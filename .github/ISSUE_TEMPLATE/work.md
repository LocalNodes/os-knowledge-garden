---
name: Work item
about: A unit of work with checkable acceptance gates. Closed only on pasted evidence.
title: ''
labels: ''
---

## Summary

<!-- One paragraph: what changes and why. Quote the person who asked, if someone did. -->

## Acceptance

<!--
One box per observable outcome, stated so a stranger could judge it.
Where a command can prove it, add CHECK/EXPECT lines (unlazy gates-leaf format).
The issue is closed only when every box has evidence pasted as a comment:
the deciding output lines for CHECK gates, an artifact URL (merged PR sha,
deploy URL) for manual ones. "I asked X" and "should work" are not evidence.
If a gate becomes impossible, do not delete it: add `ABANDON: G<n> <reason>`.
-->

- [ ] G1: <outcome>
  CHECK: <shell command that proves it>
  EXPECT: <substring the output must contain, or rc=0>

- [ ] G2: <manual outcome, when no command can prove it>
  EVIDENCE: <artifact URL once done>

## Out of scope

<!-- What this deliberately does not do, so nobody closes it for the wrong reason. -->
