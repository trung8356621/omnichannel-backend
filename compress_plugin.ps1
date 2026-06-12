# ==============================================================================
# WP PLUGIN PACKAGER & UPDATE SERVER BUILDER (WINDOWS POWERSHELL)
# ==============================================================================

# Normalizing input directories
$wpPluginDir = "D:\work\wp-seo-ai".TrimEnd("\")
$laravelTargetDir = "D:\work\omnichannel-backend\storage\app\public\plugins\omi-seo-ai-bridge".TrimEnd("\")
$pluginSlug = "omi-seo-ai-bridge"
$zipFolder = $pluginSlug # Outermost folder inside the zip file; must match installed plugin folder

# 1. Find main PHP file to read Metadata
$mainPluginFile = Join-Path $wpPluginDir "omi-seo-ai-bridge.php"
if (-not (Test-Path $mainPluginFile)) {
    # Fallback search for any php file containing "Plugin Name:"
    $mainPluginFile = Get-ChildItem -Path $wpPluginDir -Filter "*.php" -Recurse | Where-Object { 
        Select-String -Path $_.FullName -Pattern "Plugin Name:" -Quiet 
    } | Select-Object -First 1
}

if (-not $mainPluginFile) {
    Write-Error "Could not find main plugin file with Header info at $wpPluginDir"
    exit
}

# 2. Extract version from Plugin
$versionContent = Select-String -Path $mainPluginFile -Pattern "Version:\s*([0-9\.]+)"
if ($versionContent -and $versionContent.Matches.Groups[1].Value) {
    $version = $versionContent.Matches.Groups[1].Value
    Write-Host "--- DETECTED PLUGIN VERSION: $version ---" -ForegroundColor Green
} else {
    $version = "1.0.0"
    Write-Warning "Could not find 'Version:' line in main file. Defaulting to $version"
}

# Create Laravel target directory if not exists
if (-not (Test-Path $laravelTargetDir)) {
    New-Item -ItemType Directory -Path $laravelTargetDir -Force | Out-Null
}

# 3. Create temp build environment (to ensure standard WP zip structure)
$tempDir = Join-Path $env:TEMP "wp_plugin_build_$(Get-Random)"
$packageDir = Join-Path $tempDir $zipFolder
New-Item -ItemType Directory -Path $packageDir -Force | Out-Null

Write-Host "Copying clean source files..." -ForegroundColor Cyan

# List of files/directories to exclude
$excludeList = @(".git", ".github", ".gitattributes", ".gitignore", "node_modules", "tests", "phpunit.xml", "composer.json", "composer.lock", "package.json", "package-lock.json", "webpack.config.js")

# Get list of clean source files
$filesToCopy = Get-ChildItem -Path $wpPluginDir -Recurse | Where-Object {
    $relativePath = $_.FullName.Replace($wpPluginDir, "").TrimStart("\")
    if ([string]::IsNullOrEmpty($relativePath)) {
        return $false
    }
    
    $shouldExclude = $false
    foreach ($exclude in $excludeList) {
        if ($relativePath -eq $exclude -or $relativePath -like "$exclude\*" -or $relativePath -like "*\$exclude\*") {
            $shouldExclude = $true
            break
        }
    }
    -not $shouldExclude
}

# Copy files using a safe, explicit foreach loop to avoid Copy-Item parameter binding bugs
foreach ($item in $filesToCopy) {
    $relativePath = $item.FullName.Replace($wpPluginDir, "").TrimStart("\")
    $targetPath = Join-Path $packageDir $relativePath

    if ($item.PsIsContainer) {
        if (-not (Test-Path $targetPath)) {
            New-Item -ItemType Directory -Path $targetPath -Force | Out-Null
        }
    } else {
        $parentDir = Split-Path $targetPath
        if (-not (Test-Path $parentDir)) {
            New-Item -ItemType Directory -Path $parentDir -Force | Out-Null
        }
        Copy-Item -Path $item.FullName -Destination $targetPath -Force | Out-Null
    }
}

# 4. Zip the package with parent folder included (Using .NET manually to guarantee slash compatibility on Linux)
$zipFileName = "$pluginSlug-$version.zip"
$targetZipPath = Join-Path $laravelTargetDir $zipFileName

if (Test-Path $targetZipPath) {
    Remove-Item $targetZipPath -Force
}

Write-Host "Zipping package to $zipFileName..." -ForegroundColor Cyan

# Load .NET Compression Assembly
Add-Type -AssemblyName System.IO.Compression

# Initialize manual FileStream & ZipArchive to enforce slash-translation (No backward slashes!)
$zipStream = [System.IO.File]::Create($targetZipPath)
$archive = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

# Recursively get all copied files
$filesToZip = Get-ChildItem -Path $tempDir -Recurse | Where-Object { -not $_.PsIsContainer }

foreach ($file in $filesToZip) {
    # Calculate relative path from tempDir, and explicitly replace '\' with '/' 
    # to guarantee compatibility on DirectAdmin / Linux server unzipping.
    $relativePath = $file.FullName.Replace($tempDir, "").TrimStart("\").Replace("\", "/")
    
    # Create ZIP entry
    $entry = $archive.CreateEntry($relativePath, [System.IO.Compression.CompressionLevel]::Optimal)
    $entryStream = $entry.Open()
    
    # Copy file contents
    $fileStream = [System.IO.File]::OpenRead($file.FullName)
    $fileStream.CopyTo($entryStream)
    
    # Properly close and dispose handles
    $fileStream.Close()
    $fileStream.Dispose()
    $entryStream.Close()
    $entryStream.Dispose()
}

# Close ZIP Archive and Streams properly in order (ZipArchive MUST be disposed first to flush header footers)
$archive.Dispose()
$zipStream.Close()
$zipStream.Dispose()

# 5. Overwrite/Update info.json data on Laravel Update Server
$infoJsonPath = Join-Path $laravelTargetDir "info.json"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
if (Test-Path $infoJsonPath) {
    Write-Host "Syncing updates to info.json..." -ForegroundColor Cyan
    $infoJsonRaw = [System.IO.File]::ReadAllText($infoJsonPath, $utf8NoBom)
    $info = $infoJsonRaw | ConvertFrom-Json
    $info.version = $version
    $info.last_updated = (Get-Date -Format "yyyy-MM-dd HH:mm:ss")
    $json = $info | ConvertTo-Json -Depth 10
    [System.IO.File]::WriteAllText($infoJsonPath, $json, $utf8NoBom)
    Write-Host "Successfully synced info.json to version $version!" -ForegroundColor Green
} else {
    Write-Warning "Could not find info.json at $laravelTargetDir to auto-sync updates."
}

# Clean up temp files
Remove-Item -Path $tempDir -Recurse -Force

Write-Host "==========================================" -ForegroundColor Green
Write-Host "PACKAGED SUCCESSFULLY AND SAVED TO UPDATE SERVER!" -ForegroundColor Green
Write-Host "File path: $targetZipPath" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Green