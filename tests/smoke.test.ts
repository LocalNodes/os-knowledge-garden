import { describe, expect, it } from 'vitest';

describe('vitest harness', () => {
  it('boots and runs a trivial assertion', () => {
    expect(1 + 1).toBe(2);
  });
});
