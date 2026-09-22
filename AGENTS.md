# Shared AI Development Instructions — UpScale WP Test Store

This project is the **disposable WordPress/WooCommerce test store** for the
UpScale import pipeline (`D:/UpScale-Back-master`). It uses `memory-bank/` as the
persistent source of truth shared by ChatGPT/Codex, Cline, and any other coding
agent. Conversation history and an agent's private memory are **not**
authoritative project state.

The backend it exists to exercise records this project as its decision **D19**
(separate test store + the `UPSCALE_MAPPING_FILE` override). Read
`../UpScale-Back-master/memory-bank/decisions.md` (D19, D20) and
`../UpScale-Back-master/docs/woocommerce-site-setup.md` before changing anything
that touches ids, the push payload, or store requirements.

## Before planning or implementing

For every non-trivial task, read these files in order:

1. `memory-bank/projectBrief.md`
2. `memory-bank/architecture.md`
3. `memory-bank/decisions.md`
4. `memory-bank/activeContext.md`
5. `memory-bank/progress.md`
6. `memory-bank/plans/current-plan.md`, when it exists

Then inspect the relevant scripts, `docker-compose.yml`, and generated config.
Do not invent project facts that are not supported by the repository.

If the task is large or affects multiple components, write or update
`memory-bank/plans/current-plan.md` before broad changes. The plan must state the
goal, affected files, constraints, risks, acceptance criteria, and tests.

## Source-of-truth rules

- The tracked files in `memory-bank/` are the shared memory between agents.
- This directory is **not a git repository**. There is no commit/push handoff and
  no revert safety net. Hand off through the shared filesystem and the memory
  bank, and back up before destructive changes.
- `config/mapping.json` and `mock-ups/categories.json` are **generated** by
  `scripts/setup.*` and must never be hand-edited to paper over a bug — re-run the
  setup instead (only `mapping.example.json` and the generated files' schema are
  stable facts).
- Existing scripts and the backend's canonical docs outrank stale memory notes.
  When they disagree, correct the memory bank and explain the change.
- Never overwrite another agent's uncommitted work.

## Load-bearing constraints (never violate without explicit user authorization)

1. **This is a test store.** Never point it at production keys, production
   credentials, or a real store's database. It is disposable on purpose.
2. **`push` is never implicit.** The smoke test stops before `push`; publishing to
   the store only happens when the operator explicitly passes `-Push`. Mirror the
   backend's D1 — no publication without authorization for that step.
3. **Do not bypass image processing or category metadata** to make a stage pass.
   A Claid credit failure or a category missing `icp`/`keywords` is a **blocker to
   report**, not something to code around (backend D2, D3).
4. **`docker compose down -v` is destructive.** It deletes the `wp_data` and
   `wp_db` volumes — the entire test store — and irrecoverably invalidates the
   generated `config/mapping.json` ids. Do not run it without the user's explicit
   request.
5. **Stay inside this project.** Changes to `../UpScale-Back-master` require a
   deliberate, documented reason and go through that repository's own rules. The
   only coupling this project relies on is the `UPSCALE_MAPPING_FILE` override and
   the `snippets/upscale-woocommerce.php` mu-plugin.

## While working

- Keep the current plan accurate as scope or decisions change.
- Keep changes focused; do not refactor the backend from here.
- Preserve the id contract across the three places it must agree:
  `NewProduct.category_id` ← the UPS mock ↔ `gcategories_json` ↔ the store's
  `product_cat` id.

## After meaningful work

Update the memory bank in the same working session:

- `activeContext.md`: current focus, changed areas, blockers, and the next step.
- `progress.md`: completed and remaining work. Do not call work complete until
  implementation and validation actually finished.
- `decisions.md`: append only durable decisions with date, decision, rationale,
  and consequences. Do not rewrite history.
- `architecture.md`: update only when the structure or an invariant really
  changed; keep it factual and derived from the files.
- `plans/current-plan.md`: keep the active plan aligned with reality.

Do not add noise for a trivial read-only task.

## Cline instructions

1. Read this file and the memory bank before changing anything.
2. Implement `memory-bank/plans/current-plan.md` without silently redesigning
   recorded decisions.
3. Update `activeContext.md` and `progress.md` while work progresses, and record
   failed checks as well as successes.
4. Before handing work back, leave a concise review-ready summary.

## Handoff protocol

Only one agent should actively edit this checkout at a time.

### ChatGPT/Codex -> Cline

1. Read the current memory bank and repository state.
2. Write the plan and decisions to `memory-bank/`.
3. Save, then tell Cline to read `AGENTS.md` and the memory bank before working.

### Cline -> ChatGPT/Codex

1. Finish or pause at a clear boundary.
2. Update `activeContext.md`, `progress.md`, `decisions.md`, and the current plan.
3. Record what was verified and what remains.
4. Ask ChatGPT/Codex to re-read the memory bank; never rely on the previous
   conversation alone.
