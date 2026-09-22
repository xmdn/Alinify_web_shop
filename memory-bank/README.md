# Shared Memory Bank

This directory is the durable project context shared by ChatGPT/Codex and Cline
for the **UpScale WP Test Store** (`D:/UpScale-WP-Test`). It follows the same
pattern as the backend's `D:/UpScale-Back-master/memory-bank/`, and is
intentionally stored in the repository so both agents can use the same facts,
decisions, plan, and progress.

The canonical operator runbook is `../README.md`; this directory is the agent
context index, not a replacement for the scripts or that runbook.

## Files

| File | Purpose | Update policy |
| --- | --- | --- |
| `projectBrief.md` | Scope, purpose, non-goals | Update when scope changes |
| `architecture.md` | Factual map of the stack, scripts, and id bridge | Update when reality changes |
| `decisions.md` | Durable decisions and rationale (prefix `T`) | Append; do not erase history |
| `activeContext.md` | Current focus, state, blockers, next step | Update every meaningful handoff |
| `progress.md` | Done, in progress, known gaps | Update after real implementation or validation |
| `strategy.md` | Strategic direction and working hypotheses | Update when direction changes |
| `plans/current-plan.md` | The active implementation plan | Keep aligned with the current task |
| `plans/archive/` | Completed historical plans | Move finished plans here when useful |

When this index and the repository disagree, inspect the repository and correct
the index.

## Synchronization model

This directory lives inside the project's git repository (`main`, one commit at the
time of writing, remote `origin`), but agent handoff does **not** rely on git:
there is no `push`/`pull` handoff and no revert safety net for uncommitted work, so:

```text
Agent A -> memory-bank -> shared filesystem -> Agent B
```

Only one agent should actively edit this checkout at a time. Before starting,
inspect the working tree and the generated artifacts (`config/mapping.json`,
`mock-ups/categories.json`); before handing off, write the current state to the
memory bank.

The project this store tests (`D:/UpScale-Back-master`) **is** a git repository
and records this project as its decision **D19** / **D20**. Read those when a
change touches the backend coupling.

## Recommended handoff prompts

### ChatGPT/Codex to Cline

```text
Read AGENTS.md and all applicable files in memory-bank/ before changing anything.
Read memory-bank/plans/current-plan.md and implement that plan as written.
Do not silently redesign recorded decisions. If the plan is invalid, stop and
document the blocker in memory-bank/ before changing direction.
Update activeContext.md and progress.md as you work, append durable decisions to
decisions.md, and record verification plus unresolved issues before handing back.
```

### Cline to ChatGPT/Codex

```text
Re-read AGENTS.md and the complete memory-bank/ before continuing. Inspect the
current working tree and the generated artifacts. Review what Cline changed
against memory-bank/plans/current-plan.md, decisions.md, and the acceptance
criteria. Run the relevant checks (docker compose config, scripts/verify.py,
scripts/smoke.ps1), then update activeContext.md, progress.md, and the plan with
the verified result and any remaining blockers.
```

## Update discipline

- Do not copy an entire conversation into the memory bank.
- Record stable facts, decisions, current state, and actionable next steps.
- Update `activeContext.md` when the active work, blockers, or next step change.
- Update `progress.md` when work moves between planned, in-progress, complete, or
  blocked.
- Add a dated entry to `decisions.md` when choosing between viable designs or
  changing an invariant.
- Do not mark work complete merely because a command was started; record the
  actual result of validation.
