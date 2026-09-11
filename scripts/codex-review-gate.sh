#!/usr/bin/env bash
# codex-review-gate.sh — local Codex review of this branch, blocking on P1s.
#
# Replaces the GitHub "codex-review-clean" status check (retired 2026-09-08):
# the review runs on YOUR machine with YOUR Codex login, once per branch, and
# nothing in GitHub blocks a merge on it.
#
#   scripts/codex-review-gate.sh                 # review HEAD against origin/main
#   scripts/codex-review-gate.sh --base origin/develop
#   scripts/codex-review-gate.sh --uncommitted   # review the working tree instead
#   scripts/codex-review-gate.sh --merge <pr>    # review the PR head vs its base, then merge it
#                                                #   (the ONLY merge path on the seat; a bare
#                                                #   `gh pr merge` is blocked by approvals.deny)
#
# Exit 0 = no P0/P1 findings (P2/P3 may remain: file them as issues, do not thrash).
# Exit 1 = at least one P0/P1: fix, then run again. Exit 3 = could not review.
#
# Requirements: `codex` CLI (npm i -g @openai/codex) + `codex login` (ChatGPT
# account). Typical run: 3-8 minutes. The full transcript is kept at
# $CODEX_GATE_LOG (default: a temp file, path printed on failure).
#
# Opt-in as a git hook (blocks `git push` until P1-free):
#   git config core.hooksPath .githooks
# Skip once with CODEX_GATE=skip git push ... (say why in the PR).
set -uo pipefail
# Trusted copy of this script. The --merge path re-invokes the gate inside a throwaway
# worktree of the PR head; it must run THIS file, never the PR's own copy (a PR could
# otherwise edit the gate to pass itself). Codex gate P1, round 2.
SELF="$(readlink -f "$0")"

BASE="origin/main"
MODE="base"
while [ $# -gt 0 ]; do
  case "$1" in
    --base) MODE=base; BASE="${2:-origin/main}"; shift ;;
    --base=*) MODE=base; BASE="${1#--base=}" ;;
    --uncommitted) MODE=uncommitted ;;
    --merge) MODE=merge; PR="${2:?--merge needs a PR number}"; shift ;;
    -h|--help) sed -n 2,22p "$0"; exit 0 ;;
    *) echo "codex-review-gate: unknown argument $1" >&2; exit 3 ;;
  esac
  shift
done

if [ "${CODEX_GATE:-}" = "skip" ]; then
  echo "codex-review-gate: skipped (CODEX_GATE=skip)" >&2
  exit 0
fi
command -v codex >/dev/null 2>&1 || { echo "codex-review-gate: codex CLI not found (npm i -g @openai/codex; codex login)" >&2; exit 3; }
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || { echo "codex-review-gate: not a git repo" >&2; exit 3; }

if [ "$MODE" = "merge" ]; then
  # Review the PR's head against its base in a throwaway worktree, then merge it. GitHub auth:
  # the seat's App token (source ~/bin/gh-app-env.sh) unless GH_TOKEN is already set.
  [ -n "${GH_TOKEN:-}" ] || { [ -f "$HOME/bin/gh-app-env.sh" ] && . "$HOME/bin/gh-app-env.sh"; }
  INFO="$(gh pr view "$PR" --json headRefOid,baseRefName,state,isDraft 2>&1)" || { echo "codex-review-gate: gh pr view $PR failed: $INFO" >&2; exit 3; }
  HEAD_SHA="$(printf '%s' "$INFO" | sed -n 's/.*"headRefOid":"\([0-9a-f]*\)".*/\1/p')"
  PR_BASE="$(printf '%s' "$INFO" | sed -n 's/.*"baseRefName":"\([^"]*\)".*/\1/p')"
  case "$INFO" in *'"state":"OPEN"'*) ;; *) echo "codex-review-gate: PR $PR is not open ($INFO)" >&2; exit 3 ;; esac
  case "$INFO" in *'"isDraft":true'*) echo "codex-review-gate: PR $PR is a draft — gh pr ready $PR first" >&2; exit 3 ;; esac
  git fetch -q origin "$PR_BASE" "pull/$PR/head" || { echo "codex-review-gate: fetch of PR $PR failed" >&2; exit 3; }
  WT="$(mktemp -d -t codex-merge-XXXXXX)"
  git worktree add -q --detach "$WT" "$HEAD_SHA" || { echo "codex-review-gate: worktree for $HEAD_SHA failed" >&2; exit 3; }
  (cd "$WT" && "$SELF" --base "origin/$PR_BASE"); RC=$?
  git worktree remove --force "$WT" 2>/dev/null
  [ "$RC" -eq 0 ] || { echo "codex-review-gate: PR $PR NOT merged (review rc=$RC)" >&2; exit "$RC"; }
  METHOD="${CODEX_GATE_MERGE_METHOD:-merge}"      # merge commits by default: agent commits cite each other; squash only for scratch
  # --match-head-commit: if anything landed on the PR during the review, GitHub refuses the merge instead of merging unreviewed code.
  gh pr merge "$PR" "--$METHOD" --delete-branch --match-head-commit "$HEAD_SHA" && echo "codex-review-gate: merged PR $PR ($METHOD) after a clean review of $HEAD_SHA" >&2 || { echo "codex-review-gate: merge of PR $PR failed" >&2; exit 3; }
  exit 0
fi

if [ "$MODE" = "base" ]; then
  git rev-parse --verify -q "$BASE" >/dev/null || { echo "codex-review-gate: base $BASE not found (git fetch origin?)" >&2; exit 3; }
  DIFF="$(git diff --stat "$BASE"...HEAD 2>&1)" || { echo "codex-review-gate: git diff against $BASE failed: $DIFF" >&2; exit 3; }
  if [ -z "$DIFF" ]; then
    echo "codex-review-gate: nothing to review against $BASE" >&2
    exit 0
  fi
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "codex-review-gate: working tree has uncommitted tracked changes; Codex would review those, not HEAD. Commit or stash them, or use --uncommitted." >&2
    exit 3
  fi
  TARGET=(--base "$BASE")
  LABEL="$(git rev-parse --short HEAD) vs $BASE"
else
  TARGET=(--uncommitted)
  LABEL="working tree"
fi

LOG="${CODEX_GATE_LOG:-$(mktemp -t codex-review-XXXXXX.log)}"
echo "codex-review-gate: reviewing $LABEL ..." >&2
# read-only sandbox: a review never writes, and the default workspace-write
# sandbox fails on trees the sandbox user cannot create `.codex/` in.
if ! codex review -c 'sandbox_mode="read-only"' "${TARGET[@]}" >"$LOG" 2>&1; then
  echo "codex-review-gate: codex review failed (log: $LOG)" >&2
  grep -v 'rmcp::transport\|^mcp:' "$LOG" | tail -n 30 >&2
  exit 3
fi

# The transcript ends with Codex's final message after the last `codex` marker
# line; everything before it is tool output (file dumps) and MCP noise.
FINAL="$(awk '/^codex$/{buf=""; next} {buf=buf $0 "\n"} END{printf "%s", buf}' "$LOG")"
[ -z "$FINAL" ] && FINAL="$(tail -n 40 "$LOG")"
printf '%s\n' "$FINAL"

# Codex echoes its final message twice in the transcript: count distinct finding lines.
count() { grep -E "^[[:space:]]*[-*]?[[:space:]]*\[$1\]" <<<"$FINAL" | sort -u | grep -c .; }
P0="$(count P0)"; P1="$(count P1)"; P2="$(count P2)"; P3="$(count P3)"
# A failed inspection is never a clean review, whatever clean phrase follows it
# ("...could not run the diff; no issues found" must not pass). Codex opens such
# answers with "Review blocked"; the broader phrases are checked only when there
# are no tagged findings, because a finding's own text may say "could not".
if grep -qiE '^review blocked' <<<"$FINAL" || { [ $((P0 + P1 + P2 + P3)) -eq 0 ] && grep -qiE 'could not (run|inspect|be inspected|read|load|access)|unable to (inspect|review|read|access)|sandbox initialization failed|not a confirmed|unverified placeholder|permission denied' <<<"$FINAL"; }; then
  echo "codex-review-gate: Codex reports it could not inspect the diff (see above; log: $LOG)" >&2
  exit 3
fi
# A pass needs a RECOGNIZED result: tagged findings, or an explicit no-findings statement.
# Anything else (truncated answer, refusal, prose without tags) cannot be trusted as clean.
if [ $((P0 + P1 + P2 + P3)) -eq 0 ] && ! grep -qiE '\bno\b[^.]{0,60}\b(regression|issue|finding|problem|defect|concern|bug)s?\b|nothing to (flag|report)|looks good|LGTM|(did not|didn.?t|could not|couldn.?t) (find|identify) any' <<<"$FINAL"; then
  echo "codex-review-gate: no recognizable verdict in Codex's answer (no [P0-P3] findings and no explicit no-findings statement). Not passing on that; read the output above. Log: $LOG" >&2
  exit 3
fi
echo "VERDICT: P0=$P0 P1=$P1 P2=$P2 P3=$P3 ($LABEL)"
if [ $((P0 + P1)) -gt 0 ]; then
  echo "codex-review-gate: BLOCKED — $((P0 + P1)) blocking finding(s) (P0/P1). Fix them and run again; file P2/P3 as issues. Log: $LOG" >&2
  exit 1
fi
echo "codex-review-gate: clean (no P1) on $LABEL" >&2
# Post-clean: refresh the tree-sitter knowledge graph (graphify update = 0 tokens; NEVER bare
# `graphify .`, that needs a metered LLM key). Artifacts land in graphify-out/ (git-excluded).
if command -v graphify >/dev/null 2>&1 && [ "$MODE" = base ]; then
  graphify update . >/dev/null 2>&1 && echo "codex-review-gate: graphify update . refreshed graphify-out/" >&2 || echo "codex-review-gate: graphify update . failed (non-blocking)" >&2
fi
exit 0
