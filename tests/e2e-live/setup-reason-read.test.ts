import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const execFile = vi.fn();
vi.mock('node:child_process', () => ({ execFile }));

const { setupReason } = await import('./setup-reason');

const notRead = ['setup step: not read', 'reason: not read'];

describe('the default site read', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    execFile.mockReset();
  });
  afterEach(() => vi.useRealTimers());

  it('asks for a 20 s limit, a kill signal and a small output cap, from the repository root', async () => {
    execFile.mockImplementation((_file, _args, _options, done) => done(null, '{"status":"ready","detail":"","reason":""}\n'));
    expect(await setupReason()).toEqual(['setup step: ready, stopped: none', 'reason: empty']);
    const [file, , options] = execFile.mock.calls[0];
    expect(file).toBe('docker');
    expect(options).toMatchObject({ timeout: 20_000, killSignal: 'SIGKILL', maxBuffer: 64 * 1024 });
    expect(options.cwd).toBe(resolve(dirname(fileURLToPath(import.meta.url)), '..', '..'));
  });

  it('settles as not read when the command never answers', async () => {
    execFile.mockImplementation(() => undefined);
    let lines: string[] | undefined;
    void setupReason().then((l) => { lines = l; });
    await vi.advanceTimersByTimeAsync(21_999);
    expect(lines).toBeUndefined();
    await vi.advanceTimersByTimeAsync(1);
    expect(lines).toEqual(notRead);
  });

  it('prints not read when the command fails or cannot start', async () => {
    execFile.mockImplementation((_file, _args, _options, done) => done(new Error('exit 1'), '{"status":"ready"}'));
    expect(await setupReason()).toEqual(notRead);
    execFile.mockImplementation(() => { throw new Error('spawn failed'); });
    expect(await setupReason()).toEqual(notRead);
  });
});
