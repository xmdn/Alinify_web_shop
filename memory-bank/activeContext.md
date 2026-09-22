# UpScale WP Test Store — Active Context

> Current state as observed in the working tree. Update this file whenever the
> active work changes. Everything here was read from the files, not assumed.

## Current focus — documentation + memory bank (this session, 2026-09-22)

- The user asked for documentation for `D:/UpScale-WP-Test` modelled on the
  backend's documentation, plus a `memory-bank/`, and for help running the setup
  on Windows.
- Created in this session (documentation only; **no script, compose, or config
  changed**):
  - `AGENTS.md`, `CLAUDE.md`
  - `.clinerules/memory.md`, `.clinerules/workflow.md`
  - `memory-bank/`: `README.md`, `projectBrief.md`, `architecture.md`,
    `decisions.md` (T1–T7), `activeContext.md`, `progress.md`, `strategy.md`,
    `plans/current-plan.md`, `plans/archive/.gitkeep`
- Validation: source inspection of `docker-compose.yml`, `scripts/*`,
  `mock-ups/*`, `config/*`, and the backend's `memory-bank/` + `docs/`. No stack
  run and no end-to-end pipeline run were performed as validation here.

## The Windows setup problem (reported this session; workaround documented)

The user ran `docker compose up -d` successfully (all four containers up), then
hit two errors:

- `bash scripts/setup.sh` → `: invalid option namee 4: set: pipefail`.
  **Cause:** `setup.sh` is CRLF-terminated (**measured: 81 `\r\n` pairs**). Under
  Git Bash the `\r` becomes part of the option name in `set -euo pipefail`. It is
  also a POSIX script (`python3`, `/tmp`).
- `bash scripts/setup.ps1` → `syntax error near unexpected token newline`.
  **Cause:** wrong interpreter — `setup.ps1` is PowerShell, not bash.

**Supported Windows sequence (already in `README.md`):**

```powershell
docker compose up -d --build
powershell -ExecutionPolicy Bypass -File scripts/setup.ps1
```

Then paste the printed `.env` block into `../UpScale-Back-master/.env`, verify
with `python scripts/verify.py --url http://localhost:8080 --key <ck> --secret <cs>`,
and run `scripts/smoke.ps1` (push only with `-Push`).

Recorded as **T7**. The snippet path `../UpScale-Back-master/snippets/upscale-woocommerce.php`
was confirmed to exist, so the relative path resolves.

## Repository / artifact state

- This directory is **not a git repository** (confirmed: `git status` fails, no
  `.git`). There is no revert safety net and no commit-based handoff.
- `config/mapping.json` exists with real ids from a prior successful setup
  (`Бренд`=1, `Производители`=2, `mpn`=3, `gcategory`=4; `test-category`=16,
  g_category=4508). `mock-ups/categories.json` matches (category id `16`).
- `config/mapping.json` is git-ignored; `config/mapping.example.json` is the
  tracked schema reference (uses the production-style ids 562/563/567/664).

## Key facts to carry forward

- Stack: `wp` (WordPress 7.1, :8080), `wpdb` (MySQL 8.4, :3308), `cli`, `ups-mock` (:9000).
- The three-way category chain must agree: UPS mock ↔ `gcategories_json` ↔ store `product_cat`.
- The id bridge is the backend's `UPSCALE_MAPPING_FILE` override (backend D19).
- Verify with OAuth1, not `curl -u` (T3 / backend D20).
- `push` only with `-Push` (T5 / backend D1).

## Next step

1. Run `powershell -ExecutionPolicy Bypass -File scripts/setup.ps1` (Windows) and
   paste the `.env` block into `../UpScale-Back-master/.env`.
2. Run `scripts/verify.py` and confirm all checks pass.
3. Run `scripts/smoke.ps1` with live OpenAI and Claid credentials to exercise
   `create → collect → process → generate`; `push` only on explicit authorization.
4. Optional cleanup the user may request: convert `setup.sh` to LF line endings,
   or add a short "Windows quick start" note to `README.md` (already accurate —
   no change strictly needed).
