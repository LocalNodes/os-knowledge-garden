// Why the voting setup stopped, read from the judge's own test site when its setup wait fails.
//
// The CI log is public, so nothing here prints characters from the site. The step and its stop
// reason print only when they are one of the setup runner's own values (fixed lists below, from
// safe_governance's SafeGovernanceInterface::STATUS_* and SetupRunner::DETAILS). The logged
// reason prints only when it is exactly one of the fixed refusal keys below; any other value
// prints only that it is there and its length. A read that fails or takes too long prints
// `not read` and never changes the test's outcome.
import { execFile } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/** The setup runner's statuses. */
export const SETUP_STEPS = ['draft', 'approving', 'setting_up', 'naming', 'publishing', 'ready', 'failed'] as const;

/** Why a setup step stopped (SetupRunner::DETAILS). */
export const SETUP_DETAILS = ['setup_failed', 'name_taken', 'rules_refused', 'refused', 'cap', 'unread', 'unknown', 'held'] as const;

/** The log message whose reason is read: the voting service refused the settings. */
export const REFUSED_MESSAGE = 'The voting service refused the settings of voting record @id (@reason).';

/**
 * The keys safe_governance logs for the voting service's refusal reasons, one per known refusal
 * of a settings post, and `other` for the rest. Keep the same as the module's list.
 */
export const REFUSAL_KEYS = [
  'signature_invalid',
  'not_controller',
  'space_format',
  'strategy_invalid',
  'network_not_allowed',
  'timestamp',
  'wrong_types',
  'wrong_domain',
  'too_large',
  'duplicate',
  'other',
] as const;

/** How long the site read may take. */
export const READ_MS = 20_000;

/** What the site read gives back; any field may be missing. */
export type SetupState = { status?: unknown; detail?: unknown; reason?: unknown };

/** The step line: the status and stop reason, each only when it is a known value. */
export function stepLine(state: SetupState): string {
  const status = typeof state.status === 'string' && (SETUP_STEPS as readonly string[]).includes(state.status) ? state.status : 'not printable';
  let detail: string;
  if (state.detail === '' || state.detail === null || state.detail === undefined) detail = 'none';
  else if (typeof state.detail === 'string' && (SETUP_DETAILS as readonly string[]).includes(state.detail)) detail = state.detail;
  else detail = 'not printable';
  return `setup step: ${status}, stopped: ${detail}`;
}

/** The reason line: a fixed refusal key, or only that a reason is there and its length. */
export function reasonLine(reason: unknown): string {
  if (reason === null || reason === undefined) return 'reason: no log entry';
  if (reason === '') return 'reason: empty';
  if (typeof reason === 'string' && (REFUSAL_KEYS as readonly string[]).includes(reason)) return `reason: ${reason}`;
  return `reason: present, not printed (${String(reason).length} characters)`;
}

/** The two lines from the site read's output: the last JSON line, or `not read` for both. */
export function linesFrom(output: string | null): [string, string] {
  const json = (output ?? '').split('\n').map((l) => l.trim()).filter((l) => l.startsWith('{')).pop();
  if (json === undefined) return ['setup step: not read', 'reason: not read'];
  // A line starting with `{` parses only to an object.
  let state: SetupState;
  try {
    state = JSON.parse(json) as SetupState;
  }
  catch {
    return ['setup step: not read', 'reason: not read'];
  }
  return [stepLine(state), reasonLine(state.reason)];
}

/** The PHP the site evaluates: the newest voting record's status and detail, and the newest refusal's reason. */
export const READ_PHP = [
  "$ids = \\Drupal::entityQuery('safe_governance')->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();",
  "$r = $ids ? \\Drupal::entityTypeManager()->getStorage('safe_governance')->load(reset($ids)) : NULL;",
  "$v = NULL;",
  "if (\\Drupal::database()->schema()->tableExists('watchdog')) {",
  "  $row = \\Drupal::database()->select('watchdog', 'w')->fields('w', ['variables'])->condition('type', 'safe_governance')",
  `    ->condition('message', '${REFUSED_MESSAGE}')->orderBy('wid', 'DESC')->range(0, 1)->execute()->fetchField();`,
  "  $v = $row ? @unserialize($row, ['allowed_classes' => FALSE]) : NULL;",
  "}",
  "echo json_encode(['status' => $r ? (string) $r->get('status')->value : NULL, 'detail' => $r ? (string) $r->get('step_detail')->value : NULL,",
  "  'reason' => is_array($v) && isset($v['@reason']) ? (string) $v['@reason'] : NULL]), PHP_EOL;",
].join('\n');

/** Calls a command and gives its stdout, or null on any failure or after `ms`. */
export type Runner = (file: string, args: string[], ms: number) => Promise<string | null>;

const execRead: Runner = (file, args, ms) =>
  new Promise((resolve) => {
    const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
    const timer = setTimeout(() => resolve(null), ms + 2_000);
    try {
      execFile(file, args, { cwd: root, timeout: ms, killSignal: 'SIGKILL', maxBuffer: 64 * 1024 }, (error, stdout) => {
        clearTimeout(timer);
        resolve(error ? null : String(stdout));
      });
    }
    catch {
      clearTimeout(timer);
      resolve(null);
    }
  });

/** The drush call in the test site's container, as the workflow's seed step makes it. */
export const DRUSH_ARGS = [
  'compose', '-f', 'docker-compose.yaml', '-f', 'tests/e2e-live/compose.ci.yaml', '-f', 'tests/e2e-live/compose.governance.yaml',
  'exec', '-T', 'opensocial', '/var/www/html/vendor/bin/drush', '-r', '/var/www/html/html', '--uri=http://localhost:8080',
  'php:eval', READ_PHP,
];

/** The step and reason lines; never throws. */
export async function setupReason(runner: Runner = execRead): Promise<[string, string]> {
  try {
    return linesFrom(await runner('docker', DRUSH_ARGS, READ_MS));
  }
  catch {
    return ['setup step: not read', 'reason: not read'];
  }
}
