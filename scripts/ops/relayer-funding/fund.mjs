// Send test ETH once to a practice site's gas-sponsor relayer, from the
// practice wallet (the key behind the workflow secret
// SPONSOR_RELAYER_PRIVATE_KEY_CI). Run by
// .github/workflows/fund-practice-relayer.yml; never by hand with a real key.
//
// The sign-up app keeps one funding record per provision
// (localnodes-onboarding server/utils/relayer-funding.ts), so a balance check
// is never the only guard against a double send:
//   1. Reconcile first. A "sent" record: no send, ever (exit 0). A "stopped"
//      record: exit 1, it waits for a person. A "pending" record: its signed
//      transaction is checked, then looked up on chain. Mined: mark it sent.
//      Its nonce used but no receipt for the whole wait: stop, record left
//      pending (a plain RPC cannot say which transaction used a nonce).
//      Otherwise the SAME signed transaction is broadcast again (same nonce,
//      so it lands at most once). A new transaction is never signed while a
//      record exists.
//   2. No record: the chain is Ethereum Sepolia; the relayer holds less than
//      the floor; the practice wallet has nothing in flight, can pay the
//      amount, the fee and a reserve ("the practice wallet is low"); the fee
//      is under a ceiling. Then sign, record pending (the app refuses a second
//      record, so of two runs only one goes on), broadcast, wait, record sent.
//
// The amount is a constant, never an input; the app accepts only that amount.
// The destination is the relayer address the app stored from the site's
// ready, never an input.
//
// Output: fixed words, transaction hashes and the practice wallet's public
// address only. Never the key, the RPC URL, the bearer, or an error's own text
// (an RPC error can echo its URL, which may hold a provider key).

import { createHmac } from 'node:crypto'
import { pathToFileURL } from 'node:url'
import { keccak256, parseTransaction, recoverTransactionAddress } from 'viem'
import { privateKeyToAccount } from 'viem/accounts'

export const CHAIN_ID = 11155111
/** 0.1 test ETH: the fixed amount, the only one the app accepts. */
export const AMOUNT_WEI = 100_000_000_000_000_000n
/** The relayer is funded only while it holds less than 0.02 test ETH. */
export const TARGET_FLOOR_WEI = 20_000_000_000_000_000n
/** The practice wallet keeps 0.05 test ETH back for other practice runs. */
export const FUNDER_RESERVE_WEI = 50_000_000_000_000_000n
/** No send while the fee asked is above 100 gwei per gas. */
export const MAX_FEE_CEILING_WEI = 100_000_000_000n
export const GAS_LIMIT = 21_000n
export const SIGNUP_ORIGIN = 'https://localnodes.xyz'
/** How long one run waits for its transaction to be mined (polls * interval). */
export const RECEIPT_POLLS = 36
export const RECEIPT_INTERVAL_MS = 5_000

const PROVISION_ID = /^[A-Za-z0-9_-]{22}$/
const ADDRESS = /^0x[0-9a-fA-F]{40}$/
const KEY = /^(0x)?[0-9a-fA-F]{64}$/

/** A fixed stop: the run ends with this code and exit 1. */
export class Stop extends Error {
  constructor(code, words) {
    super(code)
    this.code = code
    this.words = words
  }
}

const stop = (code, words) => { throw new Stop(code, words) }
const hex = n => `0x${n.toString(16)}`
const lower = a => String(a).toLowerCase()

export function fundingToken(secret, provisionId) {
  return createHmac('sha256', secret).update(`relayer-funding:${provisionId}`).digest('hex')
}

/**
 * One funding run. env: PROVISION_ID, CALLBACK_SECRET, RPC_URL, FUNDER_KEY.
 * io: fetch, log, sleep, and optionally origin (tests only point it at a fake).
 * Answers { code } on success; throws Stop on a stop.
 */
export async function run(env, io) {
  const { fetch, log, sleep } = io
  const origin = io.origin ?? SIGNUP_ORIGIN
  const pid = String(env.PROVISION_ID ?? '')
  if (!PROVISION_ID.test(pid)) stop('bad_provision_id', 'the provision id is not 22 letters, digits, - or _')
  const secret = String(env.CALLBACK_SECRET ?? '')
  if (!secret) stop('no_callback_secret', 'the callback secret is empty')
  const rpcUrl = String(env.RPC_URL ?? '')
  if (!rpcUrl.startsWith('https://') && !io.allowHttpRpc) stop('bad_rpc_url', 'the RPC URL must be https')
  const rawKey = String(env.FUNDER_KEY ?? '')
  if (!KEY.test(rawKey)) stop('bad_funder_key', 'the practice wallet key is missing or malformed')
  const account = privateKeyToAccount(rawKey.startsWith('0x') ? rawKey : `0x${rawKey}`)
  const funder = account.address
  const bearer = `Bearer ${fundingToken(secret, pid)}`

  let rpcId = 0
  async function rpc(method, params) {
    let res
    try {
      res = await fetch(rpcUrl, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', id: ++rpcId, method, params }) })
    } catch {
      stop('rpc_unreachable', `the RPC did not answer ${method}`)
    }
    let body
    try {
      body = await res.json()
    } catch {
      stop('rpc_bad_answer', `the RPC gave no JSON for ${method}`)
    }
    if (body && body.error) return { error: String(body.error.message ?? '') }
    return { result: body ? body.result : undefined }
  }
  async function rpcOk(method, params) {
    const r = await rpc(method, params)
    if (r.error !== undefined) stop('rpc_error', `the RPC refused ${method}`)
    return r.result
  }

  async function app(method, body) {
    const url = method === 'GET'
      ? `${origin}/api/relayer-funding?provision_id=${encodeURIComponent(pid)}`
      : `${origin}/api/relayer-funding`
    try {
      const res = await fetch(url, {
        method,
        headers: { authorization: bearer, ...(body ? { 'content-type': 'application/json' } : {}) },
        ...(body ? { body: JSON.stringify({ provision_id: pid, ...body }) } : {})
      })
      let json = null
      try { json = await res.json() } catch { json = null }
      return { status: res.status, json }
    } catch {
      return { status: 0, json: null }
    }
  }
  async function readRecord() {
    const r = await app('GET')
    if (r.status === 401) stop('signup_unauthorized', 'the sign-up app refused the bearer')
    if (r.status === 404) stop('signup_unknown', 'the sign-up app has no such provision')
    if (r.status !== 200 || !r.json) stop('signup_unreachable', 'the sign-up app did not answer')
    return r.json
  }
  /** POST sent or stopped; repeats are fine. Three tries, then the next run reconciles. */
  async function finish(action, txHash, code) {
    for (let i = 0; i < 3; i++) {
      const r = await app('POST', { action, tx_hash: txHash, ...(code ? { code } : {}) })
      if (r.status === 200) return
      if (r.status === 409) stop('finish_refused', `the sign-up app refused ${action}`)
      await sleep(2_000)
    }
    stop('finish_unrecorded', `the ${action} was not recorded; the next run reconciles it`)
  }

  const chain = await rpcOk('eth_chainId', [])
  if (Number(BigInt(chain)) !== CHAIN_ID) stop('wrong_chain', 'the RPC is not Ethereum Sepolia')

  const view = await readRecord()
  const rec = view.funding
  if (rec && rec.state === 'sent') {
    log('FUNDING already_sent: this site was funded before; no send')
    return { code: 'already_sent' }
  }
  if (rec && rec.state === 'stopped') stop('record_stopped', `this site's funding stopped (${String(rec.stop_code ?? '')}); a person decides`)
  if (rec && rec.state === 'pending') return reconcile(rec, view)
  if (rec) stop('record_unknown', 'the funding record has an unknown state')

  // --- No record: a first send. ---
  const to = String(view.relayer_address ?? '')
  if (!view.fundable || !ADDRESS.test(to)) stop('not_fundable', 'the site is not ready or has no relayer')
  const target = BigInt(await rpcOk('eth_getBalance', [to, 'latest']))
  if (target >= TARGET_FLOOR_WEI) {
    log('FUNDING already_funded: the relayer holds at least the floor; no send')
    return { code: 'already_funded' }
  }
  const latestNonce = BigInt(await rpcOk('eth_getTransactionCount', [funder, 'latest']))
  const pendingNonce = BigInt(await rpcOk('eth_getTransactionCount', [funder, 'pending']))
  if (pendingNonce !== latestNonce) stop('funder_busy', 'the practice wallet has a transaction in flight; run again later')
  const block = await rpcOk('eth_getBlockByNumber', ['latest', false])
  const base = BigInt(block?.baseFeePerGas ?? '0x0')
  const tip = BigInt(await rpcOk('eth_maxPriorityFeePerGas', []))
  const maxFee = base * 2n + tip
  if (maxFee > MAX_FEE_CEILING_WEI) stop('fee_too_high', 'the network fee is over the ceiling; run again later')
  const balance = BigInt(await rpcOk('eth_getBalance', [funder, 'latest']))
  if (balance < AMOUNT_WEI + GAS_LIMIT * maxFee + FUNDER_RESERVE_WEI) stop('funder_low', 'the practice wallet is low; top it up from a faucet')

  const raw = await account.signTransaction({ type: 'eip1559', chainId: CHAIN_ID, nonce: Number(latestNonce), to, value: AMOUNT_WEI, gas: GAS_LIMIT, maxFeePerGas: maxFee, maxPriorityFeePerGas: tip })
  const txHash = keccak256(raw)
  const posted = await app('POST', { action: 'pending', from: funder, amount_wei: AMOUNT_WEI.toString(), nonce: latestNonce.toString(), tx_hash: txHash, raw_tx: raw })
  if (posted.status !== 200) {
    // A lost reply may have applied: only our own hash goes on.
    const again = await readRecord()
    if (!(again.funding && again.funding.state === 'pending' && again.funding.tx_hash === txHash)) {
      if (posted.status === 409 || again.funding) stop('record_taken', 'another run recorded this site\'s send; nothing was broadcast')
      stop('record_unwritten', 'the pending record was not written; nothing was broadcast')
    }
  }
  log(`FUNDING signed ${txHash}`)
  return broadcastAndWait(raw, txHash, latestNonce)

  // --- Helpers that close over the run. ---
  async function reconcile(r) {
    const raw = String(r.raw_tx ?? '')
    const txHash = String(r.tx_hash ?? '')
    let tx, signer
    try {
      tx = parseTransaction(raw)
      signer = await recoverTransactionAddress({ serializedTransaction: raw })
    } catch {
      stop('record_mismatch', 'the pending record\'s transaction does not parse')
    }
    const ok = keccak256(raw) === txHash
      && lower(signer) === lower(funder)
      && lower(tx.to) === lower(r.to)
      && (!view.relayer_address || lower(tx.to) === lower(view.relayer_address))
      && tx.value === AMOUNT_WEI
      && tx.chainId === CHAIN_ID
      && String(tx.nonce) === String(r.nonce)
    if (!ok) stop('record_mismatch', 'the pending record does not match its signed transaction')
    log(`FUNDING reconcile ${txHash}`)
    const mined = await receipt(txHash)
    if (mined) return settle(mined, txHash)
    const latest = BigInt(await rpcOk('eth_getTransactionCount', [funder, 'latest']))
    if (latest > BigInt(tx.nonce)) {
      // The nonce is used: ours, mined, or another transaction's. An RPC can
      // count a nonce before it serves the receipt, and a plain RPC cannot say
      // which transaction used a nonce, so nothing final is recorded here: the
      // record stays pending, a later run asks for the receipt again, and
      // nothing new is ever signed for this site while it is pending.
      for (let i = 0; i < RECEIPT_POLLS; i++) {
        const late = await receipt(txHash)
        if (late) return settle(late, txHash)
        await sleep(RECEIPT_INTERVAL_MS)
      }
      stop('nonce_used_no_receipt', 'this send\'s nonce is used but its receipt is not found; the record stays pending; run again later, and if it still says this a person decides')
    }
    return broadcastAndWait(raw, txHash, BigInt(tx.nonce))
  }

  async function receipt(txHash) {
    const r = await rpcOk('eth_getTransactionReceipt', [txHash])
    return r && r.blockNumber ? r : null
  }

  async function settle(mined, txHash) {
    if (mined.status === '0x1') {
      await finish('sent', txHash)
      log(`FUNDING sent ${txHash}`)
      return { code: 'sent' }
    }
    await finish('stopped', txHash, 'tx_reverted')
    stop('tx_reverted', 'the send was mined but reverted')
  }

  async function broadcastAndWait(raw, txHash) {
    // The answer is not trusted either way: a lost or refused reply may still
    // have reached the network ("already known", "nonce too low"). The receipt
    // decides.
    await rpc('eth_sendRawTransaction', [raw]).catch(err => { if (!(err instanceof Stop)) throw err })
    for (let i = 0; i < RECEIPT_POLLS; i++) {
      const mined = await receipt(txHash)
      if (mined) return settle(mined, txHash)
      await sleep(RECEIPT_INTERVAL_MS)
    }
    stop('not_mined_yet', 'the send is recorded as pending and not mined yet; run again to reconcile it')
  }
}

/** The CLI: env in, fixed lines out, exit 0 or 1. The key is removed from env once read. */
async function main() {
  const env = { PROVISION_ID: process.env.PROVISION_ID, CALLBACK_SECRET: process.env.CALLBACK_SECRET, RPC_URL: process.env.RPC_URL, FUNDER_KEY: process.env.FUNDER_KEY }
  delete process.env.FUNDER_KEY
  delete process.env.CALLBACK_SECRET
  delete process.env.RPC_URL
  try {
    const out = await run(env, { fetch: globalThis.fetch, log: line => console.log(line), sleep: ms => new Promise(r => setTimeout(r, ms)) })
    console.log(`FUNDING_DONE ${out.code}`)
    process.exitCode = 0
  } catch (err) {
    if (err instanceof Stop) console.log(`FUNDING_STOP ${err.code}: ${err.words}`)
    else console.log('FUNDING_STOP unexpected: the run failed in an unexpected way; no detail is printed')
    process.exitCode = 1
  }
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) await main()
