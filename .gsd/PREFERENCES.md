---
version: 1
git:
  main_branch: main
  merge_strategy: merge
  # Milestone publication: pushes milestone/<mid> and opens ONE draft PR to main.
  # That PR is the ONLY review gate (scripts/codex-review-gate.sh). auto_push is
  # suppressed while auto_pr is on (publication.ts:74).
  auto_pr: true
  pr_target_branch: main

github:
  enabled: true
  repo: LocalNodes/os-knowledge-garden
  labels: [gsd]
  auto_link_commits: true
  # slice_prs OFF: github-sync would SQUASH-merge each slice PR into milestone/<mid> and
  # delete the branch (sync.ts:526, cli.ts:296, hardcoded) — that destroys the per-task
  # commits that cite each other (M012 precedent). Slices commit sequentially on the
  # milestone branch instead; the milestone draft PR to main is the one review point.
  slice_prs: false
---

# GSD preferences — os-knowledge-garden (localnodes seat)

Preferences live in the YAML frontmatter above (gsd requires `---` fences; bare YAML is silently ignored — open-gsd/gsd-pi#2036).
