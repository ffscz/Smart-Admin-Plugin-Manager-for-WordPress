param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
    [string]$OutputDir = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
)

$ErrorActionPreference = 'Stop'

$slug = 'smart-admin-plugin-manager'
$mainFile = Join-Path $ProjectRoot 'smart-admin-plugin-manager.php'

if (-not (Test-Path $mainFile)) {
    throw "Main plugin file not found: $mainFile"
}

$versionMatch = Select-String -Path $mainFile -Pattern "\* Version:\s*([0-9]+\.[0-9]+\.[0-9]+)" | Select-Object -First 1
if (-not $versionMatch) {
    throw 'Unable to parse plugin version from main plugin header.'
}
$version = $versionMatch.Matches[0].Groups[1].Value

$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("sapm-release-" + [System.Guid]::NewGuid().ToString('N'))
$stagingPluginRoot = Join-Path $stagingRoot $slug
New-Item -ItemType Directory -Path $stagingPluginRoot -Force | Out-Null

$topFiles = @(
    'CHANGELOG.md',
    'LICENSE',
    'README.md',
    'readme.txt',
    'smart-admin-plugin-manager.php',
    'uninstall.php'
)

$topDirs = @(
    'assets',
    'includes',
    'templates'
)

foreach ($file in $topFiles) {
    $src = Join-Path $ProjectRoot $file
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination (Join-Path $stagingPluginRoot $file) -Force
    }
}

foreach ($dir in $topDirs) {
    $src = Join-Path $ProjectRoot $dir
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination (Join-Path $stagingPluginRoot $dir) -Recurse -Force
    }
}

$zipPath = Join-Path $OutputDir "$slug.zip"
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $files = Get-ChildItem -Path $stagingPluginRoot -File -Recurse
    foreach ($file in $files) {
        $fullPath = $file.FullName
        if ($fullPath.StartsWith($stagingRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
            $relative = $fullPath.Substring($stagingRoot.Length) -replace '^[\\/]+', ''
        } else {
            throw "Unable to compute relative path for: $fullPath"
        }

        $entryName = $relative -replace '\\', '/'
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip,
            $file.FullName,
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    $zip.Dispose()
}

# Validate that all archive entry names use forward slashes.
$zipRead = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $invalid = $zipRead.Entries | Where-Object { $_.FullName -match '\\' }
    if ($invalid) {
        throw 'ZIP validation failed: at least one archive entry contains a backslash.'
    }
}
finally {
    $zipRead.Dispose()
}

$hash = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
$shaPath = Join-Path $OutputDir "$slug.sha256"
$shaContent = "$hash  $slug.zip"
Set-Content -Path $shaPath -Value $shaContent -NoNewline

Remove-Item -Path $stagingRoot -Recurse -Force

Write-Host "Built release assets for v$version"
Write-Host "ZIP:  $zipPath"
Write-Host "SHA:  $shaPath"
Write-Host "SHA256: $hash"
