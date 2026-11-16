// Labels-run reporter: fails the run if the guards' known password (guard-secret.ts) reaches
// anything a reporter receives: step titles and errors of every category (API steps included),
// test errors, stdout and stderr, and attachments, error-context.md among them.
// It prints only fixed text: where the password showed up, never the password itself.
import { readFileSync } from 'node:fs';
import type { FullResult, Reporter, TestCase, TestError, TestResult, TestStep } from '@playwright/test/reporter';
import { GUARD_SECRET } from './guard-secret';

function errorText(error: TestError | undefined): string {
  if (!error) return '';
  return [error.message, error.stack, error.value, error.snippet].filter(Boolean).join('\n');
}

export default class SecretScanReporter implements Reporter {
  private readonly hits = new Set<string>();

  private check(text: string | Buffer | undefined, where: string, test?: TestCase): void {
    if (text !== undefined && String(text).includes(GUARD_SECRET)) this.hits.add(`${where}${test ? ` (${test.title})` : ''}`);
  }

  onStepBegin(test: TestCase, _result: TestResult, step: TestStep): void {
    this.check(step.title, `a ${step.category} step title`, test);
  }

  onStepEnd(test: TestCase, _result: TestResult, step: TestStep): void {
    this.check(step.title, `a ${step.category} step title`, test);
    this.check(errorText(step.error), `a ${step.category} step error`, test);
    for (const a of step.attachments) this.check(a.body ?? (a.path ? readFileSync(a.path) : undefined), `a step attachment "${a.name}"`, test);
  }

  onStdOut(chunk: string | Buffer, test?: TestCase): void {
    this.check(chunk, 'stdout', test);
  }

  onStdErr(chunk: string | Buffer, test?: TestCase): void {
    this.check(chunk, 'stderr', test);
  }

  onTestEnd(test: TestCase, result: TestResult): void {
    for (const e of result.errors) this.check(errorText(e), 'a test error', test);
    for (const a of result.attachments) {
      let content: string | Buffer | undefined = a.body;
      if (!content && a.path) {
        try { content = readFileSync(a.path); } catch { content = undefined; }
      }
      this.check(content, `the attachment "${a.name}"`, test);
    }
  }

  onEnd(_result: FullResult): { status: FullResult['status'] } | undefined {
    if (this.hits.size === 0) {
      console.log('secret-scan: the guard password reached no reporter event');
      return undefined;
    }
    for (const hit of this.hits) console.log(`secret-scan: FAIL, the guard password reached ${hit}`);
    return { status: 'failed' };
  }
}
