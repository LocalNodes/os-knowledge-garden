// A stand-in Ethereum Sepolia JSON-RPC with one practice wallet and real
// transaction checks (viem parses and recovers each raw transaction). It
// mines a sent transaction on the Nth receipt lookup, and it can lose the
// reply of a broadcast after applying it, or have another transaction take a
// nonce, as a second sender of the same wallet would.
import { keccak256, parseTransaction, recoverTransactionAddress } from 'viem'

export class FakeRpc {
  constructor({ chainId = 11155111, url }) {
    this.url = url
    this.chainId = chainId
    this.balances = new Map()
    /** address -> next mined nonce */
    this.nonces = new Map()
    /** hash -> { from, to, value, nonce, raw } waiting to be mined */
    this.mempool = new Map()
    /** hash -> receipt */
    this.mined = new Map()
    /** Every raw transaction that ever reached the chain (mined), in order. */
    this.chain = []
    this.calls = []
    this.baseFee = 1_000_000_000n
    this.tip = 1_000_000_000n
    /** Receipt lookups before a mempool transaction is mined (0: mined on the first lookup). */
    this.mineAfter = 0
    this.lookups = 0
    /** Lose the next n broadcast replies after applying them. */
    this.loseSends = 0
    /** Drop broadcasts (never reach the mempool). */
    this.dropSends = false
    this.revert = false
    /** The next n receipt lookups answer null even when mined (a lagging RPC). */
    this.hideReceipts = 0
    /** The next n 'latest' nonce counts answer one less (a lagging RPC). */
    this.staleCounts = 0
  }

  key = a => String(a).toLowerCase()
  balance(a) { return this.balances.get(this.key(a)) ?? 0n }
  nonce(a) { return this.nonces.get(this.key(a)) ?? 0n }
  pendingNonce(a) {
    let n = this.nonce(a)
    for (const t of this.mempool.values()) if (this.key(t.from) === this.key(a) && BigInt(t.nonce) >= n) n = BigInt(t.nonce) + 1n
    return n
  }

  /** Another sender of the same wallet uses `nonce` (mined at once). */
  takeNonce(from) {
    const n = this.nonce(from)
    this.nonces.set(this.key(from), n + 1n)
    for (const [h, t] of this.mempool) if (this.key(t.from) === this.key(from) && BigInt(t.nonce) <= n) this.mempool.delete(h)
  }

  mineAll() {
    for (const [h, t] of [...this.mempool]) {
      if (BigInt(t.nonce) !== this.nonce(t.from)) continue
      this.mempool.delete(h)
      this.nonces.set(this.key(t.from), this.nonce(t.from) + 1n)
      const status = this.revert ? '0x0' : '0x1'
      if (!this.revert) {
        this.balances.set(this.key(t.from), this.balance(t.from) - t.value - 21_000n * this.baseFee)
        this.balances.set(this.key(t.to), this.balance(t.to) + t.value)
      }
      this.mined.set(h, { transactionHash: h, blockNumber: '0x10', status })
      this.chain.push(t.raw)
    }
  }

  async send(raw) {
    const hash = keccak256(raw)
    if (this.mempool.has(hash)) return { error: { code: -32000, message: 'already known' } }
    const tx = parseTransaction(raw)
    const from = await recoverTransactionAddress({ serializedTransaction: raw })
    if (tx.chainId !== this.chainId) return { error: { code: -32000, message: 'invalid chain id' } }
    if (BigInt(tx.nonce) < this.nonce(from)) return { error: { code: -32000, message: 'nonce too low' } }
    if (!this.dropSends) this.mempool.set(hash, { from, to: tx.to, value: tx.value, nonce: tx.nonce, raw })
    return { result: hash }
  }

  async answer(method, params) {
    switch (method) {
      case 'eth_chainId': return { result: `0x${this.chainId.toString(16)}` }
      case 'eth_getBalance': return { result: `0x${this.balance(params[0]).toString(16)}` }
      case 'eth_getTransactionCount': {
        let n = params[1] === 'pending' ? this.pendingNonce(params[0]) : this.nonce(params[0])
        if (params[1] !== 'pending' && this.staleCounts > 0 && n > 0n) {
          this.staleCounts--
          n -= 1n
        }
        return { result: `0x${n.toString(16)}` }
      }
      case 'eth_getBlockByNumber': return { result: { number: '0x10', baseFeePerGas: `0x${this.baseFee.toString(16)}` } }
      case 'eth_maxPriorityFeePerGas': return { result: `0x${this.tip.toString(16)}` }
      case 'eth_sendRawTransaction': return this.send(params[0])
      case 'eth_getTransactionReceipt': {
        if (this.lookups++ >= this.mineAfter) this.mineAll()
        if (this.hideReceipts > 0) {
          this.hideReceipts--
          return { result: null }
        }
        return { result: this.mined.get(params[0]) ?? null }
      }
      default: return { error: { code: -32601, message: 'method not found' } }
    }
  }

  async handle(url, init) {
    const req = JSON.parse(init.body)
    this.calls.push(req.method)
    const out = await this.answer(req.method, req.params)
    if (req.method === 'eth_sendRawTransaction' && this.loseSends > 0) {
      this.loseSends--
      throw new TypeError('fetch failed')
    }
    return new Response(JSON.stringify({ jsonrpc: '2.0', id: req.id, ...out }), { status: 200, headers: { 'content-type': 'application/json' } })
  }
}
