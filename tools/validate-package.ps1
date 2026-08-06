$ErrorActionPreference = "Stop"
$projectRoot = Split-Path -Parent $PSScriptRoot
$required = @(
    "pkg_aifriendly.xml",
    "src/library/aifriendly.xml",
    "src/component/aifriendly.xml",
    "src/plugins/system/aifriendly.xml",
    "src/plugins/task/aifriendly.xml"
)

foreach ($relative in $required) {
    $path = Join-Path $projectRoot $relative
    if (-not (Test-Path -LiteralPath $path)) { throw "Missing $relative" }
    [xml](Get-Content -LiteralPath $path -Raw) | Out-Null
}

# The version is written by hand in ten places. A release that disagrees with
# itself installs badly and is hard to diagnose afterwards, so it fails here.
$expected = ([xml](Get-Content -LiteralPath (Join-Path $projectRoot "pkg_aifriendly.xml") -Raw)).extension.version
Write-Output "Declared version: $expected"

$manifests = @(
    "src/library/aifriendly.xml",
    "src/component/aifriendly.xml",
    "src/plugins/system/aifriendly.xml",
    "src/plugins/content/aifriendly.xml",
    "src/plugins/task/aifriendly.xml"
)
foreach ($relative in $manifests) {
    $found = ([xml](Get-Content -LiteralPath (Join-Path $projectRoot $relative) -Raw)).extension.version
    if ($found -ne $expected) { throw "Version mismatch in ${relative}: $found, expected $expected" }
}

$literals = @{
    "src/component/media/joomla.asset.json"                     = """version"": ""$expected"""
    "src/component/administrator/src/View/Hub/HtmlView.php"     = "VERSION = '$expected'"
    "src/plugins/system/src/Extension/AiFriendly.php"           = "VERSION = '$expected'"
}
foreach ($relative in $literals.Keys) {
    $content = Get-Content -LiteralPath (Join-Path $projectRoot $relative) -Raw
    if (-not $content.Contains($literals[$relative])) { throw "Version mismatch in ${relative}: expected $expected" }
}
Write-Output "Version is consistent across manifests and code"

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    Get-ChildItem -LiteralPath $projectRoot -Recurse -File -Filter "*.php" | ForEach-Object {
        & $php.Source -l $_.FullName
        if ($LASTEXITCODE -ne 0) { throw "PHP syntax failure: $($_.FullName)" }
    }
} else {
    Write-Warning "php executable not found; syntax lint skipped"
}

& (Join-Path $PSScriptRoot "build.ps1")
$package = Join-Path $projectRoot "dist/pkg_aifriendly.zip"
if (-not (Test-Path -LiteralPath $package)) { throw "Package was not built" }
Write-Output "Package validation completed: $package"

