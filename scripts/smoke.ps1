<#
.SYNOPSIS
  Runs one product through the UpScale pipeline against the test store.

.DESCRIPTION
  Prerequisite: the UpScale API is running with a .env that points at this test
  store (WC_API_URL, UPS_API_URL, UPSCALE_MAPPING_FILE) plus a real
  GPT_API_KEY and a Claid token that has credits — `process` fails without them.

  Stages: create -> collect -> process -> generate. `push` PUBLISHES to the test
  store and only runs with -Push, because publication requires explicit
  authorization (D1).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts/smoke.ps1 `
      -Api http://localhost:8000 `
      -Url https://competitor.example/product `
      -Images "https://competitor.example/1.jpg","https://competitor.example/2.jpg"

.EXAMPLE
  # Reuse an existing token and add the publish step:
  powershell -ExecutionPolicy Bypass -File scripts/smoke.ps1 -Token $jwt -Url ... -Push
#>
[CmdletBinding()]
param(
    [string]   $Api        = "http://localhost:8000",
    [string]   $Token      = "",
    [string]   $Email      = "upscale-test@example.com",
    [string]   $Password   = "test12345",
    [Parameter(Mandatory = $true)][string] $Url,
    [string[]] $Images     = @(),
    [int]      $CategoryId = 0,
    [switch]   $Push
)

$ErrorActionPreference = "Continue"
$api = $Api.TrimEnd("/")

function Invoke-Upscale {
    param([string]$Method, [string]$Path, $Body)
    $headers = @{ "Accept" = "application/json" }
    if ($Token) { $headers["Authorization"] = "Bearer $Token" }
    $req = @{ Method = $Method; Uri = "$api$Path"; Headers = $headers; TimeoutSec = 600 }
    if ($Body) {
        $req["Body"] = ($Body | ConvertTo-Json -Depth 6 -Compress)
        $req["ContentType"] = "application/json"
    }
    try {
        return @{ ok = $true; data = Invoke-RestMethod @req }
    } catch {
        $detail = ""
        if ($_.ErrorDetails) { $detail = $_.ErrorDetails.Message }
        return @{ ok = $false; error = "$($_.Exception.Message) $detail" }
    }
}

function Step {
    param([string]$Label, [hashtable]$Result, [string]$Field = "")
    if ($Result.ok) {
        $extra = ""
        if ($Field -and $Result.data.$Field) { $extra = " -> $($Result.data.$Field)" }
        Write-Host ("  {0,-22} OK{1}" -f $Label, $extra) -ForegroundColor Green
        return $true
    }
    Write-Host ("  {0,-22} FAIL {1}" -f $Label, $Result.error) -ForegroundColor Red
    return $false
}

Write-Host "==> UpScale API at $api" -ForegroundColor Cyan
$health = Invoke-Upscale -Method GET -Path "/"
if (-not (Step "API reachable" $health "message")) { exit 1 }

if (-not $Token) {
    Write-Host "==> Auth" -ForegroundColor Cyan
    Invoke-Upscale -Method POST -Path "/auth/signup" -Body @{ email = $Email; password = $Password } | Out-Null
    $login = Invoke-Upscale -Method POST -Path "/auth/login" -Body @{ email = $Email; password = $Password }
    if (-not (Step "login" $login)) { exit 1 }
    $Token = $login.data.access_token
}

Write-Host "==> Categories (via the UPS mock)" -ForegroundColor Cyan
$cats = Invoke-Upscale -Method GET -Path "/categories/get_categories"
if (-not (Step "get_categories" $cats)) { exit 1 }
$list = @($cats.data)
if ($list.Count -eq 0) { Write-Host "  no categories returned by the mock" -ForegroundColor Red; exit 1 }

$target = $list | Where-Object { $CategoryId -eq 0 -or $_.id -eq $CategoryId } | Select-Object -First 1
if (-not $target) { Write-Host "  category id $CategoryId not found" -ForegroundColor Red; exit 1 }
Write-Host ("  using category {0} (id {1})" -f $target.name, $target.id) -ForegroundColor DarkGray

$detail = Invoke-Upscale -Method GET -Path "/categories/get_category?category_id=$($target.id)"
if (-not (Step "get_category (icp/keywords)" $detail)) { exit 1 }

Write-Host "==> Pipeline (draft)" -ForegroundColor Cyan
$create = Invoke-Upscale -Method POST -Path "/new_products/create" -Body @{
    url = $Url; category_id = $target.id; images = @($Images)
}
if (-not (Step "create (status 0)" $create "id")) { exit 1 }
$productId = $create.data.id

foreach ($stage in @("collect", "process", "generate")) {
    $body = @{ product_id = $productId }
    if ($stage -eq "collect") { $body["draft"] = $true }
    $res = Invoke-Upscale -Method POST -Path "/new_products/$stage" -Body $body
    if (-not (Step $stage $res)) {
        Write-Host "  stopping: the $stage stage failed (status was not advanced)." -ForegroundColor Yellow
        exit 1
    }
}

$all = Invoke-Upscale -Method GET -Path "/new_products/data_all?product_id=$productId"
if (Step "data_all" $all) { Write-Host ("    " + ($all.data | ConvertTo-Json -Depth 4 -Compress).Substring(0, [Math]::Min(600, ($all.data | ConvertTo-Json -Depth 4 -Compress).Length))) -ForegroundColor DarkGray }

if ($Push) {
    Write-Host "==> push (PUBLISHES to the test store)" -ForegroundColor Yellow
    $res = Invoke-Upscale -Method POST -Path "/new_products/push" -Body @{ product_id = $productId }
    if (-not (Step "push (status 4)" $res)) { exit 1 }
    Write-Host "  Inspect the product in WooCommerce admin." -ForegroundColor DarkGray
} else {
    Write-Host ""
    Write-Host "Stopped before push. Re-run with -Push to publish this product (test store only)." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Product id: $productId" -ForegroundColor Green
