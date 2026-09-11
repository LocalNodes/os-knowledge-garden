---
version: 1
git:
  main_branch: main
  # branch = milestone/<mid> in the project root (ddev keeps working). worktree (one
  # checkout per milestone/slice, needed for slice_parallel + per-direction previews)
  # waits on git.worktree_post_create for Drupal (settings.local.php, files/, vendor/).
  isolation: branch
  merge_strategy: merge
  # Per-worktree ddev preview (unique project name, background start, URL in .gsd-preview-url).
  # Only fires under isolation: worktree or /gsd worktree create. 30 s timeout, non-fatal.
  worktree_post_create: .gsd/hooks/post-worktree-create
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

# ── Prototype profile (Tommy 2026-09-11, hackathon mode) ─────────────────────
# NO token_profile: every named profile except burn-max sets skip_research: true
# (preferences-models.ts:629-655). Research stays ON — "what already exists in
# Drupal / OSS" is what makes planning solid. Skip only the re-planning churn.
phases:
  skip_research: false
  skip_slice_research: false
  skip_reassess: true
  reassess_after_slice: false
  skip_milestone_validation: false   # MV01-MV04 ask "does it work / integrate" — keep
  require_slice_discussion: false
  mid_execution_escalation: false
  progressive_planning: false

# Gates split by purpose: "does it function" stays, "perfect the code" goes.
gate_evaluation:
  enabled: false        # Q3 "how can this be exploited", Q4 "which R-IDs to re-test"
  task_gates: false     # Q5 failure modes, Q6 10x load, Q7 negative tests (default ON)

# Agents walk first: routine UAT on; browser UAT dispatches regardless (uat-policy.ts:161).
uat_dispatch: true
verification_auto_fix: true         # false PAUSES on first failure
verification_max_retries: 1
post_unit_hooks: []
pre_dispatch_hooks: []

# Models: bare ids resolve on the session provider (claude-code, set via `gsd config`).
# Fable plans/discusses, Opus executes/researches/validates, Sonnet ONLY for
# exceptionally well-scoped routine work (execution_simple). Bump down if running rich.
models:
  discuss: claude-fable-5
  planning: claude-fable-5
  research: claude-opus-5
  execution: claude-opus-5
  execution_simple: claude-sonnet-5
  completion: claude-opus-5
  validation: claude-opus-5
  subagent: claude-opus-5
  uat: claude-opus-5
thinking:
  discuss: high
  planning: high
  research: medium
  execution: medium     # measured thrash floor; do not go lower
  completion: low
  validation: medium
  subagent: medium
dynamic_routing:
  enabled: false        # explicit models disable it anyway (auto-model-selection.ts:683)
  cross_provider: false

# Plan convergence is a COMMAND, not a pref: after plan-milestone Hermes runs
#   gsd plan-review-convergence --codex --max-cycles 1
# (one Codex gpt-6-astra round; needs `codex login`, NEEDS-TOMMY c).

# Unattended liveness (1.19.0 turns wedges into pauses — someone must be listening).
context_pause_threshold: 0
auto_supervisor:
  soft_timeout_minutes: 15
  idle_timeout_minutes: 10
  hard_timeout_minutes: 25
  stalled_tool_timeout_minutes: 5
context_management:
  observation_masking: true
  observation_mask_turns: 6
notifications:
  enabled: true
  on_attention: true
  on_error: true
  on_complete: false
# remote_questions: {channel: slack, channel_id: "<project-channel-id>", timeout_minutes: 5}  # after NEEDS-TOMMY (b)
---

# GSD preferences — os-knowledge-garden (localnodes seat)

Preferences live in the YAML frontmatter above (gsd requires `---` fences; bare YAML is silently ignored — open-gsd/gsd-pi#2036).
