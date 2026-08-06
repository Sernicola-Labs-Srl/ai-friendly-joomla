param(
    [string]$Version = ""
)

$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$projectRoot = Split-Path -Parent $PSScriptRoot
if (-not $Version) { $Version = ([xml](Get-Content -LiteralPath (Join-Path $projectRoot "pkg_aifriendly.xml") -Raw)).extension.version }
$buildRoot = Join-Path $projectRoot "build"
$distRoot = Join-Path $projectRoot "dist"

if (Test-Path $buildRoot) { Remove-Item -LiteralPath $buildRoot -Recurse -Force }
if (Test-Path $distRoot) { Remove-Item -LiteralPath $distRoot -Recurse -Force }
New-Item -ItemType Directory -Path $buildRoot, $distRoot | Out-Null

function New-ZipFromDirectory {
    param([string]$Source, [string]$Destination)
    if (Test-Path -LiteralPath $Destination) { Remove-Item -LiteralPath $Destination -Force }
    $archive = [System.IO.Compression.ZipFile]::Open($Destination, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem -LiteralPath $Source -Recurse -Directory | ForEach-Object {
            $relative = $_.FullName.Substring($Source.Length).TrimStart('\', '/').Replace('\', '/') + '/'
            if ($relative -ne '/') { $archive.CreateEntry($relative) | Out-Null }
        }
        Get-ChildItem -LiteralPath $Source -Recurse -File | ForEach-Object {
            $relative = $_.FullName.Substring($Source.Length).TrimStart('\', '/').Replace('\', '/')
            $entry = $archive.CreateEntry($relative, [System.IO.Compression.CompressionLevel]::Optimal)
            $inputStream = [System.IO.File]::OpenRead($_.FullName)
            $outputStream = $entry.Open()
            try { $inputStream.CopyTo($outputStream) }
            finally { $outputStream.Dispose(); $inputStream.Dispose() }
        }
    } finally {
        $archive.Dispose()
    }
}

$extensions = @(
    @{ Source = "src/library"; Output = "lib_aifriendly.zip" },
    @{ Source = "src/component"; Output = "com_aifriendly.zip" },
    @{ Source = "src/plugins/system"; Output = "plg_system_aifriendly.zip" },
    @{ Source = "src/plugins/content"; Output = "plg_content_aifriendly.zip" },
    @{ Source = "src/plugins/task"; Output = "plg_task_aifriendly.zip" }
)

foreach ($extension in $extensions) {
    $sourcePath = Join-Path $projectRoot $extension.Source
    $outputPath = Join-Path $buildRoot $extension.Output
    New-ZipFromDirectory -Source $sourcePath -Destination $outputPath
}

$packageStage = Join-Path $buildRoot "package"
New-Item -ItemType Directory -Path $packageStage | Out-Null
Copy-Item -LiteralPath (Join-Path $projectRoot "pkg_aifriendly.xml") -Destination $packageStage
Copy-Item -LiteralPath (Join-Path $projectRoot "script.php") -Destination $packageStage
Copy-Item -LiteralPath (Join-Path $projectRoot "README.md") -Destination $packageStage
Copy-Item -LiteralPath (Join-Path $projectRoot "README.it.md") -Destination $packageStage
Copy-Item -LiteralPath (Join-Path $projectRoot "CHANGELOG.md") -Destination $packageStage
Get-ChildItem -LiteralPath $buildRoot -Filter "*.zip" | Copy-Item -Destination $packageStage

$packagePath = Join-Path $distRoot "pkg_aifriendly.zip"
New-ZipFromDirectory -Source $packageStage -Destination $packagePath
Write-Output "Built $packagePath ($Version)"
