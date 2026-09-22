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
    [string]$SnippetPath    = "../UpScale-Back-master/snippets/upscale-woocommerce.php"
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
$inner   = "UPSCALE_TEST_CATEGORY='$CategoryName' UPSCALE_TEST_CATEGORY_SLUG='$CategorySlug' UPSCALE_ADMIN_LOGIN='$AdminUser' UPSCALE_GOOGLE_CATEGORY='$GoogleCategory' UPSCALE_OUT_DIR=/work wp eval-file /tmp/setup-site.php"
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

$specIds          = ($summary.spec_example | ForEach-Object { $_.id }) -join ", "
$mappingPathForEnv = (Join-Path $root "config/mapping.json").Replace("\", "/")

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host "  Site URL   : $($summary.site_url)"
Write-Host "  Permalinks : $($summary.permalink)"
Write-Host "  Category   : $($summary.category.name) (id $($summary.category.id), slug $($summary.category.slug))"
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
