// fund.mjs against a fake Sepolia RPC and a fake sign-up app. Every request
// goes to one of the two fakes; global fetch throws during these tests. The
// key is a sentinel test key; no output of any run, passing or stopped, may
// contain it, the bearer, the secret or the RPC URL.
import { test, describe, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { privateKeyToAccount } from 'viem/accounts'
import { keccak256, parseTransaction } from 'viem'
import { run, Stop, fundingToken, AMOUNT_WEI, TARGET_FLOOR_WEI, FUNDER_RESERVE_WEI, MAX_FEE_CEILING_WEI, RECEIPT_POLLS } from '../fund.mjs'
import { FakeRpc } from './fake-rpc.mjs'
import { FakeSignup } from './fake-signup.mjs'

const KEY = '0x5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e'
const FUNDER = privateKeyToAccount(KEY).address
const SECRET = 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface'
const PID = 'FuNd1nG000000000000000'
const RELAYER = '0x' + '1a'.repeat(20)
const ORIGIN = 'https://signup.fake.test'
const RPC_URL = 'https://rpc.fake.test/key-in-path-abc123'
const ETH = 1_000_000_000_000_000_000n

let rpc, signup, out, realFetch

function io(extra = {}) {
  return {
    origin: ORIGIN,
    log: line => out.push(line),
    sleep: async () => {},
    fetch: async (url, init) => {
      if (url === RPC_URL) return rpc.handle(url, init)
      if (String(url).startsWith(`${ORIGIN}/`)) return signup.handle(url, init)
      throw new Error(`a request escaped the fakes: ${url}`)
    },
    ...extra
  }
}
const env = (extra = {}) => ({ PROVISION_ID: PID, CALLBACK_SECRET: SECRET, RPC_URL, FUNDER_KEY: KEY, ...extra })

async function attempt(e = env(), i = io()) {
  try {
    const r = await run(e, i)
    out.push(`FUNDING_DONE ${r.code}`)
    return r.code
  } catch (err) {
    if (!(err instanceof Stop)) throw err
    out.push(`FUNDING_STOP ${err.code}: ${err.words}`)
    return err.code
  }
}

beforeEach(() => {
  out = []
  rpc = new FakeRpc({ url: RPC_URL })
  rpc.balances.set(FUNDER.toLowerCase(), ETH)
  signup = new FakeSignup({ secret: SECRET, origin: ORIGIN })
  signup.signups.set(PID, { status: 'ready', relayerAddress: RELAYER })
  realFetch = globalThis.fetch
  globalThis.fetch = () => { throw new Error('global fetch used') }
})

afterEach(() => {
  globalThis.fetch = realFetch
  // Never a secret in any output line.
  const all = out.join('\n')
  for (const s of [KEY, KEY.slice(2), SECRET, fundingToken(SECRET, PID), RPC_URL, 'key-in-path-abc123']) {
    assert.ok(!all.includes(s), 'a secret reached the output')
  }
})

const sends = () => rpc.calls.filter(m => m === 'eth_sendRawTransaction').length

describe('a first send', () => {
  test('signs, records pending, broadcasts, waits, records sent: one transaction', async () => {
    assert.equal(await attempt(), 'sent')
    assert.equal(rpc.chain.length, 1)
    const tx = parseTransaction(rpc.chain[0])
    assert.equal(tx.to.toLowerCase(), RELAYER.toLowerCase())
    assert.equal(tx.value, AMOUNT_WEI)
    assert.equal(tx.chainId, 11155111)
    assert.equal(rpc.balance(RELAYER), AMOUNT_WEI)
    const rec = signup.records.get(PID)
    assert.equal(rec.state, 'sent')
    assert.equal(rec.txHash, keccak256(rpc.chain[0]))
    // The pending record was written before the broadcast.
    const order = signup.requests.map(r => r.body?.action ?? 'GET')
    assert.deepEqual(order, ['GET', 'pending', 'sent'])
    const firstSendIndex = rpc.calls.indexOf('eth_sendRawTransaction')
    assert.ok(firstSendIndex > 0)
  })

  test('the pending record is written before the broadcast', async () => {
    let sentWhilePending = false
    const inner = io()
    const i = io({
      fetch: async (url, init) => {
        if (url === RPC_URL && JSON.parse(init.body).method === 'eth_sendRawTransaction') sentWhilePending = signup.records.get(PID)?.state === 'pending'
        return inner.fetch(url, init)
      }
    })
    assert.equal(await attempt(env(), i), 'sent')
    assert.ok(sentWhilePending)
  })

  test('a run again after sent: no send', async () => {
    await attempt()
    out = []
    const before = rpc.calls.length
    assert.equal(await attempt(), 'already_sent')
    assert.equal(rpc.chain.length, 1)
    assert.equal(sends(), 1)
    assert.ok(rpc.calls.length - before <= 1)
  })
})

describe('the guards before any send', () => {
  test('wrong chain: no send, no record', async () => {
    rpc.chainId = 84532
    assert.equal(await attempt(), 'wrong_chain')
    assert.equal(sends(), 0)
    assert.equal(signup.records.size, 0)
  })

  test('relayer already at the floor: no send, no record', async () => {
    rpc.balances.set(RELAYER.toLowerCase(), TARGET_FLOOR_WEI)
    assert.equal(await attempt(), 'already_funded')
    assert.equal(sends(), 0)
    assert.equal(signup.records.size, 0)
  })

  test('the practice wallet is low: a plain stop, no send', async () => {
    rpc.balances.set(FUNDER.toLowerCase(), AMOUNT_WEI + FUNDER_RESERVE_WEI)
    assert.equal(await attempt(), 'funder_low')
    assert.ok(out.some(l => l.includes('the practice wallet is low')))
    assert.equal(sends(), 0)
    assert.equal(signup.records.size, 0)
  })

  test('fee over the ceiling: no send', async () => {
    rpc.baseFee = MAX_FEE_CEILING_WEI
    assert.equal(await attempt(), 'fee_too_high')
    assert.equal(sends(), 0)
  })

  test('the practice wallet has a transaction in flight: no send', async () => {
    rpc.mempool.set('0x' + '0'.repeat(64), { from: FUNDER, to: RELAYER, value: 1n, nonce: 0, raw: '0x' })
    assert.equal(await attempt(), 'funder_busy')
    assert.equal(sends(), 0)
  })

  test('not fundable (still building, or no relayer): no send', async () => {
    signup.signups.set(PID, { status: 'building', relayerAddress: RELAYER })
    assert.equal(await attempt(), 'not_fundable')
    signup.signups.set(PID, { status: 'ready', relayerAddress: '' })
    assert.equal(await attempt(), 'not_fundable')
    assert.equal(sends(), 0)
  })

  test('a bad provision id, an empty secret, an http RPC, or a malformed key: a fixed stop, nothing requested', async () => {
    assert.equal(await attempt(env({ PROVISION_ID: 'x' })), 'bad_provision_id')
    assert.equal(await attempt(env({ CALLBACK_SECRET: '' })), 'no_callback_secret')
    assert.equal(await attempt(env({ RPC_URL: 'http://rpc.fake.test' })), 'bad_rpc_url')
    assert.equal(await attempt(env({ FUNDER_KEY: KEY.slice(0, 40) })), 'bad_funder_key')
    assert.equal(await attempt(env({ FUNDER_KEY: '' })), 'bad_funder_key')
    assert.equal(rpc.calls.length + signup.requests.length, 0)
  })

  test('the sign-up app refuses the bearer: a fixed stop', async () => {
    assert.equal(await attempt(env({ CALLBACK_SECRET: 'another-secret' })), 'signup_unauthorized')
    assert.equal(sends(), 0)
  })

  test('the amount is a constant: no env value changes it', async () => {
    assert.equal(await attempt(env({ AMOUNT: '5000000000000000000', AMOUNT_WEI: '1' })), 'sent')
    assert.equal(parseTransaction(rpc.chain[0]).value, AMOUNT_WEI)
  })
})

describe('double sends', () => {
  test('two runs at once that sign the same transaction: one transaction on chain', async () => {
    // Same nonce, fee, destination and amount give the same signed bytes (deterministic
    // signatures), so the second run finds its own hash in the record and only reconciles it.
    const [a, b] = await Promise.all([attempt(), attempt()])
    assert.deepEqual([a, b], ['sent', 'sent'])
    assert.equal(rpc.chain.length, 1)
    assert.equal(signup.records.size, 1)
  })

  test('two runs at once that sign different transactions: exactly one send', async () => {
    let tips = 0
    const inner = io()
    const i = io({
      fetch: async (url, init) => {
        if (url === RPC_URL && JSON.parse(init.body).method === 'eth_maxPriorityFeePerGas') rpc.tip = 1_000_000_000n + BigInt(tips++)
        return inner.fetch(url, init)
      }
    })
    const [a, b] = await Promise.all([attempt(env(), i), attempt(env(), i)])
    assert.deepEqual([a, b].sort(), ['record_taken', 'sent'])
    assert.equal(rpc.chain.length, 1)
    assert.equal(sends(), 1)
  })

  test('a lost broadcast reply, then a retry: the same hash, one transaction on chain', async () => {
    rpc.loseSends = 1
    rpc.mineAfter = RECEIPT_POLLS + 5
    assert.equal(await attempt(), 'not_mined_yet')
    const rec = signup.records.get(PID)
    assert.equal(rec.state, 'pending')
    rpc.mineAfter = 0
    rpc.lookups = 0
    assert.equal(await attempt(), 'sent')
    assert.equal(rpc.chain.length, 1)
    assert.equal(keccak256(rpc.chain[0]), rec.txHash)
  })

  test('a broadcast that never reached the network: the retry rebroadcasts the same signed transaction', async () => {
    rpc.dropSends = true
    assert.equal(await attempt(), 'not_mined_yet')
    const rec = signup.records.get(PID)
    rpc.dropSends = false
    assert.equal(await attempt(), 'sent')
    assert.equal(rpc.chain.length, 1)
    assert.equal(rpc.chain[0], rec.rawTx)
  })

  test('rebroadcast answered "already known": the receipt decides', async () => {
    rpc.mineAfter = RECEIPT_POLLS + 5
    assert.equal(await attempt(), 'not_mined_yet')
    rpc.mineAfter = 0
    rpc.lookups = 0
    // The first reconcile lookup mines it; make the lookup before the rebroadcast miss instead.
    rpc.mineAfter = 1
    assert.equal(await attempt(), 'sent')
    assert.equal(rpc.chain.length, 1)
  })

  test('mined after the first run gave up: the retry marks it sent, not re-signed', async () => {
    rpc.loseSends = 1
    rpc.mineAfter = RECEIPT_POLLS + 5
    assert.equal(await attempt(), 'not_mined_yet')
    rpc.mineAll()
    assert.equal(await attempt(), 'sent')
    assert.equal(rpc.chain.length, 1)
    assert.equal(sends(), 1)
  })

  test('rebroadcast answered "nonce too low" (mined, the RPC lagging on both reads): the receipt decides, sent', async () => {
    rpc.mineAfter = RECEIPT_POLLS + 5
    assert.equal(await attempt(), 'not_mined_yet')
    rpc.mineAll()
    rpc.hideReceipts = 1
    rpc.staleCounts = 1
    const before = sends()
    assert.equal(await attempt(), 'sent')
    assert.equal(sends(), before + 1)
    assert.equal(rpc.chain.length, 1)
  })

  test('the nonce counted before the receipt shows (a lagging RPC): sent, never a wrong nonce_taken', async () => {
    rpc.mineAfter = RECEIPT_POLLS + 5
    assert.equal(await attempt(), 'not_mined_yet')
    rpc.mineAll()
    rpc.hideReceipts = 5
    assert.equal(await attempt(), 'sent')
    assert.equal(signup.records.get(PID).state, 'sent')
  })

  test('a lost reply on the pending write that applied: the run goes on with its own hash', async () => {
    signup.losePosts = 1
    assert.equal(await attempt(), 'sent')
    assert.equal(rpc.chain.length, 1)
  })

  test('a lost reply on the sent write: the next run marks it sent, no second send', async () => {
    const inner = io()
    let posts = 0
    const i = io({
      fetch: async (url, init) => {
        if (String(url).startsWith(ORIGIN) && init?.method === 'POST' && ++posts >= 2) {
          await inner.fetch(url, init)
          throw new TypeError('fetch failed')
        }
        return inner.fetch(url, init)
      }
    })
    // The sent write applied each time; its replies were lost, so the run stops unrecorded,
    // although the record says sent. The next run sees sent.
    assert.equal(await attempt(env(), i), 'finish_unrecorded')
    assert.equal(signup.records.get(PID).state, 'sent')
    assert.equal(await attempt(), 'already_sent')
    assert.equal(rpc.chain.length, 1)
  })

  test('the nonce taken by another sender of the same wallet: the record stays pending, nothing more sent, nothing final recorded', async () => {
    rpc.dropSends = true
    assert.equal(await attempt(), 'not_mined_yet')
    rpc.dropSends = false
    rpc.takeNonce(FUNDER)
    assert.equal(await attempt(), 'nonce_used_no_receipt')
    assert.equal(signup.records.get(PID).state, 'pending')
    assert.equal(rpc.chain.length, 0)
    // A later run asks again and still sends nothing.
    assert.equal(await attempt(), 'nonce_used_no_receipt')
    assert.equal(signup.records.get(PID).state, 'pending')
    assert.equal(rpc.chain.length, 0)
  })

  test('a receipt that shows only after the whole wait: pending, then a later run marks it sent', async () => {
    rpc.mineAfter = RECEIPT_POLLS + 5
    assert.equal(await attempt(), 'not_mined_yet')
    rpc.mineAll()
    rpc.hideReceipts = RECEIPT_POLLS + 5
    assert.equal(await attempt(), 'nonce_used_no_receipt')
    assert.equal(signup.records.get(PID).state, 'pending')
    rpc.hideReceipts = 0
    assert.equal(await attempt(), 'sent')
    assert.equal(signup.records.get(PID).state, 'sent')
    assert.equal(rpc.chain.length, 1)
  })

  test('a pending record whose transaction is not ours (wrong signer, amount, hash, destination or nonce): no broadcast', async () => {
    rpc.dropSends = true
    await attempt()
    const rec = signup.records.get(PID)
    const other = privateKeyToAccount('0x' + '7'.repeat(64))
    const { r: _r, s: _s, v: _v, yParity: _y, ...tx } = parseTransaction(rec.rawTx)
    const forged = await other.signTransaction({ ...tx, type: 'eip1559' })
    // Our own key, our own hash, but more than the fixed amount.
    const bigger = await privateKeyToAccount(KEY).signTransaction({ ...tx, type: 'eip1559', value: AMOUNT_WEI * 2n })
    const before = sends()
    for (const change of [
      { rawTx: forged, txHash: keccak256(forged) },
      { rawTx: bigger, txHash: keccak256(bigger) },
      { txHash: '0x' + 'f'.repeat(64) },
      { to: '0x' + '9'.repeat(40) },
      { nonce: '99' }
    ]) {
      signup.records.set(PID, { ...rec, ...change })
      assert.equal(await attempt(), 'record_mismatch')
    }
    assert.equal(sends(), before)
  })

  test('a mined but reverted send: stopped tx_reverted', async () => {
    rpc.revert = true
    assert.equal(await attempt(), 'tx_reverted')
    assert.equal(signup.records.get(PID).stopCode, 'tx_reverted')
    // A stopped record waits for a person: no run sends.
    const chain = rpc.chain.length
    assert.equal(await attempt(), 'record_stopped')
    assert.equal(rpc.chain.length, chain)
  })
})

describe('the fake sign-up app keeps the real handler\'s rules', () => {
  const contract = JSON.parse(readFileSync(new URL('./contract.json', import.meta.url), 'utf8'))
  for (const c of contract.cases) {
    test(c.name, async () => {
      const s = new FakeSignup({ secret: contract.secret, origin: ORIGIN })
      if (c.signup) s.signups.set(contract.provision_id, c.signup)
      for (const step of c.steps) {
        const pid = step.bearer === 'other' ? contract.other_provision_id : contract.provision_id
        const auth = `Bearer ${fundingToken(contract.secret, pid)}`
        const [status, body] = step.method === 'GET' ? s.check(auth, contract.provision_id) : s.write(auth, step.body)
        assert.equal(status, step.expect.status, `${c.name}: ${JSON.stringify(step)}`)
        if (step.expect.body) assert.deepEqual(body, step.expect.body)
      }
    })
  }
})
