#!/usr/bin/env bash
# Configures the UpScale WordPress/WooCommerce test store (Linux/macOS).
# Run AFTER `docker compose up -d`. Windows: scripts/setup.ps1 does the same.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

WP_URL="${WP_URL:-http://localhost:8080}"
ADMIN_USER="${WP_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin123}"
ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"
CATEGORY_NAME="${CATEGORY_NAME:-Test Category}"
CATEGORY_SLUG="${CATEGORY_SLUG:-test-category}"
GOOGLE_CATEGORY="${GOOGLE_CATEGORY:-4508}"
SNIPPET="${SNIPPET:-../UpScale-Back-master/snippets/upscale-woocommerce.php}"

echo "==> Waiting for WordPress core files ..."
for _ in $(seq 1 90); do
    if docker compose exec -T cli sh -c "test -f /var/www/html/wp-config.php" >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

echo "==> WordPress core ..."
if ! docker compose exec -T cli sh -c "wp core is-installed >/dev/null 2>&1"; then
    docker compose exec -T cli wp core install \
        --url="$WP_URL" --title="UpScale Test Store" \
        --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWORD" \
        --admin_email="$ADMIN_EMAIL" --skip-email
fi

echo "==> Plugins ..."
for slug in woocommerce wordpress-seo yikes-inc-easy-custom-woocommerce-product-tabs; do
    docker compose exec -T cli wp plugin install "$slug" --activate
done

echo "==> Pretty permalinks ..."
docker compose exec -T cli wp rewrite structure '/%postname%/' --hard

if [ -f "$SNIPPET" ]; then
    echo "==> mu-plugin snippet ..."
    docker compose cp "$SNIPPET" "cli:/tmp/upscale-woocommerce.php"
    docker compose exec -T cli sh -c \
        "mkdir -p /var/www/html/wp-content/mu-plugins && cp /tmp/upscale-woocommerce.php /var/www/html/wp-content/mu-plugins/upscale-woocommerce.php"
else
    echo "!! Snippet not found at $SNIPPET" >&2
fi

echo "==> Attributes, category, REST keys ..."
docker compose cp scripts/setup-site.php "cli:/tmp/setup-site.php"
RAW="$(docker compose exec -T cli sh -c \
    "UPSCALE_TEST_CATEGORY='$CATEGORY_NAME' UPSCALE_TEST_CATEGORY_SLUG='$CATEGORY_SLUG' UPSCALE_ADMIN_LOGIN='$ADMIN_USER' UPSCALE_GOOGLE_CATEGORY='$GOOGLE_CATEGORY' UPSCALE_OUT_DIR=/work wp eval-file /tmp/setup-site.php")"

printf '%s' "$RAW" > /tmp/upscale-summary-raw.txt

python3 - "$ROOT" <<'PY'
import json, sys
root = sys.argv[1]
raw = open("/tmp/upscale-summary-raw.txt", encoding="utf-8").read()
s = json.loads(raw[raw.find("{"):raw.rfind("}") + 1])
cat = s["category"]
print()
print("Done.")
print("  Site URL   : %s" % s["site_url"])
print("  Permalinks : %s" % s["permalink"])
print("  Category   : %s (id %s, slug %s)" % (cat["name"], cat["id"], cat["slug"]))
print("  Attributes : %s global" % s["attribute_count"])
print("  spec ids   : %s" % ", ".join(str(a["id"]) for a in s.get("spec_example", [])))
print("  Wrote      : mock-ups/categories.json=%s  config/mapping.json=%s"
      % (s["written"]["categories"], s["written"]["mapping"]))
print()
print("# ---- paste into ../UpScale-Back-master/.env ------------------------------")
print("WC_API_URL=%s/" % s["site_url"])
print("WC_API_KEY=%s" % s["rest"]["consumer_key"])
print("WC_API_SECRET=%s" % s["rest"]["consumer_secret"])
print("UPS_API_URL=http://localhost:9000")
print("UPS_API_KEY=mock-ups-key")
print("UPSCALE_MAPPING_FILE=%s/config/mapping.json" % root)
PY
