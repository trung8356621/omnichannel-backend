# ==============================================================================
# WP PLUGIN PACKAGER & UPDATE SERVER BUILDER (WINDOWS POWERSHELL)
# ==============================================================================

$wpPluginDir = "C:\work\wp-seo-ai"
$laravelTargetDir = "C:\work\omnichannel-backend\storage\app\public\plugins\omi-seo-ai-bridge"
$pluginSlug = "omi-seo-ai-bridge"
$zipFolder = "wp-seo-ai" # Outermost folder inside the zip file for standard WP structure

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
    $relativePath = $_.FullName.Substring($wpPluginDir.Length + 1)
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
    $relativePath = $item.FullName.Substring($wpPluginDir.Length + 1)
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

# 4. Zip the package with parent folder included
$zipFileName = "$pluginSlug-$version.zip"
$targetZipPath = Join-Path $laravelTargetDir $zipFileName

if (Test-Path $targetZipPath) {
    Remove-Item $targetZipPath -Force
}

Write-Host "Zipping package to $zipFileName..." -ForegroundColor Cyan

# Navigate to temp build directory and zip the folder itself to include the "wp-seo-ai" root folder
Push-Location $tempDir
Compress-Archive -Path ".\$zipFolder" -DestinationPath $targetZipPath -Force
Pop-Location

# 5. Overwrite/Update info.json data on Laravel Update Server
$infoJsonPath = Join-Path $laravelTargetDir "info.json"
if (Test-Path $infoJsonPath) {
    Write-Host "Syncing updates to info.json..." -ForegroundColor Cyan
    $info = Get-Content -Path $infoJsonPath -Raw | ConvertFrom-Json
    $info.version = $version
    $info.last_updated = (Get-Date -Format "yyyy-MM-dd HH:mm:ss")
    $info | ConvertTo-Json -Depth 10 | Set-Content -Path $infoJsonPath -Encoding utf8
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