<#
.SYNOPSIS
  Configures the UpScale WordPress/WooCommerce test store.

.DESCRIPTION
  Run AFTER `docker compose up -d`. Installs WordPress core, the required
  plugins, pretty permalinks and the mu-plugin snippet; registers the global
  attributes; creates a test category; generates a WooCommerce REST key pair.
  The in-container script then writes config/mapping.json and
  mock-ups/categories.json using the store's REAL ids.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts/setup.ps1
  pwsh -File scripts/setup.ps1
#>
[CmdletBinding()]
param(
    [string]$WpUrl          = "http://localhost:8080",
    [string]$AdminUser      = "admin",
    [string]$AdminPassword  = "admin123",
    [string]$AdminEmail     = "admin@example.com",
    [string]$CategoryName   = "Test Category",
    [string]$CategorySlug   = "test-category",
    [int]   $GoogleCategory = 4508,
    [string]$SnippetPath    = "../UpScale-Back-master/snippets/upscale-woocommerce.php",
    [string]$CatalogPath    = "data/catalog.json",
    [string]$ThemePath      = "theme/upscale-storefront",
    [string]$PhotosPath     = "data/photo-sources.json",
    [switch]$SkipCatalog,
    [switch]$SkipPhotos,
    [switch]$SkipStorefront
)

# "Continue" (not "Stop"): probing commands such as `wp core is-installed` are
# EXPECTED to return non-zero before the site is installed. Every step below
# checks $LASTEXITCODE explicitly instead.
$ErrorActionPreference = "Continue"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$pluginMap = [ordered]@{
    "woocommerce"                            = "WooCommerce"
    "wordpress-seo"                          = "Yoast SEO"
    "yikes-inc-easy-custom-woocommerce-product-tabs" = "Yikes Custom Product Tabs"
}

function Invoke-Wp {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$WpArgs)
    docker compose exec -T cli wp @WpArgs
    if ($LASTEXITCODE -ne 0) { throw "wp $($WpArgs -join ' ') failed (exit $LASTEXITCODE)" }
}

Write-Host "==> Waiting for WordPress core files ..." -ForegroundColor Cyan
$ready = $false
for ($i = 0; $i -lt 90; $i++) {
    docker compose exec -T cli sh -c "test -f /var/www/html/wp-config.php" 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) { throw "wp-config.php never appeared. Inspect 'docker compose logs wp'." }

$running = docker compose ps --services --filter "status=running"
if ($running -notcontains "cli") { throw "The 'cli' service is not running. Start the stack with 'docker compose up -d'." }

Write-Host "==> WordPress core ..." -ForegroundColor Cyan
docker compose exec -T cli sh -c "wp core is-installed >/dev/null 2>&1" 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
    Invoke-Wp core install --url=$WpUrl --title="UpScale Test Store" --admin_user=$AdminUser --admin_password=$AdminPassword --admin_email=$AdminEmail --skip-email
} else {
    Write-Host "    already installed" -ForegroundColor DarkGray
}

Write-Host "==> Plugins ..." -ForegroundColor Cyan
foreach ($slug in $pluginMap.Keys) {
    Write-Host "    $($pluginMap[$slug])" -ForegroundColor DarkGray
    Invoke-Wp plugin install $slug --activate
}

Write-Host "==> Pretty permalinks ..." -ForegroundColor Cyan
Invoke-Wp rewrite structure "/%postname%/" --hard

if (-not $SkipStorefront) {
    Write-Host "==> Storefront theme + child theme ..." -ForegroundColor Cyan
    docker compose exec -T cli sh -c "wp theme is-installed storefront" 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Invoke-Wp theme install storefront
    }
    if (-not (Test-Path $ThemePath)) {
        throw "Child theme not found at '$ThemePath'. Expected theme/upscale-storefront."
    }
    docker compose cp $ThemePath "cli:/var/www/html/wp-content/themes/"
    if ($LASTEXITCODE -ne 0) { throw "Could not copy the child theme into the container." }
    Invoke-Wp theme activate upscale-storefront
}

if (Test-Path $SnippetPath) {
    Write-Host "==> mu-plugin snippet ..." -ForegroundColor Cyan
    docker compose cp $SnippetPath "cli:/tmp/upscale-woocommerce.php"
    docker compose exec -T cli sh -c "mkdir -p /var/www/html/wp-content/mu-plugins && cp /tmp/upscale-woocommerce.php /var/www/html/wp-content/mu-plugins/upscale-woocommerce.php"
    if ($LASTEXITCODE -ne 0) { throw "Could not install the mu-plugin snippet." }
} else {
    Write-Warning "Snippet not found at '$SnippetPath' - attribute ids will not be registered by the site."
}

Write-Host "==> Attributes, category, REST keys ..." -ForegroundColor Cyan
docker compose cp scripts/setup-site.php "cli:/tmp/setup-site.php"
if ($LASTEXITCODE -ne 0) { throw "Could not copy scripts/setup-site.php into the cli container." }
$rawFile = Join-Path $env:TEMP "upscale-setup-raw.txt"
$catalogEnv = ""
$catalogRel = ($CatalogPath -replace '\\', '/')
if (-not $SkipCatalog -and (Test-Path $CatalogPath)) {
    $catalogEnv = " UPSCALE_CATALOG_FILE='/work/$catalogRel'"
} elseif (-not $SkipCatalog) {
    Write-Warning "No catalogue at '$CatalogPath' - only the legacy test category will exist. Run 'python scripts/build_catalog.py' first."
}
$inner   = "UPSCALE_TEST_CATEGORY='$CategoryName' UPSCALE_TEST_CATEGORY_SLUG='$CategorySlug' UPSCALE_ADMIN_LOGIN='$AdminUser' UPSCALE_GOOGLE_CATEGORY='$GoogleCategory' UPSCALE_OUT_DIR=/work$catalogEnv wp eval-file /tmp/setup-site.php"
docker compose exec -T cli sh -c $inner > $rawFile
if ($LASTEXITCODE -ne 0) { throw "wp eval-file failed. Raw output: $rawFile" }

$raw   = Get-Content $rawFile -Raw
$first = $raw.IndexOf("{")
$last  = $raw.LastIndexOf("}")
if ($first -lt 0 -or $last -lt 0) { throw "No JSON in the setup output. Raw output: $rawFile" }
$summary = $raw.Substring($first, $last - $first + 1) | ConvertFrom-Json

if ($summary.attribute_count -eq 0) {
    Write-Warning "No global attributes found - is the mu-plugin snippet installed?"
}
if (-not $summary.written.mapping) {
    Write-Warning "config/mapping.json was not written from the container. The JSON is in $rawFile."
}
if (-not $summary.written.categories) {
    Write-Warning "mock-ups/categories.json was not written from the container. The JSON is in $rawFile."
}

# Demo catalogue: placeholder images, products, pages, navigation menu, options.
$seedSummary = $null
if (-not $SkipCatalog -and (Test-Path $CatalogPath)) {
    Write-Host "==> Demo catalogue (images, products, pages, menu) ..." -ForegroundColor Cyan

    if (-not (Test-Path "assets/placeholders/placeholder-1.png")) {
        python scripts/make_placeholders.py
        if ($LASTEXITCODE -ne 0) { throw "scripts/make_placeholders.py failed." }
    }

    # Real product photographs (Wikimedia Commons, licensed) — the storefront's
    # cards, category tiles and hero banner use these instead of the placeholders.
    if (-not $SkipPhotos -and -not (Test-Path $PhotosPath)) {
        Write-Host "==> Product photographs (Wikimedia Commons) ..." -ForegroundColor Cyan
        python scripts/fetch_product_photos.py
        if ($LASTEXITCODE -ne 0) { Write-Warning "Photo download failed; products keep the generated placeholders." }
    }

    $placeholderIds = $null
    $storedIds = docker compose exec -T cli wp option get upscale_placeholder_ids 2>$null
    if ($storedIds) {
        $placeholderIds = @($storedIds | Where-Object { $_ -match '^\d+(,\d+)*$' }) | Select-Object -First 1
    }
    if (-not $placeholderIds) {
        $imported = docker compose exec -T cli sh -c "wp media import /work/assets/placeholders/placeholder-*.png --porcelain" 2>$null
        $ids = @($imported | Where-Object { $_ -match '^\d+$' })
        if ($ids.Count -eq 0) { throw "wp media import produced no attachment ids." }
        $placeholderIds = $ids -join ","
        docker compose exec -T cli wp option update upscale_placeholder_ids $placeholderIds | Out-Null
    }
    Write-Host "    placeholder attachments: $placeholderIds" -ForegroundColor DarkGray

    docker compose cp scripts/seed-catalog.php "cli:/tmp/seed-catalog.php"
    if ($LASTEXITCODE -ne 0) { throw "Could not copy scripts/seed-catalog.php into the cli container." }

    $seedRaw     = Join-Path $env:TEMP "upscale-seed-raw.txt"
    $seedInner   = "UPSCALE_CATALOG_FILE='/work/$catalogRel' UPSCALE_PLACEHOLDER_IDS='$placeholderIds' wp eval-file /tmp/seed-catalog.php"
    docker compose exec -T cli sh -c $seedInner > $seedRaw
    if ($LASTEXITCODE -ne 0) { Write-Warning "Seeding failed. Raw output: $seedRaw" }

    $seedText = Get-Content $seedRaw -Raw
    $seedFrom = $seedText.IndexOf("{")
    $seedTo   = $seedText.LastIndexOf("}")
    if ($seedFrom -ge 0 -and $seedTo -gt $seedFrom) {
        $seedSummary = $seedText.Substring($seedFrom, $seedTo - $seedFrom + 1) | ConvertFrom-Json
        Write-Host ("    products {0}, pages {1}, reviews {2}, menu items {3}" -f `
            $seedSummary.created.products, $seedSummary.created.pages, `
            $seedSummary.created.reviews, $seedSummary.created.menu_items) -ForegroundColor DarkGray
    } else {
        Write-Warning "No JSON in the seeding output. Raw output: $seedRaw"
    }

    # Photo pass: import the photographs, attach them to products/categories and
    # publish the attribution page the CC BY / CC BY-SA licences require.
    if (-not $SkipPhotos -and (Test-Path $PhotosPath)) {
        Write-Host "==> Photographs (media library, product images, attribution) ..." -ForegroundColor Cyan
        docker compose cp scripts/seed-photos.php "cli:/tmp/seed-photos.php"
        if ($LASTEXITCODE -ne 0) { throw "Could not copy scripts/seed-photos.php into the cli container." }

        $photosRel  = ($PhotosPath -replace '\\', '/')
        $photoRaw   = Join-Path $env:TEMP "upscale-photos-raw.txt"
        $photoInner = "UPSCALE_PHOTOS_FILE='/work/$photosRel' UPSCALE_PROJECT_DIR=/work wp eval-file /tmp/seed-photos.php"
        docker compose exec -T cli sh -c $photoInner > $photoRaw
        if ($LASTEXITCODE -ne 0) { Write-Warning "Photo pass failed. Raw output: $photoRaw" }

        $photoText = Get-Content $photoRaw -Raw
        $photoFrom = $photoText.IndexOf("{")
        $photoTo   = $photoText.LastIndexOf("}")
        if ($photoFrom -ge 0 -and $photoTo -gt $photoFrom) {
            $photoSummary = $photoText.Substring($photoFrom, $photoTo - $photoFrom + 1) | ConvertFrom-Json
            Write-Host ("    imported {0}, reused {1}, products linked {2}, categories linked {3}" -f `
                $photoSummary.imported.attachments, $photoSummary.imported.reused, `
                $photoSummary.imported.products_linked, $photoSummary.imported.categories_linked) -ForegroundColor DarkGray
        } else {
            Write-Warning "No JSON in the photo output. Raw output: $photoRaw"
        }
    } elseif (-not $SkipPhotos) {
        Write-Warning "No photo record at '$PhotosPath' - the storefront keeps the generated placeholders."
    }
}

$specIds          = ($summary.spec_example | ForEach-Object { $_.id }) -join ", "
$mappingPathForEnv = (Join-Path $root "config/mapping.json").Replace("\", "/")

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host "  Site URL   : $($summary.site_url)"
Write-Host "  Permalinks : $($summary.permalink)"
Write-Host "  Category   : $($summary.category.name) (id $($summary.category.id), slug $($summary.category.slug))"
Write-Host "  Categories : $($summary.category_count) in product_cat"
if ($seedSummary) {
    Write-Host "  Products   : $($seedSummary.products_total) published (demo)"
    Write-Host "  Storefront : $($seedSummary.home_url)"
}
Write-Host "  Attributes : $($summary.attribute_count) global"
Write-Host "  spec ids   : $specIds"
Write-Host "  Wrote      : mock-ups/categories.json=$($summary.written.categories)  config/mapping.json=$($summary.written.mapping)"
Write-Host ""
Write-Host "# ---- paste into D:\UpScale-Back-master\.env ------------------------------" -ForegroundColor Yellow
Write-Host "WC_API_URL=$($summary.site_url)/" -ForegroundColor Yellow
Write-Host "WC_API_KEY=$($summary.rest.consumer_key)" -ForegroundColor Yellow
Write-Host "WC_API_SECRET=$($summary.rest.consumer_secret)" -ForegroundColor Yellow
Write-Host "UPS_API_URL=http://localhost:9000" -ForegroundColor Yellow
Write-Host "UPS_API_KEY=mock-ups-key" -ForegroundColor Yellow
Write-Host "UPSCALE_MAPPING_FILE=$mappingPathForEnv" -ForegroundColor Yellow
Write-Host "# If the UpScale API runs inside Docker, use host.docker.internal instead of" -ForegroundColor DarkGray
Write-Host "# localhost for WC_API_URL / UPS_API_URL (README.md, 'Networking')." -ForegroundColor DarkGray
