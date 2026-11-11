// Offline tests for scripts/ops/openrouter-probe.mjs (23-06, D-21).
//
// No real key and no network: the live path runs against a local stub server
// through --base-url. Run: node --test scripts/ops/tests/openrouter-probe.test.mjs
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { execFile } from 'node:child_process'
import { createServer } from 'node:http'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { readFileSync } from 'node:fs'
import * as probeLib from '../openrouter-probe.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const probe = join(here, '..', 'openrouter-probe.mjs')

// A fake key that looks like the real shape, so a leak would match "sk-or".
const FAKE_KEY = 'sk-or-v1-FAKEKEYFORTESTSONLY0123456789'
const FAKE_LABEL = 'sk-or-v1-fak...6789'

function run(args, env) {
  return new Promise((resolve) => {
    // A clean env so the real OPENROUTER_TEST_KEY of whoever runs the tests
    // can never reach the child.
    const childEnv = { PATH: process.env.PATH, ...env }
    execFile(process.execPath, [probe, ...args], { env: childEnv, timeout: 30000 }, (error, stdout, stderr) => {
      resolve({ code: error ? (error.code ?? 1) : 0, stdout, stderr })
    })
  })
}

function startStub() {
  const seen = []
  const server = createServer((req, res) => {
    let raw = ''
    req.on('data', (c) => { raw += c })
    req.on('end', () => {
      const body = raw ? JSON.parse(raw) : null
      seen.push({ method: req.method, url: req.url, auth: req.headers.authorization, body })
      const json = (status, obj) => {
        res.writeHead(status, { 'content-type': 'application/json' })
        res.end(JSON.stringify(obj))
      }
      if (req.method === 'GET' && req.url === '/embeddings/models') {
        return json(200, { data: [{ id: 'openai/text-embedding-3-large' }, { id: 'qwen/qwen3-embedding-8b' }] })
      }
      if (req.method === 'GET' && req.url === '/endpoints/zdr') {
        return json(200, {
          data: [
            { model_id: 'openai/text-embedding-3-large', provider_name: 'Azure', safety_retention: false },
            { model_id: 'qwen/qwen3-embedding-8b', provider_name: 'DeepInfra', safety_retention: false },
            { model_id: 'vendor/chat-ok', provider_name: 'Parasail', safety_retention: false },
            { model_id: 'vendor/chat-sr', provider_name: 'Google', safety_retention: true },
            { model_id: 'vendor/chat-refused', provider_name: 'Nobody', safety_retention: false },
          ],
        })
      }
      if (req.method === 'GET' && req.url === '/key') {
        // The real label can hold a key prefix; the probe must never print it.
        return json(200, { data: { label: FAKE_LABEL, usage: 0.0123, limit: 5 } })
      }
      if (req.method === 'POST' && req.url === '/embeddings') {
        const n = body.dimensions ?? 1024
        return json(200, { data: [{ embedding: new Array(n).fill(0.1) }], provider: 'Azure' })
      }
      if (req.method === 'POST' && req.url === '/chat/completions') {
        if (body.model === 'vendor/chat-refused') {
          return json(404, { error: { code: 404, message: 'No endpoints found matching your data policy (Zero data retention).' } })
        }
        return json(200, { provider: body.model === 'vendor/chat-sr' ? 'Google' : 'Parasail', choices: [{ message: { content: 'ok' } }] })
      }
      return json(404, { error: { message: 'unexpected ' + req.method + ' ' + req.url } })
    })
  })
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => resolve({ server, seen, base: `http://127.0.0.1:${server.address().port}` }))
  })
}

test('self-test passes offline and asserts the privacy provider object', async () => {
  const r = await run(['--self-test'], {})
  assert.equal(r.code, 0, r.stdout + r.stderr)
  assert.match(r.stdout, /self-test: ok/)
})

test('missing key exits 2 with the variable name and no key text', async () => {
  const r = await run([], {})
  assert.equal(r.code, 2)
  assert.match(r.stderr, /OPENROUTER_TEST_KEY is not set/)
})

test('--key-env names another variable in the missing-key message', async () => {
  const r = await run(['--key-env', 'ORGANIZER_KEY'], {})
  assert.equal(r.code, 2)
  assert.match(r.stderr, /ORGANIZER_KEY is not set/)
})

test('live path against a stub: ZDR on every POST, key used but never printed', async () => {
  const { server, seen, base } = await startStub()
  try {
    const r = await run(
      [
        '--base-url', base,
        '--embedding-models', 'openai/text-embedding-3-large,qwen/qwen3-embedding-8b',
        '--chat-models', 'vendor/chat-ok,vendor/chat-sr,vendor/chat-refused,vendor/chat-unlisted',
      ],
      { OPENROUTER_TEST_KEY: FAKE_KEY },
    )
    assert.equal(r.code, 0, r.stdout + r.stderr)

    // T-23-20: the key and its label never reach the output.
    for (const text of [r.stdout, r.stderr]) {
      assert.ok(!text.includes(FAKE_KEY), 'key text in output')
      assert.ok(!text.includes('sk-or'), 'sk-or in output')
      assert.ok(!text.includes(FAKE_LABEL), 'key label in output')
    }

    // The key was used as a bearer token.
    assert.ok(seen.some((s) => s.auth === `Bearer ${FAKE_KEY}`))

    // T-23-21: every POST carries the privacy provider object and the fixed sentence.
    const posts = seen.filter((s) => s.method === 'POST')
    assert.ok(posts.length >= 5, 'expected embedding and chat calls, got ' + posts.length)
    for (const p of posts) {
      assert.equal(p.body.provider.zdr, true)
      assert.equal(p.body.provider.data_collection, 'deny')
    }
    const embeds = posts.filter((p) => p.url === '/embeddings')
    assert.ok(embeds.every((p) => p.body.input === 'LocalNodes probe sentence.'))
    assert.ok(embeds.some((p) => p.body.model === 'openai/text-embedding-3-large' && p.body.dimensions === 3072))
    assert.ok(embeds.some((p) => p.body.model === 'qwen/qwen3-embedding-8b' && p.body.dimensions === 4096), 'native size probed when it differs')

    // The table: ids, sizes, status codes, who served it. The unlisted model made no call.
    assert.match(r.stdout, /openai\/text-embedding-3-large\s+3072\s+3072\s+200/)
    assert.match(r.stdout, /qwen\/qwen3-embedding-8b\s+4096\s+4096\s+200/)
    assert.match(r.stdout, /vendor\/chat-ok.*200.*Parasail/)
    assert.match(r.stdout, /vendor\/chat-sr.*200.*Google.*safety-retention/)
    assert.match(r.stdout, /vendor\/chat-refused.*404.*no-zdr-endpoint/)
    assert.match(r.stdout, /vendor\/chat-unlisted.*skipped/)
    assert.ok(!posts.some((p) => p.body.model === 'vendor/chat-unlisted'))

    // Spend report shows numbers only.
    assert.match(r.stdout, /usage \$0\.0123 of \$5/)
  } finally {
    server.close()
  }
})

test('--key-env reads the named variable', async () => {
  const { server, seen, base } = await startStub()
  try {
    const r = await run(
      ['--base-url', base, '--key-env', 'ORGANIZER_KEY', '--embedding-models', 'openai/text-embedding-3-large', '--chat-models', 'vendor/chat-ok'],
      { ORGANIZER_KEY: FAKE_KEY },
    )
    assert.equal(r.code, 0, r.stdout + r.stderr)
    assert.ok(seen.every((s) => s.auth === `Bearer ${FAKE_KEY}`))
    assert.ok(!r.stdout.includes(FAKE_KEY))
  } finally {
    server.close()
  }
})

// ---- provider pins (23-21 Task 2): the probe sends what production sends ----

const fixturePins = join(here, 'fixtures', 'openrouter-pins.fixture.yml')
const realSettings = join(
  here, '..', '..', '..', 'html', 'modules', 'custom', 'localnodes_platform', 'modules',
  'localnodes_openrouter', 'config', 'install', 'localnodes_openrouter.settings.yml',
)

// What the settings file holds today (23-MODEL-GATE.md pins). A pin refresh
// changes this table in the same commit as the YAML.
const REAL_PINS = {
  'mistralai/mistral-small-2603': ['mistral/eu', 'mistral/us', 'mistral/zdr'],
  'z-ai/glm-5.3': ['wafer/us', 'wafer/fp4', 'reka'],
  'openai/gpt-oss-120b': ['primeintellect/fp4', 'near-ai/fp4'],
  'openai/text-embedding-3-large': ['azure'],
}

test('the pins reader reads every pinned model of the real settings file, nothing more', () => {
  const pins = probeLib.loadPins(realSettings)
  assert.deepEqual(pins.map((p) => p.model), Object.keys(REAL_PINS))
  for (const entry of pins) {
    assert.deepEqual(entry.only, REAL_PINS[entry.model], entry.model)
    assert.deepEqual(entry.ignore, [], entry.model)
  }
  // Every "- model:" line of the file was read (no entry skipped).
  const modelLines = readFileSync(realSettings, 'utf8').split('\n').filter((l) => /^ {2}- model:/.test(l))
  assert.equal(pins.length, modelLines.length)
})

test('the default pins file is the real settings file', () => {
  assert.equal(probeLib.DEFAULT_PINS_FILE, realSettings)
})

test('each pinned model of the real file gets exactly its only list, with the privacy fields', () => {
  const pins = probeLib.loadPins(realSettings)
  for (const [model, only] of Object.entries(REAL_PINS)) {
    const chat = probeLib.buildChatBody(model, pins)
    const emb = probeLib.buildEmbeddingBody(model, 3072, pins)
    for (const body of [chat, emb]) {
      assert.deepEqual(body.provider, { zdr: true, data_collection: 'deny', only }, model)
      assert.ok(!('ignore' in body.provider), `${model} gained an ignore`)
    }
  }
})

test('an unpinned model gets the privacy fields and no only or ignore', () => {
  const pins = probeLib.loadPins(realSettings)
  assert.deepEqual(probeLib.buildChatBody('vendor/unpinned', pins).provider, { zdr: true, data_collection: 'deny' })
  assert.deepEqual(probeLib.buildEmbeddingBody('vendor/unpinned', 1024, pins).provider, { zdr: true, data_collection: 'deny' })
  // No pins given at all: the old behaviour.
  assert.deepEqual(probeLib.buildChatBody('vendor/unpinned').provider, { zdr: true, data_collection: 'deny' })
})

test('fixture: ignore is sent when not empty, with or without only; an empty entry pins nothing', () => {
  const pins = probeLib.loadPins(fixturePins)
  assert.deepEqual(pins.map((p) => p.model), [
    'fixture/both', 'fixture/ignore-only', 'fixture/empty', 'fixture/inline', 'fixture/only-block',
  ])
  const sent = (m) => probeLib.buildChatBody(m, pins).provider
  const base = { zdr: true, data_collection: 'deny' }
  assert.deepEqual(sent('fixture/both'), { ...base, only: ['host-a/one', 'host-a/two'], ignore: ['bad-host/one'] })
  assert.deepEqual(sent('fixture/ignore-only'), { ...base, ignore: ['bad-host/two', 'bad-host/three'] })
  assert.deepEqual(sent('fixture/empty'), base)
  assert.deepEqual(sent('fixture/inline'), { ...base, only: ['host-b/one', 'host-b/two'], ignore: ['bad-host/four'] })
  assert.deepEqual(sent('fixture/only-block'), { ...base, only: ['host-c'] })
  // Same for embeddings.
  assert.deepEqual(probeLib.buildEmbeddingBody('fixture/ignore-only', 8, pins).provider, sent('fixture/ignore-only'))
})

test('the body has the same keys production sends (PrivateOpenRouterClient::withPrivacy)', () => {
  // Production: zdr, data_collection, then only (non-empty), then ignore (non-empty).
  const pins = probeLib.loadPins(fixturePins)
  assert.deepEqual(Object.keys(probeLib.buildChatBody('fixture/both', pins).provider), ['zdr', 'data_collection', 'only', 'ignore'])
  assert.deepEqual(Object.keys(probeLib.buildChatBody('fixture/ignore-only', pins).provider), ['zdr', 'data_collection', 'ignore'])
  assert.deepEqual(Object.keys(probeLib.buildChatBody('fixture/empty', pins).provider), ['zdr', 'data_collection'])
})

test('pins never mutate the shared PROVIDER constant, and the bodies do not share lists with the pins', () => {
  const pins = probeLib.loadPins(fixturePins)
  const body = probeLib.buildChatBody('fixture/both', pins)
  body.provider.only.push('tampered')
  body.provider.zdr = false
  assert.deepEqual(probeLib.PROVIDER, { zdr: true, data_collection: 'deny' })
  assert.ok(Object.isFrozen(probeLib.PROVIDER))
  assert.deepEqual(probeLib.buildChatBody('fixture/both', pins).provider.only, ['host-a/one', 'host-a/two'])
})

test('the first entry for a model wins, as in production', () => {
  const text = 'provider_pins:\n  - model: a/b\n    only: []\n    ignore: []\n  - model: a/b\n    only:\n      - late\n    ignore: []\n'
  assert.deepEqual(probeLib.buildChatBody('a/b', probeLib.parsePins(text)).provider, { zdr: true, data_collection: 'deny' })
})

test('a malformed pins file is an error, never silently no pins', () => {
  assert.throws(() => probeLib.parsePins('chat_model: x\n'), /provider_pins/)
  assert.throws(() => probeLib.parsePins('provider_pins:\n  - model: a/b\n    only: nope\n'), /only/)
  assert.throws(() => probeLib.parsePins('provider_pins:\n  - model: a/b\n    surprise: 1\n'), /surprise/)
  assert.throws(() => probeLib.loadPins(join(here, 'fixtures', 'does-not-exist.yml')))
})

test('live path: every POST carries the pins of its model; --pins-file overrides the path', async () => {
  const { server, seen, base } = await startStub()
  try {
    const r = await run(
      [
        '--base-url', base, '--pins-file', fixturePins,
        '--embedding-models', 'openai/text-embedding-3-large',
        '--chat-models', 'vendor/chat-ok',
      ],
      { OPENROUTER_TEST_KEY: FAKE_KEY },
    )
    assert.equal(r.code, 0, r.stdout + r.stderr)
    const posts = seen.filter((s) => s.method === 'POST')
    assert.ok(posts.length >= 2)
    // The fixture pins neither stub model: nothing but the privacy fields.
    for (const p of posts) assert.deepEqual(p.body.provider, { zdr: true, data_collection: 'deny' })

    // The default file (the real settings) pins the embedding model to azure.
    seen.length = 0
    const r2 = await run(
      ['--base-url', base, '--embedding-models', 'openai/text-embedding-3-large', '--chat-models', 'vendor/chat-ok'],
      { OPENROUTER_TEST_KEY: FAKE_KEY },
    )
    assert.equal(r2.code, 0, r2.stdout + r2.stderr)
    const embeds = seen.filter((s) => s.url === '/embeddings')
    assert.ok(embeds.length >= 1)
    for (const p of embeds) assert.deepEqual(p.body.provider, { zdr: true, data_collection: 'deny', only: ['azure'] })
    for (const p of seen.filter((s) => s.url === '/chat/completions')) {
      assert.deepEqual(p.body.provider, { zdr: true, data_collection: 'deny' })
    }
  } finally {
    server.close()
  }
})

test('a missing --pins-file exits 2 before any call', async () => {
  const { server, seen, base } = await startStub()
  try {
    const r = await run(['--base-url', base, '--pins-file', join(here, 'fixtures', 'nope.yml')], { OPENROUTER_TEST_KEY: FAKE_KEY })
    assert.equal(r.code, 2)
    assert.match(r.stderr, /pins file/)
    assert.equal(seen.length, 0)
  } finally {
    server.close()
  }
})
