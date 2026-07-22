param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot

function Read-RepoFile {
    param([Parameter(Mandatory = $true)][string]$RelativePath)
    return [System.IO.File]::ReadAllText((Join-Path $repoRoot $RelativePath))
}

function Assert-Contains {
    param(
        [Parameter(Mandatory = $true)][string]$Content,
        [Parameter(Mandatory = $true)][string]$Needle,
        [Parameter(Mandatory = $true)][string]$Message
    )
    if (-not $Content.Contains($Needle)) {
        throw "MISSING: $Message ($Needle)"
    }
}

function Assert-NotContains {
    param(
        [Parameter(Mandatory = $true)][string]$Content,
        [Parameter(Mandatory = $true)][string]$Needle,
        [Parameter(Mandatory = $true)][string]$Message
    )
    if ($Content.Contains($Needle)) {
        throw "UNEXPECTED CONTENT: $Message ($Needle)"
    }
}

function Assert-OccurrenceCount {
    param(
        [Parameter(Mandatory = $true)][string]$Content,
        [Parameter(Mandatory = $true)][string]$Needle,
        [Parameter(Mandatory = $true)][int]$Expected,
        [Parameter(Mandatory = $true)][string]$Message
    )
    $actual = ([regex]::Matches($Content, [regex]::Escape($Needle))).Count
    if ($actual -ne $Expected) {
        throw "WRONG OCCURRENCE COUNT: $Message - expected: $Expected, actual: $actual ($Needle)"
    }
}

$plugin = Read-RepoFile 'mockup-generator.php'
$googleTracking = Read-RepoFile 'includes/class-google-ads-tracking.php'
$googleSettings = Read-RepoFile 'includes/class-google-ads-settings.php'
$metaPixel = Read-RepoFile 'includes/class-facebook-pixel.php'
$metaReliability = Read-RepoFile 'includes/class-facebook-pixel-reliability.php'
$metaSettings = Read-RepoFile 'includes/class-facebook-pixel-settings.php'
$virtualVariantDisplay = Read-RepoFile 'assets/js/virtual-variant-display.js'

$headerVersion = [regex]::Match($plugin, '(?m)^Version:\s*([^\r\n]+)').Groups[1].Value.Trim()
$constantVersion = [regex]::Match($plugin, 'define\(''MG_VERSION'',\s*''([^'']+)''\)').Groups[1].Value
if ($headerVersion -eq '' -or $headerVersion -ne $constantVersion) {
    throw "Plugin header and constant versions do not match: '$headerVersion' / '$constantVersion'."
}

Assert-Contains $googleTracking "gtag('set', 'url_passthrough', true)" 'Consent Mode URL passthrough'
Assert-Contains $googleTracking 'typeMeta.colors[colorSlug].surcharge' 'Google AddToCart color surcharge'
Assert-Contains $googleTracking 'typeMeta.size_surcharges[sizeValue]' 'Google AddToCart size surcharge'
Assert-Contains $googleTracking "'region' => `$region" 'Google Enhanced Conversions region'
Assert-OccurrenceCount $googleTracking "window.gtag('event', 'add_to_cart'" 1 'single Google AddToCart sender'
Assert-NotContains $virtualVariantDisplay "window.gtag('event', 'add_to_cart'" 'duplicate virtual Google AddToCart event'
Assert-Contains $googleSettings '_mg_gads_browser_rendered' 'Google browser diagnostics'

Assert-Contains $metaPixel 'build_advanced_matching(self::get_received_order())' 'Meta Advanced Matching in initial init'
Assert-OccurrenceCount $metaPixel "fbq('init'" 1 'single Meta Pixel initialization'
Assert-Contains $metaPixel '_mgFbColorSurcharges' 'Meta AddToCart color surcharge'
Assert-Contains $metaReliability "'st' => self::normalize_text" 'Meta CAPI region match field'
Assert-Contains $metaReliability "'wc_guest_' . `$email" 'Meta CAPI stable guest external_id'
Assert-Contains $metaSettings '_mg_fb_browser_rendered' 'Meta browser diagnostics'

$previousErrorPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$diffCheck = & git -C $repoRoot diff --check 2>&1
$diffCheckExitCode = $LASTEXITCODE
$ErrorActionPreference = $previousErrorPreference
if ($diffCheckExitCode -ne 0) {
    throw "git diff --check failed:`n$($diffCheck -join "`n")"
}

Write-Host "Static tracking check: OK (plugin $headerVersion)" -ForegroundColor Green
Write-Host 'Google AddToCart senders: 1; Meta Pixel init calls: 1'
