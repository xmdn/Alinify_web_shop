# UpScale WP Test Store — Strategy Context

> Records strategic context and working hypotheses. It is not a substitute for
> verified scripts or the canonical runbook (`README.md`). Anything here marked
> "hypothesis" is not an approved decision.

## Role in the product

This project is a **test harness**, not a product. Its value is entirely in
service of the UpScale import pipeline (`D:/UpScale-Back-master`): it provides a
reproducible, disposable WooCommerce target whose real ids can be pinned so the
pipeline can be exercised end to end without touching a real store.

The product being built is the **backend**; this project is scaffolding for
validating it.

## What it makes possible (hypothesis)

1. **Reproducible integration target** — a fresh store can be created, configured,
   and given known ids in one command, so a failed import can be reproduced rather
   than guessed at.
2. **Safe publication testing** — products can be imported and, with explicit
   authorization, published to a store that is truly disposable.
3. **Contract validation** — it surfaces exactly where the backend's static id
   snapshots (backend D13) drift from a real store, and proves the
   `UPSCALE_MAPPING_FILE` bridge (backend D19) closes the gap.

These are hypotheses drawn from the files. They have not been validated with a
full live pipeline run here.

## Capability boundary (present in the project)

- A four-service Compose stack (WordPress, MySQL, WP-CLI, UPS mock).
- One-command setup that generates the id artifacts.
- An OAuth1 verifier and a one-product smoke script (`push` opt-in).
- A stand-in category API with `icp`/`keywords`.

## Explicitly not present

- Any pipeline product code (all of it lives in the backend).
- Production credentials, TLS, or a real store's data.
- A test suite or CI.
- Automation of the full setup → verify → smoke sequence.

## Open questions

- Should the long-term fix be the backend's live-fetch (its
  `docs/woocommerce-site-setup.md` §7), making the `UPSCALE_MAPPING_FILE` override
  unnecessary? If so, this project becomes purely a store provisioner.
- Should `setup.sh` be converted to LF so a single script serves both hosts, or is
  the `ps1`/`sh` split preferable?
- Should the full pipeline smoke (including `push` under authorization) be
  scripted as a single reproducible run for regression testing?

## Relationship to the backend's strategy

The backend's `strategy.md` describes a forward-looking WordPress-plugin +
subscription product. This project supports the *current* single-store backend; it
has no bearing on pricing, metering, or tenancy decisions recorded there.
