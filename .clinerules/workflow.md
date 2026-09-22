# Shared Agent Workflow

For a non-trivial change:

1. Inspect the relevant scripts, `docker-compose.yml`, and the current working
   tree.
2. Read the memory bank as required by `AGENTS.md`.
3. If needed, write the goal, affected files, constraints, acceptance criteria,
   and tests to `memory-bank/plans/current-plan.md`.
4. Implement incrementally without redesigning recorded decisions silently.
5. Run the relevant checks when practical (`docker compose config`,
   `scripts/verify.py`, `scripts/smoke.ps1`, `py_compile`, `php -l`). Note that
   this project has no automated test suite.
6. Update `activeContext.md`, `progress.md`, and any applicable decision or
   architecture records.
7. Leave a concise handoff summary, including verification and known failures.

Stage-specific rules:

- `push` publishes to the test store. It runs only with explicit authorization
  (`-Push`); never add an implicit publish step.
- Never bypass Claid image processing or a category that is missing `icp` /
  `keywords`; report the blocker instead.
- Never point the store at production keys or a real store's data.
- Do not run `docker compose down -v` (it deletes the whole store and invalidates
  the generated ids) without explicit user authorization.
- `config/mapping.json` and `mock-ups/categories.json` are generated artifacts;
  regenerate them with `scripts/setup.*` rather than editing them by hand.

If a change would require editing the backend, pause: that crosses into
`../UpScale-Back-master`, which has its own rules and its own memory bank.
