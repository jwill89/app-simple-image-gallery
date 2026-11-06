#!/usr/bin/env pwsh
#Requires -Version 7.4

<#
.SYNOPSIS
    Build and deploy the Gallery (Laravel backend + Vue SPA) to the DigitalOcean droplet.

.DESCRIPTION
    Built on shared-tooling's SharedDeploy module (shared/deploy/pwsh), the shape every
    deploy follows: one archive, one held SSH connection, one remote run.

    The droplet runs the Laravel app from the gallery's domain folder (AppRoot),
    whose public/ is Apache's DocumentRoot. The database, media and duplicate
    reports sit beside the code in that folder (db/, media/, dupes/, at the paths
    its .env names), as do backups/ and slim-rollback/. A deploy ships and
    replaces code only, and never touches those.

    Steps:
      1. Build the frontend (vue-tsc + vite) -> frontend/dist  (skip with -SkipBuild).
      2. Stage and pack the payload, code only, as one archive:
           from backend/  -> app/, bootstrap/ (not its cache), config/, database/
                             (not a local gallery.db), public/, routes/, artisan,
                             composer.json, composer.lock, openapi.json
           from frontend/ -> dist/* into public/, except index.html, which goes to
                             resources/spa/index.html, where the SPA shell reads it
         Never .env, storage/, vendor/ or bootstrap/cache/.
      3. Open one SSH connection, held for the whole run, and upload the archive.
      4. On the host, in one remote run, once the archive's SHA-256 and file count check:
           - build the new code whole in .deploy-next: unpacked, vendor/ copied from
             the live code, composer install --no-dev     (skip with -SkipComposer),
             the public/media link copied, all of it given to www-data,
           - swap it in by renames, moving the previous code to .deploy-backup
             (kept afterwards unless -NoBackup), then package:discover; the site
             runs the old code or the new, never a half-replaced folder,
           - snapshot the database next to it in .backups/ via SQLite VACUUM INTO,
             keeping the five most recent                  (skip with -NoBackup),
           - php artisan migrate --force                   (skip with -SkipMigrate),
           - storage:link (public/media), optimize, queue:restart. Neither storage/
             nor the .env file is touched.
         If a step fails before the swap, the live code was never touched; after it,
         the previous CODE is moved back automatically. The database is NOT rolled
         back automatically: the failure trap fires on any non-zero exit, including
         after a migration has already succeeded, so reverting data could discard
         media uploaded during the deploy. A failed migration prints the snapshot
         path and the exact restore command.
      5. Fetch the health URL and the site from here, through Cloudflare. If either
         fails, the previous code goes back (the database stays as it is), unless
         -NoBackup left nothing to go back to.
      6. Check the daily backup (shared-tooling's Install-BackupTimer): a systemd timer
         that runs as www-data at 10:00 UTC and copies the database with VACUUM INTO to
         db/backups/gallery-YYYY-MM-DD.db, integrity-checked, keeping 28 days. Those sit
         apart from step 4's snapshots in db/.backups/. If the timer is already as it
         should be, nothing changes; if it is new or changed, it runs once first, and a
         failed run puts the previous timer back.
      7. Pull a copy of the live database to the local one backend/.env names
         (DB_DATABASE), keeping the three previous local copies     (skip with -NoDbPull).
         It is skipped, with a warning, while the local database is in use.
    Steps 6 and 7 run only once the site checks pass, so a deploy that rolled back
    changes neither.

    The first deploy to a new AppRoot needs its .env first (the plan's Deployment
    section, docs/laravel-migration.md); this script refuses to run without one.

    Uses PuTTY's plink (0.85 or later) so the DigitalOcean .ppk key works directly.
    It runs in batch mode, so a passphrase-protected .ppk must be loaded into
    Pageant first:
        pageant.exe "<KeyPath>"   (enter the passphrase once per Windows session).

    The droplet runs `ufw limit ssh` (drops the IP after ~6 connections in 30s); the
    whole deploy rides one held connection, plus one only if it drops and reconnects.

.PARAMETER VpsHost
    Droplet IP/hostname. Resolved from -VpsHost, then $env:DEPLOY_VPS_HOST, then scripts/deploy.config.ps1 ($DeployVpsHost).
.PARAMETER VpsUser
    SSH user. Resolved from -VpsUser, then $env:DEPLOY_VPS_USER, then $DeployVpsUser.
.PARAMETER KeyPath
    PuTTY .ppk private key. Resolved from -KeyPath, then $env:DEPLOY_KEY_PATH, then $DeployKeyPath.
.PARAMETER AppRoot
    The Laravel app's folder on the host (its public/ is the DocumentRoot). Resolved from -AppRoot, then $env:DEPLOY_APP_ROOT, then $DeployAppRoot.
.PARAMETER HealthUrl
    URL fetched after the deploy. Default: https://<PublicHost>/api/media/count.
.PARAMETER PublicHost
    The site's public host name.
.PARAMETER SkipBuild
    Deploy the existing frontend/dist without rebuilding.
.PARAMETER SkipComposer
    Don't run `composer install` on the host.
.PARAMETER SkipMigrate
    Don't run database migrations on the host.
.PARAMETER NoBackup
    Don't keep the previous code in .deploy-backup once the deploy succeeds (it is still put back on a failure), or snapshot the database. The daily backup is unaffected.
.PARAMETER NoDbPull
    Don't pull a copy of the live database to the local one.

.EXAMPLE
    .\scripts\deploy.ps1                       # full build + deploy + composer + migrate
.EXAMPLE
    .\scripts\deploy.ps1 -SkipBuild            # redeploy current dist without rebuilding
.EXAMPLE
    .\scripts\deploy.ps1 -SkipMigrate -SkipComposer   # code-only push
#>
[CmdletBinding()]
param(
    # Server-specific settings are NOT baked into this (tracked) script. Each is
    # resolved from its -param, then $env:DEPLOY_*, then the untracked
    # scripts/deploy.config.ps1. See deploy.config.example.ps1.
    [string]$VpsHost,
    [string]$VpsUser,
    [string]$KeyPath,
    [string]$AppRoot,
    [string]$PublicHost = 'gallery.mathdad.me',
    [string]$HealthUrl,
    [switch]$SkipBuild,
    [switch]$SkipComposer,
    [switch]$SkipMigrate,
    [switch]$NoBackup,
    [switch]$NoDbPull
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
Import-Module (Join-Path $PSScriptRoot '..\shared\deploy\pwsh\SharedDeploy.psd1') -Force
trap { Close-DeployConnection; Write-Host "`nERROR: $($_.Exception.Message)" -ForegroundColor Red; exit 1 }

$settings = Resolve-DeploySetting -ConfigPath (Join-Path $PSScriptRoot 'deploy.config.ps1') `
    -Value @{ VpsHost = $VpsHost; VpsUser = $VpsUser; KeyPath = $KeyPath; AppRoot = $AppRoot } `
    -Required VpsHost, VpsUser, KeyPath, AppRoot
$appRoot = $settings.AppRoot
if (-not $HealthUrl) { $HealthUrl = "https://$PublicHost/api/media/count" }

$repoRoot = Split-Path $PSScriptRoot -Parent
$backendDir = Join-Path $repoRoot 'backend'
$frontendDir = Join-Path $repoRoot 'frontend'
$distDir = Join-Path $frontendDir 'dist' # vite outDir is frontend/dist

# The local database the pull refreshes: the one backend/.env names, which the app reads.
# backend/database/gallery.db is gitignored for it, and the deploy never packs it.
$devDatabase = $null
if (-not $NoDbPull) {
    $envPath = Join-Path $backendDir '.env'
    $line = if (Test-Path -LiteralPath $envPath) { Get-Content -LiteralPath $envPath | Where-Object { $_ -match '^DB_DATABASE=' } | Select-Object -First 1 }
    $value = if ($line) { ($line -replace '^DB_DATABASE=', '').Trim() -replace '^["'']|["'']$', '' }
    if ($value -and [IO.Path]::IsPathRooted($value)) {
        $devDatabase = $value
    } else {
        Write-Warning 'backend/.env names no absolute DB_DATABASE, so the live database will not be pulled. Point it at backend/database/gallery.db to have each deploy refresh that copy.'
    }
}

# The code, by its top-level entries: what the archive carries, and vendor/, which the
# host builds beside it. The new set is built whole and swapped in by renames, so the
# site runs either the old code or the new: never a half-replaced folder, which failed
# every request for the 10-20 s that composer install, the migration and optimize took.
$shipped = 'app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'artisan', 'composer.json', 'composer.lock', 'openapi.json'
$code = @($shipped) + 'vendor'
$queueRestart = 'php artisan queue:restart >/dev/null 2>&1 || true'

Write-Host "Deploying Gallery -> $($settings.VpsUser)@$($settings.VpsHost):$appRoot" -ForegroundColor Yellow

# 1. Build the frontend
if ($SkipBuild) {
    Write-Step 'Skipping frontend build (-SkipBuild); using existing dist/'
} else {
    Invoke-NpmBuild -Path $frontendDir -Output (Join-Path $distDir 'index.html')
}
if (-not (Test-Path (Join-Path $distDir 'index.html'))) {
    throw 'frontend/dist/index.html is missing - nothing to deploy. (Run without -SkipBuild.)'
}

# 2. Stage and pack the payload (code only; never data)
Write-Step 'Packing the deploy...'
$stage = Join-Path ([IO.Path]::GetTempPath()) ('gallery-deploy-{0}' -f [guid]::NewGuid())
New-Item -ItemType Directory -Path $stage | Out-Null
try {
    foreach ($p in $shipped | Where-Object { $_ -ne 'resources' }) {
        $source = Join-Path $backendDir $p
        if (-not (Test-Path $source)) { throw "Expected path missing: backend/$p" }
        Copy-Item -Path $source -Destination $stage -Recurse
    }
    # What a local run leaves behind: cached config and package lists (they name
    # dev packages the host doesn't install), a local database, the media link.
    Get-ChildItem (Join-Path $stage 'bootstrap/cache') -Filter '*.php' | Remove-Item -Force
    Get-ChildItem (Join-Path $stage 'database') -Filter 'gallery.db*' | Remove-Item -Force
    foreach ($link in @('public/media', 'public/storage')) {
        $path = Join-Path $stage $link
        if (Test-Path $path) { Remove-Item $path -Recurse -Force }
    }
    # The built SPA: assets into public/, its index.html where SpaController reads it.
    Get-ChildItem $distDir | Where-Object { $_.Name -ne 'index.html' } | Copy-Item -Destination (Join-Path $stage 'public') -Recurse
    New-Item -ItemType Directory -Path (Join-Path $stage 'resources/spa') | Out-Null
    Copy-Item (Join-Path $distDir 'index.html') (Join-Path $stage 'resources/spa/index.html')

    $archive = Compress-DeployArchive -Root $stage -Item $shipped
} finally {
    Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Ok ('{0} files, {1:N1} MB, one archive' -f $archive.FileCount, ((Get-Item $archive.Path).Length / 1MB))

# 3. The host's steps. They run from AppRoot, with $stage (.deploy-next) and $backup
# (.deploy-backup) set by the module.

if ($SkipComposer) {
    $composer = "echo '==> -SkipComposer: skipping composer install'"
} else {
    # --no-scripts: its one hook, package:discover, boots the app, which needs the live
    # .env and storage/. It runs after the swap.
    $composer = @'
echo '==> composer install (no dev packages)'
composer install --working-dir="$stage" --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
'@
}

$prepare = Format-RemoteScript -Value @{ COMPOSER = $composer } -Template @'
if [ ! -f .env ]; then echo "$root/.env is missing. Create it before the first deploy (docs/laravel-migration.md, Deployment)." >&2; exit 2; fi
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$stage/bootstrap/cache"
rm -f "$stage"/bootstrap/cache/*.php
# Composer starts from the live vendor/, so it only applies what changed.
if [ -d vendor ]; then cp -a vendor "$stage/vendor"; fi
# The links storage:link made (public/media), so thumbnails are served the moment the
# new public/ is in.
find public -maxdepth 1 -type l -exec cp -P {} "$stage/public/" \; 2>/dev/null || true
mkdir -p storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
__COMPOSER__
# Owned by www-data before it goes live, so Laravel can write bootstrap/cache.
chown -R www-data:www-data "$stage"
'@

if ($SkipMigrate) {
    $migrate = "echo '==> -SkipMigrate: skipping database migrations'"
} else {
    # Snapshot the database immediately before anything can modify it.
    #
    # `cp gallery.db` is NOT safe: the connection runs in WAL mode, so a plain copy
    # misses whatever is still in the -wal file. `VACUUM INTO` is atomic and WAL-aware,
    # and reachable from PDO, so this needs no sqlite3 binary.
    #
    # The snapshot is deliberately NOT restored by the failure trap, which fires on
    # *any* non-zero exit, including after a successful migration: rolling the database
    # back would discard media uploaded during the deploy window. Rolling data back is a
    # decision, so the path and command are printed.
    if ($NoBackup) {
        $snapshot = @'
echo '==> -NoBackup: skipping database snapshot'
DB_SNAPSHOT=""
'@
    } else {
        $snapshot = @'
DB=$(grep -E '^DB_DATABASE=' .env | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//')
DB_SNAPSHOT=""
if [ -n "$DB" ] && [ -f "$DB" ]; then
  DB_SNAPSHOT="$(dirname "$DB")/.backups/gallery-$(date +%Y%m%d-%H%M%S).db"
  mkdir -p "$(dirname "$DB_SNAPSHOT")"
  echo '==> snapshotting the database before migrating'
  php -r '$pdo = new PDO("sqlite:" . $argv[1]); $pdo->exec("VACUUM INTO " . $pdo->quote($argv[2]));' "$DB" "$DB_SNAPSHOT" \
    || { echo 'The database snapshot failed; stopping before the migration.' >&2; exit 4; }
  echo "    snapshot: $DB_SNAPSHOT ($(du -h "$DB_SNAPSHOT" | cut -f1))"
  # Keep the five most recent; these are full copies of the database.
  ls -1t "$(dirname "$DB_SNAPSHOT")"/gallery-*.db 2>/dev/null | tail -n +6 | xargs -r rm -f
fi
'@
    }
    $migrate = $snapshot + "`n" + @'
echo '==> php artisan migrate'
if ! php artisan migrate --force --no-interaction; then
  echo 'The migrations failed.' >&2
  if [ -n "$DB_SNAPSHOT" ]; then
    echo '  Restore the database with:' >&2
    echo "    cp -a $DB_SNAPSHOT $DB && rm -f $DB-wal $DB-shm && chown www-data:www-data $DB" >&2
  fi
  exit 5
fi
'@
}

$afterSwap = Format-RemoteScript -Value @{ MIGRATE = $migrate } -Template @'
php artisan package:discover --ansi
__MIGRATE__
echo '==> storage:link, optimize, queue:restart'
# The media link came over with the new code; this makes it on a first deploy.
[ -L public/media ] || php artisan storage:link --no-interaction
# Config, routes and events; the app has no Blade views to cache.
php artisan optimize --except=views
php artisan queue:restart
# What the artisan runs above wrote as root. Not the whole folder: the data, backups/
# and slim-rollback/ sit beside the code and keep their own owners.
chown -R www-data:www-data bootstrap storage
'@

try {
    $connection = $settings | Open-DeployConnection
    Write-Step 'Uploading...'
    $upload = Invoke-Upload -Path $archive.Path -Destination 'gallery-deploy.tgz' -Activity 'Uploading the Gallery' -FileCount $archive.FileCount
    if (-not $upload.Ok) { throw "Upload failed; the live site is untouched.`n$($upload.Err)" }

    Write-Step 'Running the deploy on the host (code -> composer -> swap -> migrate -> optimize)...'
    Switch-RemoteItemSet -Archive 'gallery-deploy.tgz' -Sha256 $archive.Sha256 -ExpectedFileCount $archive.FileCount `
        -Root $appRoot -Item $code -Prepare $prepare -AfterSwap $afterSwap -OnRestore $queueRestart -NoBackup:$NoBackup

    Write-Step "Checking $PublicHost..."
    if ($NoBackup) {
        # Nothing was kept to go back to.
        Invoke-SmokeCheck -Url $HealthUrl, "https://$PublicHost/" -Attempts 3 -DelaySeconds 2 -NoRollback
    } else {
        # The previous code goes back; the database stays as it is, as above.
        Invoke-SmokeCheck -Url $HealthUrl, "https://$PublicHost/" -Attempts 3 -DelaySeconds 2 -Rollback {
            Restore-RemoteItemSet -Root $appRoot -Item $code -OnRestore $queueRestart
        }
    }

    # From here the new code is live and stays; these keep the data safe. The live database
    # is the one the host's .env names.
    $database = @(Invoke-Remote (Format-RemoteScript -Value @{ ENV = "$appRoot/.env" } -Template @'
grep -E '^DB_DATABASE=' "__ENV__" | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
'@))[0]
    if (-not $database) { throw "The Gallery is deployed and live, but $appRoot/.env names no DB_DATABASE, so its daily backup was not checked." }

    # The daily copies go in db/backups/, apart from the deploy's snapshots in db/.backups/,
    # whose pruning (the newest five gallery-*.db) would otherwise remove them.
    Write-Step 'Checking the daily backup...'
    try {
        Install-BackupTimer -Name 'gallery' -Database $database -Owner 'www-data' -Description 'Gallery daily database backup'
    } catch {
        throw "The Gallery is deployed and live, but its daily backup is not: $($_.Exception.Message)"
    }

    if ($devDatabase) { $null = Copy-LiveDatabase -Database $database -Destination $devDatabase }
} finally {
    Close-DeployConnection
    Remove-Item -LiteralPath $archive.Path -ErrorAction SilentlyContinue
}

Write-Host "`n[OK] Gallery deployed to $($connection.Target):$appRoot" -ForegroundColor Green
if (-not $NoBackup) {
    Write-Host "     Previous code kept at $appRoot/.deploy-backup" -ForegroundColor DarkGray
}
Write-Host '     SPA assets are content-hashed and the service worker auto-updates; no manual cache bust needed.' -ForegroundColor DarkGray
