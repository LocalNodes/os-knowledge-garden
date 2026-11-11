// A loopback Upstash REST stub and a second built sign-up server pointed at it,
// for the no-scripts sign-up form test only (23-39, F2b; orchestrator route A,
// 2026-11-08).
//
// Why: while the server renders /onboarding it reads room through
// GET /api/capacity. The main test server on :3000 has no Redis, so that read
// fails and, with scripts off, the page shows "Waiting for Room", never the
// form. page.route can't reach a read the server makes. So this test starts its
// own pair:
//   - startUpstashStub(): an http server on 127.0.0.1 that speaks just enough of
//     Upstash's REST protocol to answer the three reads the room check makes
//     (GET capacity:vps, GET capacity:apps, ZCOUNT capacity:inflight). Every
//     other command gets null. Every command is recorded, so the test can show
//     no write reached it.
//   - startSignupServer(): the onboarding build's .output/server/index.mjs on
//     its own loopback port, pointed at the stub, with an allowlisted
//     environment (never a copy of process.env, so no key in the shell reaches
//     it) and room for 5 sites.
// The main server on :3000 stays without Redis, so its "full" states don't
// change.
//
// Node built-ins only; no package.
import { spawn, type ChildProcess } from 'node:child_process';
import { existsSync } from 'node:fs';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { createServer as createNetServer, type AddressInfo } from 'node:net';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const STUB_TOKEN = 'upstash-stub-token';

export interface UpstashStub {
  url: string;
  token: string;
  /** Every command received, in order, as sent (command name first). */
  commands: string[][];
  close: () => Promise<void>;
}

/** A capacity report that passes capacityReportSchema, received just now. */
function freshReport(): string {
  return JSON.stringify({
    memAvailableMb: 64000,
    dockerFreeGb: 500,
    load1: 0.1,
    nproc: 8,
    reportedAt: new Date().toISOString(),
    receivedAt: Date.now(),
  });
}

/** The answer to one command. Anything the room check doesn't read gets null. */
function answer(command: string[]): string | number | null {
  const [name = '', key = ''] = command;
  const op = name.toUpperCase();
  if (op === 'GET' && key === 'capacity:vps') return freshReport();
  // A cached count of running sites, so Coolify is never called.
  if (op === 'GET' && key === 'capacity:apps') return '0';
  if (op === 'ZCOUNT' && key === 'capacity:inflight') return 0;
  return null;
}

function readBody(req: IncomingMessage): Promise<string> {
  return new Promise((done, fail) => {
    const chunks: Buffer[] = [];
    req.on('data', (c: Buffer) => chunks.push(c));
    req.on('end', () => done(Buffer.concat(chunks).toString('utf8')));
    req.on('error', fail);
  });
}

function send(res: ServerResponse, status: number, body: unknown) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
}

/** Starts the Upstash REST stub on 127.0.0.1 and a free port. */
export async function startUpstashStub(): Promise<UpstashStub> {
  const commands: string[][] = [];
  const server = createServer(async (req, res) => {
    if (req.headers.authorization !== `Bearer ${STUB_TOKEN}`) return send(res, 401, { error: 'Unauthorized' });
    if (req.method !== 'POST') return send(res, 405, { error: 'Method not allowed' });
    let parsed: unknown;
    try {
      parsed = JSON.parse(await readBody(req));
    } catch {
      return send(res, 400, { error: 'Bad JSON' });
    }
    const base64 = String(req.headers['upstash-encoding'] ?? '').toLowerCase() === 'base64';
    const encode = (r: string | number | null) => (base64 && typeof r === 'string' ? Buffer.from(r, 'utf8').toString('base64') : r);
    const run = (cmd: unknown) => {
      const command = Array.isArray(cmd) ? cmd.map((part) => String(part)) : [];
      commands.push(command);
      return { result: encode(answer(command)) };
    };
    const path = (req.url ?? '/').split('?')[0];
    if (path === '/pipeline' || path === '/multi-exec') {
      if (!Array.isArray(parsed)) return send(res, 400, { error: 'A pipeline is a list of commands' });
      return send(res, 200, parsed.map(run));
    }
    if (path === '/' || path === '') return send(res, 200, run(parsed));
    return send(res, 404, { error: 'Not found' });
  });
  await new Promise<void>((done) => server.listen(0, '127.0.0.1', done));
  const { port } = server.address() as AddressInfo;
  return {
    url: `http://127.0.0.1:${port}`,
    token: STUB_TOKEN,
    commands,
    close: () => new Promise<void>((done) => server.close(() => done())),
  };
}

/** A free port on 127.0.0.1. */
function freePort(): Promise<number> {
  return new Promise((done, fail) => {
    const probe = createNetServer();
    probe.once('error', fail);
    probe.listen(0, '127.0.0.1', () => {
      const { port } = probe.address() as AddressInfo;
      probe.close(() => done(port));
    });
  });
}

export interface SignupServer {
  url: string;
  stop: () => Promise<void>;
}

/** The onboarding repo: LN_ONBOARDING_DIR, or ../localnodes-onboarding next to this repo. */
function onboardingDir(): string {
  return process.env.LN_ONBOARDING_DIR ? resolve(process.env.LN_ONBOARDING_DIR) : join(REPO_ROOT, '..', 'localnodes-onboarding');
}

/**
 * Starts a second built sign-up server on 127.0.0.1, pointed at the stub. Waits
 * until GET /invite answers 200 (up to 30 s).
 */
export async function startSignupServer({ redisUrl, redisToken }: { redisUrl: string, redisToken: string }): Promise<SignupServer> {
  const entry = join(onboardingDir(), '.output', 'server', 'index.mjs');
  if (!existsSync(entry)) throw new Error('build the sign-up site first (NUXT_LAUNCH_OPEN=true npm run build)');
  const port = await freePort();
  // An allowlist, never a copy of process.env: no Stripe, Resend, Gemini,
  // Coolify or Upstash key in the shell reaches this server.
  const env: NodeJS.ProcessEnv = {
    PATH: process.env.PATH ?? '',
    HOME: process.env.HOME ?? '',
    NODE_ENV: 'production',
    HOST: '127.0.0.1',
    PORT: String(port),
    NUXT_LAUNCH_OPEN: 'true',
    NUXT_UPSTASH_REDIS_URL: redisUrl,
    NUXT_UPSTASH_REDIS_TOKEN: redisToken,
    NUXT_CAPACITY_MAX_SITES: '5',
  };
  const child: ChildProcess = spawn(process.execPath, [entry], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  let output = '';
  child.stdout?.on('data', (c: Buffer) => { output += c.toString(); });
  child.stderr?.on('data', (c: Buffer) => { output += c.toString(); });
  const exited = new Promise<void>((done) => child.once('exit', () => done()));
  const stop = async () => {
    if (child.exitCode === null && child.signalCode === null) child.kill('SIGTERM');
    await exited;
  };
  const url = `http://127.0.0.1:${port}`;
  const deadline = Date.now() + 30_000;
  while (Date.now() < deadline) {
    if (child.exitCode !== null) break;
    const up = await fetch(`${url}/invite`).then((r) => r.status === 200, () => false);
    if (up) return { url, stop };
    await new Promise((r) => setTimeout(r, 250));
  }
  await stop();
  throw new Error(`the second sign-up server didn't answer on ${url}/invite within 30 s. Output:\n${output}`);
}
