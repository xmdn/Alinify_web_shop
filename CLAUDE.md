# UpScale WP Test Store — Development Guide

A local, disposable WordPress + WooCommerce store used to test the UpScale
product-import pipeline (`D:/UpScale-Back-master`). This is a **separate
project**: the backend never depends on it at runtime, and this store is never
pointed at production.

The full operator runbook, troubleshooting table, and rationale live in
`README.md`. This file is the short map for a coding agent; when in doubt,
`README.md` and the scripts are canonical.

## Why this project exists

UpScale pushes products through the standard WooCommerce REST API (`wc/v3`), and
its attribute/category ids are a **static snapshot of one production store**
(`PromptData.actual_attributes`, `PromptData.gcategories_json`). A fresh store has
different ids, and publishing to production is not permitted (backend D1). This
store provides a controlled target whose real ids can be read once and pinned into
the backend through the optional `UPSCALE_MAPPING_FILE` override (backend D19).

## The stack (docker-compose.yml)

| Service | Image | Host port | Role |
| --- | --- | --- | --- |
| `wp` | `wordpress:7.1` | **8080** | The store (`wordpress:6` is too old — WooCommerce 11 needs WP 7.0+) |
| `wpdb` | `mysql:8.4` | **3308** | WordPress DB (avoids UpScale's 3307) |
| `cli` | `wordpress:cli` | — | WP-CLI, kept idle for `docker compose exec` |
| `ups-mock` | built from `./mock-ups` | **9000** | Stand-in for the external "UPS" category API |

Runtime requirement: the backend must reach this store’s REST API and the UPS
mock. From a host-run API that is `http://localhost:8080/` and
`http://localhost:9000`; from Docker use `host.docker.internal` (see
`README.md`, "Networking").

## Running it

```powershell
# Windows host
docker compose up -d --build
powershell -ExecutionPolicy Bypass -File scripts/setup.ps1

# Linux / macOS host
docker compose up -d --build
bash scripts/setup.sh
```

Do **not** run the Linux script through `bash` on Windows: `setup.sh` is
CRLF-terminated and `setup.ps1` is PowerShell. `setup.ps1` is the correct Windows
path. See `memory-bank/activeContext.md` for this known trap.

`setup.*` finishes by printing an `.env` block. Paste it into
`../UpScale-Back-master/.env`.

## The scripts

| Script | Runs on | Purpose |
| --- | --- | --- |
| `scripts/setup.ps1` | Windows (PowerShell) | Full store setup; generates the artifacts |
| `scripts/setup.sh` | Linux/macOS (bash) | Same, for a POSIX host |
| `scripts/setup-site.php` | **inside** the container (`wp eval-file`) | Registers attributes, creates the category, generates a REST key pair, writes the JSON artifacts |
| `scripts/verify.py` | host (Python 3) | OAuth1 checks of the store (REST, attributes, categories, write+delete) |
| `scripts/smoke.ps1` | Windows (PowerShell) | Drives one product through `create → collect → process → generate`; `push` only with `-Push` |

## Load-bearing constraints

- **Test store only.** Never production keys, never a real store's data.
- **`push` is never implicit.** The smoke test stops before publishing unless
  `-Push` is passed (mirrors backend D1).
- **Do not bypass Claid or empty category metadata** to force a stage to pass
  (backend D2, D3) — report the blocker.
- **`docker compose down -v` deletes the whole store** and invalidates the
  generated ids.
- **`config/mapping.json` and `mock-ups/categories.json` are generated** — re-run
  the setup, never hand-edit them to hide a bug.

A coding agent must also read `AGENTS.md` and the whole `memory-bank/` before
changing anything.

## Verification

```powershell
# OAuth1 (works over plain HTTP; curl -u does NOT — see backend D20)
python scripts/verify.py --url http://localhost:8080 --key <WC_API_KEY> --secret <WC_API_SECRET>

# UPS mock (Basic auth is fine here — it is not WooCommerce)
curl.exe -s -u "admin:mock-ups-key" "http://localhost:9000/categories/get_categories"
```

## Related

- `README.md` — canonical runbook, troubleshooting, tear down.
- `memory-bank/` — shared persistent context for agents.
- `../UpScale-Back-master/docs/woocommerce-site-setup.md` — store requirements and id contracts.
- `../UpScale-Back-master/memory-bank/decisions.md` — backend D19 (this project) and D20 (auth).
