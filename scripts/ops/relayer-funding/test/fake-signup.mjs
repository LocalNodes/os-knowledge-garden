// A stand-in for the sign-up app's /api/relayer-funding routes, with the same
// rules as localnodes-onboarding server/utils/relayer-funding.ts. contract.json
// holds request sequences and their answers; this file's test runs them here,
// and the onboarding repo runs the same file (tests/unit/fixtures/
// relayer-funding-contract.json, byte-identical) against the real handler.
import { fundingToken } from '../fund.mjs'

/** The app's fixed amount, kept here on its own so a change in fund.mjs alone is caught. */
export const AMOUNT_WEI = 100_000_000_000_000_000n
const PID = /^[A-Za-z0-9_-]{22}$/
const ADDRESS = /^0x[0-9a-fA-F]{40}$/
const HASH = /^0x[0-9a-f]{64}$/
const RAW = /^0x[0-9a-f]{2,2048}$/
const UINT = /^(0|[1-9]\d{0,30})$/
const STOP_CODES = new Set(['nonce_taken', 'tx_reverted'])
const FUNDABLE = new Set(['ready', 'complete'])

export class FakeSignup {
  constructor({ secret, origin }) {
    this.secret = secret
    this.origin = origin
    /** provision id -> { status, relayerAddress } */
    this.signups = new Map()
    /** provision id -> record */
    this.records = new Map()
    this.requests = []
    /** Apply the next n POSTs, then fail as a lost reply (a thrown fetch). */
    this.losePosts = 0
  }

  authorized(auth, pid) {
    return typeof pid === 'string' && PID.test(pid) && auth === `Bearer ${fundingToken(this.secret, pid)}`
  }

  view(rec) {
    if (!rec) return null
    const v = { state: rec.state, to: rec.to, from: rec.from, amount_wei: rec.amountWei, nonce: rec.nonce, tx_hash: rec.txHash, raw_tx: rec.rawTx }
    if (rec.stopCode) v.stop_code = rec.stopCode
    return v
  }

  check(auth, pid) {
    if (!this.authorized(auth, pid)) return [401, { message: 'Unauthorized' }]
    const rec = this.records.get(pid)
    const s = this.signups.get(pid)
    if (!s && !rec) return [404, { message: 'Unknown provision' }]
    const relayer = s && ADDRESS.test(String(s.relayerAddress ?? '')) ? s.relayerAddress : ''
    return [200, { relayer_address: relayer, fundable: Boolean(relayer) && FUNDABLE.has(String(s?.status)), funding: this.view(rec) }]
  }

  write(auth, body) {
    const pid = body?.provision_id
    if (!this.authorized(auth, pid)) return [401, { message: 'Unauthorized' }]
    if (body.action === 'pending') {
      const ok = ADDRESS.test(String(body.from)) && UINT.test(String(body.amount_wei)) && UINT.test(String(body.nonce)) && HASH.test(String(body.tx_hash)) && RAW.test(String(body.raw_tx))
      if (!ok || typeof body.from !== 'string' || typeof body.amount_wei !== 'string' || typeof body.nonce !== 'string') return [400, { message: 'Invalid request' }]
      const s = this.signups.get(pid)
      if (!s) return [404, { message: 'Unknown provision' }]
      const to = ADDRESS.test(String(s.relayerAddress ?? '')) ? s.relayerAddress : ''
      if (!to || !FUNDABLE.has(String(s.status))) return [409, { message: 'Not fundable' }]
      if (body.amount_wei !== AMOUNT_WEI.toString()) return [409, { message: 'Wrong amount' }]
      if (this.records.has(pid)) return [409, { message: 'Record exists' }]
      this.records.set(pid, { state: 'pending', to, from: body.from, amountWei: body.amount_wei, nonce: body.nonce, txHash: body.tx_hash, rawTx: body.raw_tx })
      return [200, { ok: true }]
    }
    const finishOk = (body.action === 'sent' || (body.action === 'stopped' && STOP_CODES.has(body.code))) && HASH.test(String(body.tx_hash))
    if (!finishOk) return [400, { message: 'Invalid request' }]
    const rec = this.records.get(pid)
    if (!rec || rec.txHash !== body.tx_hash) return [409, { message: 'Refused' }]
    if (rec.state === body.action) return [200, { ok: true, repeat: true }]
    if (rec.state !== 'pending') return [409, { message: 'Refused' }]
    rec.state = body.action
    if (body.action === 'stopped') rec.stopCode = body.code
    return [200, { ok: true }]
  }

  /** The fetch half for this origin. */
  async handle(url, init) {
    const u = new URL(url)
    if (u.pathname !== '/api/relayer-funding') throw new Error('fake signup: unknown path')
    const auth = init?.headers?.authorization
    this.requests.push({ method: init?.method ?? 'GET', body: init?.body ? JSON.parse(init.body) : null })
    let status, body
    if ((init?.method ?? 'GET') === 'GET') [status, body] = this.check(auth, u.searchParams.get('provision_id'))
    else {
      [status, body] = this.write(auth, JSON.parse(init.body))
      if (this.losePosts > 0) {
        this.losePosts--
        throw new TypeError('fetch failed')
      }
    }
    return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } })
  }
}
