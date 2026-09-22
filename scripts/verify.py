#!/usr/bin/env python3
"""Verify the UpScale test store through the WooCommerce REST API.

Uses **OAuth 1.0a** — the same authentication the UpScale backend uses (the
`woocommerce` Python SDK signs with the consumer key/secret).

Why not `curl -u key:secret`? WooCommerce only attempts Basic authentication
when `is_ssl()` is true (see
`includes/class-wc-rest-authentication.php`), so on a plain-HTTP store every
Basic-auth call returns 401 `woocommerce_rest_cannot_view`. OAuth1 works over
HTTP. Run this script on an HTTP store instead of the curl checks.

Usage:
    python scripts/verify.py --url http://localhost:8080 \
        --key ck_xxx --secret cs_xxx
"""

import argparse
import base64
import hashlib
import hmac
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

_enc = lambda s: urllib.parse.quote(str(s), safe="~")


def request(base_url, key, secret, method, path, query=None, body=None):
    url = base_url.rstrip("/") + path
    params = {
        "oauth_consumer_key": key,
        "oauth_nonce": uuid.uuid4().hex,
        "oauth_signature_method": "HMAC-SHA1",
        "oauth_timestamp": str(int(time.time())),
        "oauth_version": "1.0",
    }
    if query:
        params.update({k: str(v) for k, v in query.items()})

    pairs = sorted((_enc(k), _enc(v)) for k, v in params.items())
    base = "%s&%s&%s" % (
        method.upper(),
        _enc(url),
        _enc("&".join("%s=%s" % (k, v) for k, v in pairs)),
    )
    signature = base64.b64encode(
        hmac.new((_enc(secret) + "&").encode(), base.encode(), hashlib.sha1).digest()
    ).decode()

    auth = "OAuth " + ", ".join('%s="%s"' % (_enc(k), _enc(v)) for k, v in sorted(params.items()))
    auth += ', oauth_signature="%s"' % _enc(signature)

    headers = {"Authorization": auth, "Accept": "application/json"}
    data = None
    if body is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(body).encode()

    if query:
        url += "?" + urllib.parse.urlencode({k: str(v) for k, v in query.items()})

    req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as err:
        return err.code, err.read().decode("utf-8", "replace")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--url", default="http://localhost:8080")
    ap.add_argument("--key", required=True)
    ap.add_argument("--secret", required=True)
    args = ap.parse_args()

    failures = 0

    def check(label, condition, detail=""):
        nonlocal failures
        if not condition:
            failures += 1
        print("%-46s %s %s" % (label, "OK" if condition else "FAIL", detail))

    code, body = request(args.url, args.key, args.secret, "GET", "/wp-json/wc/v3/products", {"per_page": 1})
    check("REST reachable + credentials valid", code == 200, "HTTP %s" % code)

    code, body = request(args.url, args.key, args.secret, "GET", "/wp-json/wc/v3/products/attributes", {"per_page": 100})
    attrs = json.loads(body) if code == 200 else []
    ids = ", ".join("%s=%s" % (a["id"], a["name"]) for a in attrs)
    check("Global attributes readable", code == 200, ids or "HTTP %s" % code)
    for required in ("Бренд", "Производители", "mpn", "gcategory"):
        check("  attribute present: %s" % required, any(a["name"] == required for a in attrs))

    code, body = request(args.url, args.key, args.secret, "GET", "/wp-json/wc/v3/products/categories", {"per_page": 100})
    cats = json.loads(body) if code == 200 else []
    check("Categories readable", code == 200, ", ".join("%s=%s" % (c["id"], c["name"]) for c in cats))

    # Write scope: create a draft, then delete it.
    code, body = request(
        args.url, args.key, args.secret, "POST", "/wp-json/wc/v3/products",
        body={"name": "UpScale connectivity test", "status": "draft", "type": "simple", "regular_price": "1"},
    )
    created = json.loads(body) if code in (200, 201) else {}
    check("Write scope (create draft)", bool(created.get("id")), "HTTP %s id=%s" % (code, created.get("id")))

    if created.get("id"):
        code, body = request(
            args.url, args.key, args.secret, "DELETE", "/wp-json/wc/v3/products/%s" % created["id"],
            query={"force": "true"},
        )
        check("Delete the test draft", code in (200, 204), "HTTP %s" % code)

    print()
    if failures:
        print("%d check(s) failed." % failures)
        return 1
    print("All checks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
