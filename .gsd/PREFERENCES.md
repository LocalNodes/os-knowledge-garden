# GSD preferences — os-knowledge-garden (localnodes seat)

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
  # Slice PRs auto squash-merge into milestone/<mid> with NO review window — by design.
  slice_prs: true
