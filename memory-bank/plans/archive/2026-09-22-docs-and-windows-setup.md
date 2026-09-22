# Plan — Documentation + memory bank, then run the store on Windows

> Active plan. Update it as scope changes. Do not mark anything done until the
> implementation and validation actually finished.

## Status

Phase 1 (documentation + memory bank) is **complete** in this session.
Phase 2 (run and validate the store on Windows) is **ready to execute by the
user**, not started here.

## Goal

Give `D:/UpScale-WP-Test` the same durable shared-context contract the backend has
(root agent instructions, `.clinerules/`, `memory-bank/`), and unblock the Windows
setup so the pipeline can be exercised against the store.

## Phase 1 — documentation + memory bank (DONE)

Deliverables:

- `AGENTS.md`, `CLAUDE.md`
- `.clinerules/memory.md`, `.clinerules/workflow.md`
- `memory-bank/`: `README.md`, `projectBrief.md`, `architecture.md`,
  `decisions.md` (T1–T7), `activeContext.md`, `progress.md`, `strategy.md`,
  `plans/current-plan.md`, `plans/archive/.gitkeep`

Acceptance criteria:

- Every file's content is traceable to the repository (or the backend's canonical
  docs for the coupling), not to a guess.
- No script, `docker-compose.yml`, or generated config is changed by this phase.
- The load-bearing constraints (test-store-only, `push` needs `-Push`, no bypassing
  Claid/empty metadata, `down -v` is destructive) appear in `AGENTS.md`.

Verification: source inspection across `docker-compose.yml`, `scripts/`,
`mock-ups/`, `config/`, and the backend's `memory-bank/` + `docs/`. No runtime
check was performed (documentation-only change).

## Phase 2 — run and validate the store on Windows (READY, user-run)

Steps (Windows host):

1. `docker compose up -d --build`
   (already done once by the user; all four containers came up.)
2. `powershell -ExecutionPolicy Bypass -File scripts/setup.ps1`
   — **the Windows path**; do not use `bash scripts/setup.sh` (CRLF, POSIX) or
   `bash scripts/setup.ps1` (wrong interpreter). See T7.
3. Paste the printed `.env` block into `../UpScale-Back-master/.env`
   (`WC_API_URL`, `WC_API_KEY`, `WC_API_SECRET`, `UPS_API_URL`, `UPS_API_KEY`,
   `UPSCALE_MAPPING_FILE`).
4. `python scripts/verify.py --url http://localhost:8080 --key <ck> --secret <cs>`
   — expect all checks OK (REST via OAuth1, the four attributes, categories,
   create draft, delete).
5. `powershell -ExecutionPolicy Bypass -File scripts/smoke.ps1 -Api http://localhost:8000 -Url <competitor URL> -Images <url>`
   — needs a real `GPT_API_KEY` and a Claid token with credits. Add `-Push` only
   with explicit authorization.

Acceptance criteria:

- `setup.ps1` runs end to end and writes `config/mapping.json` +
  `mock-ups/categories.json` with the store's real ids.
- `verify.py` reports "All checks passed."
- The backend resolves `PromptData` against this store's ids when
  `UPSCALE_MAPPING_FILE` is set.

Risks / notes:

- Docker's expected non-zero probe exits are handled (`$ErrorActionPreference =
  "Continue"`); do not flip it to `"Stop"`.
- `process` fails without Claid credits — that is a blocker, not a bug (backend D3).
- Never `docker compose down -v` unless the intent is to delete the store.

## Phase 3 — optional follow-ups (PROPOSED, needs the user to choose)

1. Convert `scripts/setup.sh` to LF line endings (or document the split further) so
   the Windows trap disappears.
2. Add an explicit "Windows quick start" callout to `README.md` (currently accurate
   but easy to miss — the user hit exactly this).
3. Script a single setup → verify → smoke entrypoint for regression runs.
4. Decide the long-term id strategy with the backend (live fetch vs the override).

## Constraints and dependencies

- Preserve T1–T7 and the backend's D1, D2, D3, D19, D20.
- Any change touching publication needs explicit user authorization.
- No git repository → back up before destructive changes.
