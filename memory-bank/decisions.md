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

## T8. Mirror the reference storefront's *structure*, never its content

**Date:** 2026-09-22. **Status:** implemented.

The user asked for a store like `https://beauty-mafia.com.ua/`. What this project
reproduces is the **structure**: the category tree (names/slugs/hierarchy read from
the site's public Store API), the layout (utility bar, dark navigation with
dropdowns, hero, category tiles, product grid, brand strip, trust blocks,
testimonials, four-column footer) and the store settings (UAH, four columns,
ratings on).

What it does **not** reproduce: the site's product names, descriptions,
photographs, banners, reviews, brand name ("Beauty Mafia") or copy. Demo products
are labelled `Демо-товар: …` with deterministic demo prices and generated
placeholder images; reviews are labelled `(демо-відгук)`; the store title, phone
and address are this project's own and obviously fake.

**Why:** copying a third party's product content and brand into this store would
be a copyright/trademark problem and serves no testing purpose — the pipeline
needs a realistic *shape* (categories, attributes, ids), not someone else's text.

**Consequences:** comparisons between the two sites will show a similar layout and
clearly different content. Anything imported *from* the reference site is a public
**URL**, never a copied artefact.

## T9. `data/catalog.json` is an input; `setup-site.php` stays the only artifact generator

**Date:** 2026-09-22. **Status:** implemented. **Refines T6.**

- `scripts/build_catalog.py` (host, Python stdlib) reads the reference site's
  public category list, matches slugs against the backend's `gpt_data.py`
  snapshot for the Google product category, adds this project's own Ukrainian
  `keywords`/`icp`, and writes `data/catalog.json` and `data/source-products.csv`.
- `scripts/setup-site.php` (in-container) consumes `data/catalog.json`, creates the
  `product_cat` tree parent-first and **still** writes `config/mapping.json` and
  `mock-ups/categories.json` from the store's real ids.
- Without `data/catalog.json` the legacy single `test-category` path runs.

**Why:** the three-way id contract (mock ↔ `gcategories_json` ↔ `product_cat`) must
keep exactly one generator (T6), while the catalog content is data, not code.

**Consequences:** after changing the catalog, run `build_catalog.py` and then the
setup; `data/catalog.json` is generated — regenerate it, never hand-edit it. A
category that exists but is not in the catalog is left untouched.

## T10. Demo seeding is a separate, idempotent script

**Date:** 2026-09-22. **Status:** implemented.

`scripts/seed-catalog.php` (in-container, run by `scripts/setup.ps1`) adds demo
products (SKU `DEMO-<source_id>-<n>`, deterministic demo prices derived from the
SKU), fictional demo brands, the `Головне меню` navigation menu, the four
informational pages and the WooCommerce/UAH settings.

It skips anything that already exists, and it only rebuilds the navigation menu
when it created that menu (`upscale_primary_menu_id`), so manual edits are never
overwritten.

**Why:** after `docker compose down -v` the storefront must be reproducible in one
command, and a second run must not duplicate products, reviews, pages or menu
items.

**Consequences:** safe to re-run. `wp option upscale_placeholder_ids` caches the
imported placeholder attachments so images are imported once.

## T11. Placeholder images are generated locally

**Date:** 2026-09-22. **Status:** implemented.

`scripts/make_placeholders.py` writes eight abstract PNG tiles (pure Python, no
Pillow) into `assets/placeholders/`; `setup.ps1` imports them with
`wp media import` and passes the attachment ids to the seeder.

**Why:** the storefront needs images to look like a real catalogue, while T8
forbids downloading another site's photographs; Pillow would add a host
dependency this project does not otherwise need.

**Consequences:** product images are abstract tiles, not photography. Changing the
generator changes the PNGs only; already-imported attachments keep their ids.

## T12. Real product photographs come from Wikimedia Commons, with the licence recorded

**Date:** 2026-09-22. **Status:** implemented. **Extends T8.**

`scripts/fetch_product_photos.py` downloads photographs of real products from
Wikimedia Commons — CC0 / public domain / CC BY / CC BY-SA only, at least 700 px,
with logos, diagrams and documents filtered out — into
`assets/product-photos/<keyword>/`, and records the title, author, licence, licence
URL and file page in `data/photo-sources.json`. It also stores one hero banner
candidate per run under `assets/hero/`.

`scripts/seed-photos.php` then imports them into the media library (once — the
file → attachment map is cached in `upscale_photo_attachments`) and:

- maps every category to a **kind of product** (clipper, dryer, chair, lamp …) via
  `data/photo-map.json`, inheriting from the nearest ancestor when a leaf has none;
- gives every demo product a featured image and a gallery of that kind, and names
  it after the object in the photo (`Мийка парикмахерська BarberCraft M-870`) so
  the card and its picture agree;
- publishes the "Джерела зображень" page listing each file with author, licence and
  Commons link.

**Why:** the absent photographs were exactly what made the storefront look
unfinished. Commons keeps the store visually real *and* honest: attribution is
published where the licences require it, and nothing is taken from the reference
shop's catalogue (its `wp-content/uploads/*` images, `bm-banner*`, brand logos).

**Consequences:** the attribution for CC BY / CC BY-SA files **must** stay
published — if that page is removed, or the photos are reused elsewhere, the licences
have to be satisfied again. A photograph is reused across several demo products
(Commons holds no 304 unique shop photographs); that is acceptable for a demo.
`--only <keyword>` refills a single gap without re-downloading everything.

## T13. The storefront speaks Ukrainian and hides Storefront's page-list fallback

**Date:** 2026-09-22. **Status:** implemented.

- `wp language core install uk`, `wp language plugin install woocommerce uk`,
  `WPLANG=uk`: the chrome ("Додати в кошик", "Пошук товарів", "Кошик") comes from
  real translations rather than our own string mapping. The theme keeps a small
  `gettext` fallback for a store still running the English locale.
- `scripts/seed-catalog.php` renames WooCommerce's pages and WordPress' sample page
  (Кошик, Оформлення замовлення, Мій кабінет, Каталог, Демо-сторінка WordPress).
- The "Головне меню" menu is assigned to Storefront's `handheld` location as well as
  `primary`, because Storefront otherwise prints a plain WordPress page list in the
  mobile navigation (and in the markup) — it looked like an unfinished menu.

**Why:** English chrome mixed into a Ukrainian demo looked unfinished, and the stray
page list was visible on narrow screens.

**Consequences:** a store rebuilt from scratch re-installs the language packs (needs
network access from the container); `WPLANG` is store state, not a tracked artifact.
`AGENTS.md`'s constraint set is unaffected: this is presentation only.
