#!/usr/bin/env node
/**
 * OpenRouter zero-data-retention (ZDR) and embedding-size probe (23-06, D-21).
 *
 * Finds out which OpenRouter models have a ZDR endpoint, which embedding
 * models can return the 3072-number vectors our search store expects, and
 * whether a chat call and an embeddings call both succeed with the privacy
 * provider object on:
 *
 *   provider: { zdr: true, data_collection: 'deny' }
 *
 * plus the model's endpoint pins, read from the Drupal module's settings file
 * (provider_pins), exactly as PrivateOpenRouterClient::withPrivacy() sends
 * them: provider.only when the pin's only list is not empty, provider.ignore
 * when its ignore list is not empty, nothing for a model with no pin.
 *
 * Content: one fixed public sentence and one fixed one-line prompt. Never
 * member content.
 *
 * Output: model ids, requested size, returned vector length, HTTP status and
 * which provider served the call. Never response bodies, never the key. The
 * key is read from the environment only (default OPENROUTER_TEST_KEY) and
 * every output line passes through a redactor as a second guard.
 *
 * Usage:
 *   node scripts/ops/openrouter-probe.mjs --self-test
 *   set -a; . ~/.openrouter-test.env; set +a; node scripts/ops/openrouter-probe.mjs
 *   node scripts/ops/openrouter-probe.mjs --key-env ORGANIZER_KEY   (runbook: check a BYO key, with the pins)
 *
 * Flags:
 *   --self-test               offline; asserts the request bodies and the redactor
 *   --base-url URL            default https://openrouter.ai/api/v1
 *   --key-env NAME            env variable holding the key (default OPENROUTER_TEST_KEY)
 *   --dimensions N            requested vector size (default 3072)
 *   --embedding-models a,b    override the embedding candidates
 *   --chat-models a,b         override the chat candidates
 *   --pins-file PATH          settings YAML holding provider_pins (default: the
 *                             localnodes_openrouter module's config/install file)
 *
 * Exit codes: 0 ran and at least one row answered 200, 1 no row answered 200
 * (or the self-test failed), 2 key variable not set or the pins file unreadable
 * or malformed, 3 the key was rejected
 * or OpenRouter was unreachable.
 *
 * Node built-ins only.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

export const PROBE_SENTENCE = 'LocalNodes probe sentence.'
export const PROBE_PROMPT = 'Reply with the single word: ok.'
export const PROVIDER = Object.freeze({ zdr: true, data_collection: 'deny' })
export const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1'
export const DEFAULT_KEY_ENV = 'OPENROUTER_TEST_KEY'
export const DEFAULT_DIMENSIONS = 3072
export const DEFAULT_PINS_FILE = join(
  dirname(fileURLToPath(import.meta.url)), '..', '..', 'html', 'modules', 'custom', 'localnodes_platform',
  'modules', 'localnodes_openrouter', 'config', 'install', 'localnodes_openrouter.settings.yml',
)

// Native sizes from the drupal/ai_provider_openrouter table (research Q10,
// OpenRouterProvider.php:858-897). The API itself reports no dimensions.
export const NATIVE_DIMENSIONS = Object.freeze({
  'openai/text-embedding-ada-002': 1536,
  'openai/text-embedding-3-small': 1536,
  'openai/text-embedding-3-large': 3072,
  'google/gemini-embedding-001': 768,
  'mistralai/mistral-embed-2312': 1024,
  'mistralai/codestral-embed-2505': 1024,
  'qwen/qwen3-embedding-8b': 4096,
  'qwen/qwen3-embedding-4b': 2560,
  'qwen/qwen3-embedding-0.6b': 1024,
})

// google/gemini-embedding-2 is the announced replacement for
// gemini-embedding-001 (22-AI-SPEC), native size not published: ask for 3072.
const EXTRA_EMBEDDING_MODELS = ['google/gemini-embedding-2']

// Chat candidates: open-weight or EU-hosted models that list a ZDR endpoint,
// support tools (the group assistant runs an agent loop) and are cheap.
const DEFAULT_CHAT_MODELS = [
  'openai/gpt-oss-120b',
  'qwen/qwen3.6-35b-a3b',
  'deepseek/deepseek-v4-flash',
  'mistralai/mistral-small-2603',
  'meta-llama/llama-3.3-70b-instruct',
]

/**
 * Reads provider_pins from the settings YAML text.
 *
 * The repo has no YAML parser for node scripts, so this reads only the one
 * shape the file uses: a top-level `provider_pins:` list of
 * `- model: <id>` entries, each with `only:` and `ignore:` given as a block
 * list of tags or as an inline `[a, b]` / `[]`. Comments and blank lines are
 * skipped; the list ends at the next top-level key. Anything else throws, so
 * a changed file shape can never turn into "no pins" without a word.
 *
 * @returns {{model: string, only: string[], ignore: string[]}[]}
 */
export function parsePins(text) {
  const lines = String(text).split(/\r?\n/)
  const start = lines.findIndex((l) => /^provider_pins:/.test(l))
  if (start === -1) throw new Error('no provider_pins key')
  const unquote = (v) => v.trim().replace(/^(['"])(.*)\1$/, '$2')
  const inline = (v, where) => {
    const m = /^\[(.*)\]$/.exec(v.trim())
    if (!m) throw new Error(`${where}: expected a list, got "${v.trim()}"`)
    return m[1].split(',').map(unquote).filter(Boolean)
  }
  const rest = /^provider_pins:\s*(.*?)\s*(#.*)?$/.exec(lines[start])[1]
  if (rest !== '') return inline(rest, 'provider_pins')

  const entries = []
  let entry = null
  let listKey = null
  for (let i = start + 1; i < lines.length; i++) {
    const line = lines[i]
    const where = `provider_pins line ${i + 1}`
    if (/^\s*(#.*)?$/.test(line)) continue
    if (/^\S/.test(line)) break // next top-level key
    let m
    if ((m = /^ {2}- model:\s*(\S.*?)\s*$/.exec(line))) {
      entry = { model: unquote(m[1]), only: [], ignore: [] }
      entries.push(entry)
      listKey = null
    } else if ((m = /^ {4}(only|ignore):\s*(\S.*?)?\s*$/.exec(line)) && entry) {
      listKey = m[1]
      if (m[2] !== undefined) {
        entry[listKey] = inline(m[2], `${where} (${listKey})`)
        listKey = null
      }
    } else if ((m = /^ {6}- (\S.*?)\s*$/.exec(line)) && entry && listKey) {
      entry[listKey].push(unquote(m[1]))
    } else {
      throw new Error(`${where}: not understood: "${line.trim()}"`)
    }
  }
  return entries
}

export function loadPins(path) {
  return parsePins(readFileSync(path, 'utf8'))
}

/**
 * The provider object for one model: what PrivateOpenRouterClient::withPrivacy()
 * builds. The first entry for the model decides (pinFor() returns on the first
 * match); an entry with both lists empty counts as no pin. PROVIDER is copied,
 * never changed, and the lists are copied so a body never shares them with the pins.
 */
export function providerFor(model, pins = []) {
  const provider = { ...PROVIDER }
  const entry = pins.find((p) => p.model === model)
  if (entry) {
    if (entry.only.length) provider.only = [...entry.only]
    if (entry.ignore.length) provider.ignore = [...entry.ignore]
  }
  return provider
}

export function buildEmbeddingBody(model, dimensions, pins = []) {
  const body = {
    model,
    input: PROBE_SENTENCE,
    encoding_format: 'float',
    provider: providerFor(model, pins),
  }
  if (dimensions) body.dimensions = dimensions
  return body
}

export function buildChatBody(model, pins = []) {
  return {
    model,
    messages: [{ role: 'user', content: PROBE_PROMPT }],
    max_tokens: 64,
    provider: providerFor(model, pins),
  }
}

export function redactor(secret) {
  return (text) => (secret && secret.length >= 8 ? String(text).split(secret).join('[redacted]') : String(text))
}

function parseArgs(argv) {
  const opts = {
    selfTest: false,
    baseUrl: DEFAULT_BASE_URL,
    keyEnv: DEFAULT_KEY_ENV,
    dimensions: DEFAULT_DIMENSIONS,
    embeddingModels: null,
    chatModels: null,
    pinsFile: DEFAULT_PINS_FILE,
  }
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i]
    const next = () => {
      const v = argv[++i]
      if (v === undefined) throw new Error(`${a} needs a value`)
      return v
    }
    if (a === '--self-test') opts.selfTest = true
    else if (a === '--base-url') opts.baseUrl = next().replace(/\/+$/, '')
    else if (a === '--key-env') opts.keyEnv = next()
    else if (a === '--dimensions') opts.dimensions = Number.parseInt(next(), 10)
    else if (a === '--embedding-models') opts.embeddingModels = next().split(',').map((s) => s.trim()).filter(Boolean)
    else if (a === '--chat-models') opts.chatModels = next().split(',').map((s) => s.trim()).filter(Boolean)
    else if (a === '--pins-file') opts.pinsFile = next()
    else throw new Error(`unknown flag ${a}`)
  }
  if (!Number.isInteger(opts.dimensions) || opts.dimensions < 1) throw new Error('--dimensions must be a positive integer')
  return opts
}

function selfTest() {
  const failures = []
  const check = (ok, what) => { if (!ok) failures.push(what) }
  const hasPrivacy = (b) => b.provider && b.provider.zdr === true && b.provider.data_collection === 'deny'

  for (const dims of [undefined, 3072, 4096]) {
    const b = buildEmbeddingBody('x/y', dims)
    check(hasPrivacy(b), `embeddings body (dimensions ${dims}) lacks zdr:true + data_collection:deny`)
    check(b.input === PROBE_SENTENCE, 'embeddings body input is not the fixed sentence')
    check(dims === undefined ? !('dimensions' in b) : b.dimensions === dims, 'embeddings body dimensions wrong')
  }
  const c = buildChatBody('x/y')
  check(hasPrivacy(c), 'chat body lacks zdr:true + data_collection:deny')
  check(c.messages.length === 1 && c.messages[0].content === PROBE_PROMPT, 'chat body is not the fixed prompt')

  // The shared PROVIDER constant must not leak mutations between bodies.
  buildChatBody('x/y').provider.zdr = false
  check(hasPrivacy(buildChatBody('x/y')) && hasPrivacy(buildEmbeddingBody('x/y', 1)), 'a body mutation changed later bodies')
  check(Object.isFrozen(PROVIDER), 'PROVIDER constant is not frozen')

  // Pins: the real settings file is read, each pinned model's body carries
  // exactly its list, an unpinned model and an empty entry carry none.
  try {
    const real = loadPins(DEFAULT_PINS_FILE)
    check(real.length > 0, 'the settings file lists no provider_pins')
    for (const pin of real) {
      const p = buildChatBody(pin.model, real).provider
      check(hasPrivacy({ provider: p }), `pinned body for ${pin.model} lost the privacy fields`)
      check(JSON.stringify(p.only ?? []) === JSON.stringify(pin.only), `pinned body for ${pin.model} lacks its only list`)
      check(JSON.stringify(p.ignore ?? []) === JSON.stringify(pin.ignore), `pinned body for ${pin.model} has the wrong ignore list`)
      check(JSON.stringify(buildEmbeddingBody(pin.model, 1, real).provider) === JSON.stringify(p), `embeddings body for ${pin.model} differs from its chat body`)
    }
    const unpinned = buildChatBody('x/unpinned', real).provider
    check(!('only' in unpinned) && !('ignore' in unpinned), 'an unpinned model gained pins')
  } catch (e) {
    check(false, `pins file: ${e.message}`)
  }
  const sample = parsePins('provider_pins:\n  - model: a/b\n    only: [t1]\n    ignore:\n      - i1\n  - model: c/d\n    only: []\n    ignore: []\nnext_key: 1\n')
  const sa = buildChatBody('a/b', sample).provider
  check(JSON.stringify(sa.only) === '["t1"]' && JSON.stringify(sa.ignore) === '["i1"]', 'sample pin: only and ignore not both sent')
  check(!('only' in buildChatBody('c/d', sample).provider) && !('ignore' in buildChatBody('c/d', sample).provider), 'sample pin: an empty entry sent a pin')
  check(Object.isFrozen(PROVIDER) && !('only' in PROVIDER) && !('ignore' in PROVIDER), 'PROVIDER gained pins')

  // T-23-20: the redactor removes a key from any text.
  const fake = 'sk-or-v1-NOTAREALKEY0000'
  check(!redactor(fake)(`Authorization: Bearer ${fake}`).includes(fake), 'redactor leaves the key in the text')

  if (failures.length) {
    for (const f of failures) console.error(`self-test FAIL: ${f}`)
    return 1
  }
  console.log('self-test: ok (every request body carries provider.zdr true and data_collection deny; pins match the settings file; redactor removes keys)')
  return 0
}

async function main(argv) {
  let opts
  try {
    opts = parseArgs(argv)
  } catch (e) {
    console.error(`openrouter-probe: ${e.message}`)
    return 2
  }
  if (opts.selfTest) return selfTest()

  let pins
  try {
    pins = loadPins(opts.pinsFile)
  } catch (e) {
    console.error(`openrouter-probe: cannot read the pins file ${opts.pinsFile}: ${e.message}`)
    return 2
  }

  const key = process.env[opts.keyEnv]
  if (!key) {
    console.error(`${opts.keyEnv} is not set`)
    return 2
  }
  const redact = redactor(key)
  const say = (line = '') => console.log(redact(line))

  const call = async (method, path, body) => {
    const res = await fetch(opts.baseUrl + path, {
      method,
      headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(60000),
    })
    let json = null
    try { json = await res.json() } catch { /* not JSON: keep null */ }
    return { status: res.status, json }
  }

  // 1. Key check and spend. Numbers only: the key label can hold a key prefix.
  let keyInfo
  let embList
  let zdrList
  try {
    keyInfo = await call('GET', '/key')
    if (keyInfo.status === 401 || keyInfo.status === 403) {
      console.error(redact(`openrouter-probe: the key was rejected (HTTP ${keyInfo.status})`))
      return 3
    }
    embList = await call('GET', '/embeddings/models')
    zdrList = await call('GET', '/endpoints/zdr')
  } catch (e) {
    console.error(redact(`openrouter-probe: OpenRouter unreachable: ${e.name}`))
    return 3
  }
  if (embList.status !== 200 || zdrList.status !== 200 || !Array.isArray(embList.json?.data) || !Array.isArray(zdrList.json?.data)) {
    console.error(redact(`openrouter-probe: model lists failed (embeddings/models ${embList.status}, endpoints/zdr ${zdrList.status})`))
    return 3
  }

  const usage = keyInfo.json?.data?.usage
  const limit = keyInfo.json?.data?.limit
  say(`provider pins: ${pins.length} models from ${opts.pinsFile}`)
  say(`key check: HTTP ${keyInfo.status}` + (typeof usage === 'number' ? `, usage $${usage} of $${limit ?? 'no limit'}` : ''))

  // 2. ZDR map: model id -> endpoints, with the safety-retention flag.
  const zdr = new Map()
  for (const ep of zdrList.json.data) {
    if (!ep?.model_id) continue
    if (!zdr.has(ep.model_id)) zdr.set(ep.model_id, [])
    zdr.get(ep.model_id).push({ provider: ep.provider_name, safetyRetention: ep.safety_retention === true })
  }
  const zdrSummary = (id) => {
    const eps = zdr.get(id) ?? []
    const strict = eps.filter((e) => !e.safetyRetention).length
    return eps.length ? `${eps.length} endpoints (${strict} ZDR-only, ${eps.length - strict} safety-retention)` : 'none'
  }

  const embIds = embList.json.data.map((m) => m.id)
  const embWithZdr = embIds.filter((id) => zdr.has(id))
  say('')
  say(`embedding models listed: ${embIds.length}; with a ZDR endpoint: ${embWithZdr.length}`)
  for (const id of embWithZdr) say(`  ${id}  native ${NATIVE_DIMENSIONS[id] ?? '?'}  ${zdrSummary(id)}`)
  say(`models with a ZDR endpoint overall: ${zdr.size}`)

  // 3. Candidates.
  const embeddingModels = opts.embeddingModels ?? [
    ...new Set([
      ...Object.keys(NATIVE_DIMENSIONS),
      ...EXTRA_EMBEDDING_MODELS,
      ...embList.json.data
        .filter((m) => /\b(3072|4096|8192)\b/.test(m.description ?? ''))
        .map((m) => m.id),
    ]),
  ]
  const chatModels = opts.chatModels ?? DEFAULT_CHAT_MODELS

  // 4. Calls. One row each.
  const rows = []
  const servedNote = (id, provider) => {
    const eps = zdr.get(id) ?? []
    const match = eps.find((e) => e.provider === provider)
    if (!provider) return 'served-by unknown (zdr requested)'
    if (!match) return `${provider} (not on the ZDR list)`
    return match.safetyRetention ? `${provider} safety-retention` : `${provider} zdr`
  }
  const classify = (status, json) => {
    if (status === 200) return null
    const msg = String(json?.error?.message ?? '')
    return /data policy|no endpoints|zero data|zdr/i.test(msg) ? 'no-zdr-endpoint' : 'error'
  }

  for (const model of embeddingModels) {
    if (!zdr.has(model)) {
      rows.push({ kind: 'embed', model, asked: '-', got: '-', status: '-', note: 'skipped (no ZDR endpoint listed)' })
      continue
    }
    const sizes = [opts.dimensions]
    const native = NATIVE_DIMENSIONS[model]
    if (native && native !== opts.dimensions) sizes.push(native)
    for (const size of sizes) {
      try {
        const r = await call('POST', '/embeddings', buildEmbeddingBody(model, size, pins))
        const vec = r.json?.data?.[0]?.embedding
        const bad = classify(r.status, r.json)
        rows.push({
          kind: 'embed', model, asked: size,
          got: Array.isArray(vec) ? vec.length : '-',
          status: r.status,
          note: bad ?? servedNote(model, r.json?.provider),
        })
      } catch (e) {
        rows.push({ kind: 'embed', model, asked: size, got: '-', status: '-', note: `network ${e.name}` })
      }
    }
  }
  for (const model of chatModels) {
    if (!zdr.has(model)) {
      rows.push({ kind: 'chat', model, asked: '-', got: '-', status: '-', note: 'skipped (no ZDR endpoint listed)' })
      continue
    }
    try {
      const r = await call('POST', '/chat/completions', buildChatBody(model, pins))
      const bad = classify(r.status, r.json)
      rows.push({ kind: 'chat', model, asked: '-', got: '-', status: r.status, note: bad ?? servedNote(model, r.json?.provider) })
    } catch (e) {
      rows.push({ kind: 'chat', model, asked: '-', got: '-', status: '-', note: `network ${e.name}` })
    }
  }

  // 5. Table.
  const w = (s, n) => String(s).padEnd(n)
  const idWidth = Math.max(8, ...rows.map((r) => r.model.length)) + 2
  say('')
  say(`${w('kind', 7)}${w('model', idWidth)}${w('asked', 7)}${w('got', 7)}${w('http', 6)}served by / result`)
  for (const r of rows) say(`${w(r.kind, 7)}${w(r.model, idWidth)}${w(r.asked, 7)}${w(r.got, 7)}${w(r.status, 6)}${r.note}`)

  // 6. Spend after the calls.
  try {
    const after = await call('GET', '/key')
    const u = after.json?.data?.usage
    if (typeof u === 'number') say(`\nafter the probe: usage $${u} of $${after.json?.data?.limit ?? 'no limit'}`)
  } catch { /* spend line is a courtesy */ }

  return rows.some((r) => r.status === 200) ? 0 : 1
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  main(process.argv.slice(2)).then((code) => { process.exitCode = code })
}
