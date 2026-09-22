# UpScale WP Test Store — Progress

> Snapshot of what is done vs. what remains, read from the files, the containers and
> the checks that were actually run. Keep this current; it is the "what's the state"
> file.

## Done — photographs, logo and finish (this session, 2026-09-22)

- `scripts/fetch_product_photos.py` (host, stdlib): searches Wikimedia Commons per
  **product-type keyword** (36 of them), keeps only CC0 / public domain / CC BY /
  CC BY-SA files ≥700 px, filters out logos/diagrams/documents, downloads them into
  `assets/product-photos/<keyword>/`, downloads hero candidates into `assets/hero/`,
  and writes `data/photo-sources.json` (title, author, licence, licence URL, file
  page, dimensions, description) plus `data/photo-map.json` (category → keyword).
  `--only <keyword>` refreshes a single type; `--per-keyword`, `--heroes` tune it.
- `scripts/inc/demo-data.php`: shared helpers — reading the generated JSON, the
  keyword → Ukrainian label table, and `upscale_demo_keyword_for_term()` with
  ancestor inheritance.
- `scripts/seed-photos.php` (in-container, idempotent): imports each photo into the
  media library once (cached in `upscale_photo_attachments`), stores
  `upscale_category_images` (174 categories) and `upscale_hero_image`, attaches a
  matching featured image + gallery to every demo product, and publishes the
  "Джерела зображень" attribution page (88 rows).
- `scripts/seed-catalog.php`: product names now follow the photo keyword
  (`{label} {brand} {model}`), existing products are renamed on re-run, the menu is
  assigned to `primary` **and** `handheld`, and the WooCommerce/WordPress pages get
  Ukrainian titles.
- Theme: `assets/logo.svg` (own mark), header branding block, hero background
  photograph, category tiles with photos, footer logo + "Джерела зображень" link,
  Ukrainian `gettext` fallback, CSS for all of it.
- `scripts/setup.ps1`: new `-PhotosPath` / `-SkipPhotos`, the photo download step and
  the photo pass, with a summary line.
- Docs: README (storefront table, quick start, layout tree, troubleshooting rows),
  `.gitignore` (photo assets are generated), decisions **T12**, **T13**.

## Done — test runbook for the UpScale product (this session, 2026-09-22)

- `docs/TESTING.md` — the end-to-end runbook for testing the UpScale product against
  this store: fresh REST credentials, the backend `.env`, the two caveats that
  silently break the run, the one-product smoke test, the bulk CSV workflow, the
  success checklist and the pitfall table. Linked from `README.md` (intro + Related)
  and listed in the README layout tree.
- The caveats were checked against the actual code, not assumed:
  `apply_store_mapping_override()` reads `UPSCALE_MAPPING_FILE` with
  `os.path.isfile()` and returns `False` **silently** when the file is unreachable,
  and the backend's `docker-compose.yml` mounts no host path — so a backend running
  in Docker cannot see `D:/UpScale-WP-Test/config/mapping.json` without an added
  volume, and attributes would degrade to `id: 0` with an empty `gcategory`.
- This part of the session was **read-only** for the store, theme, scripts and
  generated artifacts: nothing was re-run except reading files and `git status`.

## Verification actually run

| Check | Result |
| --- | --- |
| `python -m py_compile` on the new scripts | OK |
| `php -l` on `seed-catalog.php`, `seed-photos.php`, `inc/demo-data.php` and all theme files | no syntax errors |
| `scripts/setup.ps1` end to end | 174 categories, 304 products, 41 menu items, photo pass `imported 1, reused 86, products linked 303, categories linked 174` |
| `python scripts/verify.py` | **All checks passed** |
| Home / shop / category / product / sources pages | HTTP 200; logo + hero photo + 8 tile photos + product photos; 0 stray page items; add-to-cart label "Додати в кошик" |
| Demo products without an image | 0 of 304 |
| Categories with a photo | 174 of 174 |
| Attribution page | 88 rows, 87 Commons file links |

## Known gaps / issues

- `scripts/setup.sh` was **not** updated with the catalog / theme / photo / seed
  steps; it is also CRLF-terminated and unreliable under Git Bash (T7). `setup.ps1`
  is the validated path — parity for `setup.sh` is open work.
- Photographs repeat across products (Commons has no unique photo per demo product)
  and show *types* of products, not the exact model named in the card.
- The REST key pair is regenerated on every setup run, so the `.env` block must be
  re-pasted after each run.
- `setup.ps1` can end with exit code 1 even on success (wp-cli writes "already
  installed/active" warnings to stderr and `2>&1` marks the pipeline failed); every
  step is still checked explicitly with `$LASTEXITCODE` inside the script.
- The attribution page is a licence obligation, not decoration (T12): removing it or
  reusing the photos elsewhere requires re-satisfying CC BY / CC BY-SA.
- `Uncategorized` (term 15) and the wrapper root `katalog` (17) both exist; the theme
  and the seeders ignore `uncategorized` explicitly.
- The working tree **is** a git repository (`main`, one commit, remote `origin`), but
  handoff is via the shared filesystem + memory bank, not commits: this session's work
  is uncommitted (modified `README.md`, `AGENTS.md`, `scripts/*`, `memory-bank/*`,
  theme files; new `scripts/fetch_product_photos.py`, `scripts/seed-photos.php`,
  `scripts/inc/demo-data.php`, `theme/upscale-storefront/assets/logo.svg`,
  `assets/`). Nothing was committed or pushed.
- No automated test suite; the checks above are scripted manual runs.
- The generated ids/artifacts become stale after `docker compose down -v`; re-run
  `build_catalog.py`, `fetch_product_photos.py` and `setup.ps1`.
- The store is HTTP-only; `curl -u` Basic auth returns 401 by design (T3) — use
  `verify.py` (OAuth1).

## Not started

- `push` of any product (needs explicit authorization).
- A first live `create → collect → process → generate` run with real OpenAI/Claid
  credentials (blocked on credentials, not on the store).
- A curated photo per product type with several distinct shots (currently 1–3 per
  type), and brand-logo artwork for the brand strip (deliberately text-only).
- RU/UK language switching and Elementor-style page building — out of scope (T8).
- Any CI.
