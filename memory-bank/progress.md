# UpScale WP Test Store — Progress

> Snapshot of what is done vs. what remains, read from the files
> (`docker-compose.yml`, `scripts/`, `mock-ups/`, `config/`, `README.md`).
> Keep this current; it is the "what's the state" file.

## Done — documentation + memory bank (this session, 2026-09-22)

- Added the agent documentation and shared context for this project, mirroring the
  backend's pattern:
  - `AGENTS.md`, `CLAUDE.md`
  - `.clinerules/memory.md`, `.clinerules/workflow.md`
  - `memory-bank/`: `README.md`, `projectBrief.md`, `architecture.md`,
    `decisions.md` (T1–T7), `activeContext.md`, `progress.md`, `strategy.md`,
    `plans/current-plan.md`, `plans/archive/.gitkeep`
- Diagnosed the Windows setup errors: CRLF-terminated `setup.sh` (81 `\r\n` pairs)
  and `setup.ps1` invoked through `bash`. Recorded as **T7**; the supported path is
  `scripts/setup.ps1` via PowerShell.
- Validation: file inspection only. No stack run and no pipeline run in this
  session. No scripts, `docker-compose.yml`, or generated config were changed.

## Done — the test store itself (as built / observed)

- `docker-compose.yml`: `wp` (WordPress 7.1), `wpdb` (MySQL 8.4, host 3308),
  `cli` (WP-CLI, root mounted at `/work`), `ups-mock` (:9000), on
  `upscale_test_network`.
- `mock-ups/`: FastAPI stand-in for the "UPS" category API (Basic auth, four
  endpoints, file re-read per request).
- `scripts/`: `setup.ps1` (Windows), `setup.sh` (Linux/macOS), `setup-site.php`
  (in-container: attributes, category, REST keys, JSON artifacts), `verify.py`
  (OAuth1 checks), `smoke.ps1` (one product through the pipeline).
- `config/mapping.example.json` (schema reference) and generated
  `config/mapping.json` (real ids: `Бренд`=1, `Производители`=2, `mpn`=3,
  `gcategory`=4; `test-category`=16).
- `README.md` — canonical runbook (services, quick start, id problem, networking,
  verification, smoke, troubleshooting, tear down, layout).
- Backend coupling (recorded there as D19/D20) and the optional
  `UPSCALE_MAPPING_FILE` override, plus `snippets/upscale-woocommerce.php`
  (confirmed present at `../UpScale-Back-master/snippets/`).

## In progress / needs validation

- Running `scripts/setup.ps1` on the user's Windows host (they ran the Linux/PowerShell
  scripts through `bash` and hit the errors above). The supported command is:
  `powershell -ExecutionPolicy Bypass -File scripts/setup.ps1`.
- A full end-to-end pipeline run (`create → collect → process → generate`) with
  live OpenAI and Claid credentials has **not** been performed here; `push` remains
  unauthorized until requested.

## Known gaps / issues

- `scripts/setup.sh` is CRLF-terminated and unreliable under Git Bash on Windows
  (T7). `setup.ps1` works around it; converting to LF is optional cleanup.
- No git repository → no revert safety net, no commit-based handoff.
- No automated test suite.
- The generated ids in `config/mapping.json` / `mock-ups/categories.json` become
  stale after `docker compose down -v`; `scripts/setup.*` must be re-run.
- The store is HTTP-only; `curl -u` Basic auth returns 401 by design (T3) and must
  not be "fixed" by adding TLS — use OAuth1.
- The id bridge depends on the backend's `UPSCALE_MAPPING_FILE` override, which is
  a test enabler, not the long-term live-fetch fix (backend
  `docs/woocommerce-site-setup.md` §7).

## Not started

- Automating a full smoke run from a single entrypoint (setup → verify → smoke).
- A clean `down -v` + rebuild + re-setup walkthrough documented as a runbook step.
- Any CI.
