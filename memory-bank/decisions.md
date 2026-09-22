# UpScale WP Test Store — Decisions

> Durable decisions for **this** project (prefix `T`), with the rationale the
> files and the backend's canonical docs state. The backend keeps its own
> `D1…D20`; when a decision here mirrors one there, the backend number is cited.
> These are constraints, not preferences — changing one needs a deliberate reason.

## T1. A separate, disposable store is the integration target — never production

**Date:** 2026-09-21. **Status:** implemented (mirrors backend D1, D19).

This project is a standalone Docker Compose stack used to exercise the pipeline
end to end. It is **not** part of `D:/UpScale-Back-master` and the backend does
not depend on it at runtime.

**Why:** publishing test products to a real store is not permitted without
explicit authorization (backend D1), and a production store's ids are not
reproducible.

**Consequences:** the store is disposable (`docker compose down -v`) and must
**never** be given production keys or a real store's database. It is a test
harness, not a second deployment.

## T2. Bridge the id gap with a generated mapping file, not by editing production values

**Date:** 2026-09-21. **Status:** implemented (mirrors backend D19).

The backend's attribute and category ids are a static snapshot of one production
store (backend D13). Instead of editing those values, the backend gained an
additive override: `UPSCALE_MAPPING_FILE` replaces `actual_attributes`,
`spec_example`, and `gcategories_json`. `scripts/setup.*` generates that file from
the store's **real** ids.

**Why:** production behaviour must stay unchanged, and the test store's ids must
not leak back into the committed snapshots.

**Consequences:** with `UPSCALE_MAPPING_FILE` unset the backend behaves exactly as
before (verified). This override is a **test enabler**, not the long-term
live-fetch fix (backend `docs/woocommerce-site-setup.md` §7), which remains the
correct eventual change.

## T3. Verification uses OAuth 1.0a, not Basic auth

**Date:** 2026-09-21. **Status:** verified finding (mirrors backend D20).

WooCommerce only attempts Basic authentication when `is_ssl()` is true
(`includes/class-wc-rest-authentication.php`). On this plain-HTTP store,
`curl -u <ck>:<cs>` returns `401 woocommerce_rest_cannot_view` **even with valid
credentials**. The backend is unaffected (it signs with OAuth 1.0a), and this
project's `scripts/verify.py` signs the same way.

**Why:** the store is intentionally HTTP-only and local; forcing TLS would add
friction without changing what is being tested.

**Consequences:** use `scripts/verify.py` (or an HTTPS store) for REST checks; do
not diagnose a `curl -u` 401 as a credential problem.

## T4. WordPress 7.1 is required, not `wordpress:6`

**Date:** 2026-09-21. **Status:** implemented (found by running the stack).

`docker-compose.yml` pins `wordpress:7.1`. WooCommerce 11 requires WordPress 7.0+,
so the older `wordpress:6` image fails during plugin activation.

**Consequences:** changing the WordPress image down a major version will break the
setup; the compose comment records this.

## T5. `push` is never implicit

**Date:** 2026-09-21. **Status:** implemented (mirrors backend D1).

`scripts/smoke.ps1` drives `create → collect → process → generate` and stops. It
publishes to the store only when `-Push` is passed.

**Why:** publication is a deliberate, authorized action, even on a test store —
the habit and the code path must match the backend's contract.

**Consequences:** never add an implicit publish step to a script or task here.

## T6. The JSON artifacts are generated — regenerate, never hand-edit

**Date:** 2026-09-21. **Status:** implemented.

`config/mapping.json` and `mock-ups/categories.json` are written by
`scripts/setup-site.php` from the store's real ids. `config/mapping.json` is
git-ignored; only `config/mapping.example.json` is tracked.

**Why:** the ids must agree across three places at once; hand-editing one breaks
the chain silently.

**Consequences:** after `docker compose down -v` (which deletes the store and
invalidates the ids) re-run `scripts/setup.*`. Do not "fix" a mismatch by editing
the generated files.

## T7. `setup.ps1` is the Windows path; `setup.sh` is POSIX-only

**Date:** 2026-09-22. **Status:** observed problem, documented.

`scripts/setup.sh` is CRLF-terminated and depends on `python3`/`/tmp`, so running
it through `bash` on Windows fails at `set -euo pipefail` (`invalid option name`
because of the trailing `\r`). `scripts/setup.ps1` is the supported Windows
entrypoint; `setup.sh` targets Linux/macOS hosts.

**Consequences:** Windows operators run
`powershell -ExecutionPolicy Bypass -File scripts/setup.ps1`. Do not run
`setup.ps1` through `bash` (wrong interpreter). Converting `setup.sh` to LF is an
optional cleanup, not a requirement while `setup.ps1` exists.
