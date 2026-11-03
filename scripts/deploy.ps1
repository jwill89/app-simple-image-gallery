#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Build and deploy the Gallery (Laravel backend + Vue SPA) to the DigitalOcean droplet.

.DESCRIPTION
    The droplet runs the Laravel app from the gallery's domain folder (AppRoot),
    whose public/ is Apache's DocumentRoot. The database, media and duplicate
    reports sit beside the code in that folder (db/, media/, dupes/, at the paths
    its .env names), as do backups/ and slim-rollback/. A deploy ships and
    replaces code only, and never touches those.

    Steps:
      1. Build the frontend (vue-tsc + vite) -> frontend/dist  (skip with -SkipBuild).
      2. Stage and pack the payload, code only:
           from backend/  -> app/, bootstrap/ (not its cache), config/, database/
                             (not a local gallery.db), public/, routes/, artisan,
                             composer.json, composer.lock, openapi.json
           from frontend/ -> dist/* into public/, except index.html, which goes to
                             resources/spa/index.html, where the SPA shell reads it
         Never .env, storage/, vendor/ or bootstrap/cache/.
      3. Upload it in a single pscp stream.
      4. On the host (one plink connection):
           - back up the current code to .deploy-backup (rollback; skip with -NoBackup),
           - replace the code (storage/ and .env are left alone),
           - composer install --no-dev                     (skip with -SkipComposer),
           - snapshot the database next to it in .backups/ via SQLite VACUUM INTO,
             keeping the five most recent                  (skip with -NoBackup),
           - php artisan migrate --force                   (skip with -SkipMigrate),
           - storage:link (public/media), optimize, queue:restart,
           - give the code, vendor/ and storage/ to www-data (the code read-only to
             everyone else), and health-check the API.
         If a step fails, the previous CODE is restored automatically. The database
         is NOT rolled back automatically: the failure trap fires on any non-zero
         exit, including after a migration has already succeeded, so reverting data
         could discard media uploaded during the deploy. A failed migration prints
         the snapshot path and the exact restore command.

    The first deploy to a new AppRoot needs its .env first (the plan's Deployment
    section, docs/laravel-migration.md); this script refuses to run without one.

    Uses PuTTY's pscp/plink so the DigitalOcean .ppk key works directly. They run
    in batch mode, so a passphrase-protected .ppk must be loaded into Pageant first:
        pageant.exe "<KeyPath>"   (enter the passphrase once per Windows session).

    The droplet runs `ufw limit ssh` (drops the IP after ~6 connections in 30s), so
    this uses just TWO SSH connections (one upload, one remote run). The upload is
    retried once after a 35s wait if it looks rate-limited.

.PARAMETER VpsHost      Droplet IP/hostname. Resolved from -VpsHost, then $env:DEPLOY_VPS_HOST, then scripts/deploy.config.ps1 ($DeployVpsHost).
.PARAMETER VpsUser      SSH user. Resolved from -VpsUser, then $env:DEPLOY_VPS_USER, then $DeployVpsUser.
.PARAMETER KeyPath      PuTTY .ppk private key. Resolved from -KeyPath, then $env:DEPLOY_KEY_PATH, then $DeployKeyPath.
.PARAMETER AppRoot      The Laravel app's folder on the host (its public/ is the DocumentRoot). Resolved from -AppRoot, then $env:DEPLOY_APP_ROOT, then $DeployAppRoot.
.PARAMETER HealthUrl    URL fetched on the host after the deploy. Default: https://<PublicHost>/api/media/count.
.PARAMETER PublicHost   The site's public host name.
.PARAMETER SkipBuild    Deploy the existing frontend/dist without rebuilding.
.PARAMETER SkipComposer Don't run `composer install` on the host.
.PARAMETER SkipMigrate  Don't run database migrations on the host.
.PARAMETER NoBackup     Don't keep a .deploy-backup rollback copy, or snapshot the database.

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
    # resolved below from its -param, then $env:DEPLOY_* (the default), then the
    # untracked scripts/deploy.config.ps1. See deploy.config.example.ps1.
    [string]$VpsHost = $env:DEPLOY_VPS_HOST,
    [string]$VpsUser = $env:DEPLOY_VPS_USER,
    [string]$KeyPath = $env:DEPLOY_KEY_PATH,
    [string]$AppRoot = $env:DEPLOY_APP_ROOT,
    [string]$PublicHost = "gallery.mathdad.me",
    [string]$HealthUrl,
    [switch]$SkipBuild,
    [switch]$SkipComposer,
    [switch]$SkipMigrate,
    [switch]$NoBackup
)

$ErrorActionPreference = 'Stop'
# PowerShell 7.4+ makes a non-zero native exit throw under 'Stop', bypassing our
# own $LASTEXITCODE checks. Disable so our checks/messages always run. (No-op on 5.1.)
$PSNativeCommandUseErrorActionPreference = $false

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Write-Ok($msg) { Write-Host "    $msg" -ForegroundColor Green }
function Fail($msg) { Write-Host "`nERROR: $msg" -ForegroundColor Red; exit 1 }
trap { Write-Host "`nERROR: $($_.Exception.Message)" -ForegroundColor Red; exit 1 }

# ── Server settings resolution ────────────────────────────────────────────────
$configPath = Join-Path $PSScriptRoot 'deploy.config.ps1'
if (Test-Path $configPath) { . $configPath }
if (-not $VpsHost) { $VpsHost = $DeployVpsHost }
if (-not $VpsUser) { $VpsUser = $DeployVpsUser }
if (-not $KeyPath) { $KeyPath = $DeployKeyPath }
if (-not $AppRoot) { $AppRoot = $DeployAppRoot }
if (-not $HealthUrl) { $HealthUrl = "https://$PublicHost/api/media/count" }

$missing = @()
if (-not $VpsHost) { $missing += 'VpsHost (-VpsHost / $env:DEPLOY_VPS_HOST / $DeployVpsHost)' }
if (-not $VpsUser) { $missing += 'VpsUser (-VpsUser / $env:DEPLOY_VPS_USER / $DeployVpsUser)' }
if (-not $KeyPath) { $missing += 'KeyPath (-KeyPath / $env:DEPLOY_KEY_PATH / $DeployKeyPath)' }
if (-not $AppRoot) { $missing += 'AppRoot (-AppRoot / $env:DEPLOY_APP_ROOT / $DeployAppRoot)' }
if ($missing.Count -gt 0) {
    Fail "Missing deploy settings:`n  - $($missing -join "`n  - ")`nSet these via -params, `$env:DEPLOY_* variables, or scripts/deploy.config.ps1 (copy scripts/deploy.config.example.ps1)."
}

# ── Resolve paths & tools ─────────────────────────────────────────────────────
$RepoRoot = Split-Path $PSScriptRoot -Parent
$BackendDir = Join-Path $RepoRoot "backend"
$FrontendDir = Join-Path $RepoRoot "frontend"
$DistDir = Join-Path $FrontendDir "dist" # vite outDir is frontend/dist

if (-not (Test-Path $KeyPath)) { Fail "SSH key not found: $KeyPath" }

function Resolve-PuttyTool($name) {
    $cmd = Get-Command $name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $candidate = Join-Path "C:\Program Files\PuTTY" "$name.exe"
    if (Test-Path $candidate) { return $candidate }
    Fail "$name not found. Install PuTTY (winget install PuTTY.PuTTY) or add it to PATH."
}
$pscp = Resolve-PuttyTool "pscp"
$plink = Resolve-PuttyTool "plink"
if (-not (Get-Command tar -ErrorAction SilentlyContinue)) {
    Fail "tar not found. Windows 10+ ships tar.exe; ensure C:\Windows\System32 is on PATH."
}

$remoteTarget = "$VpsUser@$VpsHost"
$remoteTarball = "/tmp/gallery-deploy.tgz"

# Upload one local file to a remote path, retrying once if it looks rate-limited.
function Send-File([string]$localPath, [string]$remotePath) {
    & $pscp -batch -i $KeyPath "$localPath" "${remoteTarget}:$remotePath"
    if ($LASTEXITCODE -eq 0) { return $true }
    Write-Host "    Upload failed - possibly the SSH rate limit (ufw limit ssh). Waiting 35s, then retrying once..." -ForegroundColor Yellow
    Start-Sleep -Seconds 35
    & $pscp -batch -i $KeyPath "$localPath" "${remoteTarget}:$remotePath"
    return ($LASTEXITCODE -eq 0)
}

Write-Host "Deploying Gallery -> ${remoteTarget}:$AppRoot" -ForegroundColor Yellow

# ── 1. Build the frontend ─────────────────────────────────────────────────────
if ($SkipBuild) {
    Write-Step "Skipping frontend build (-SkipBuild); using existing dist/"
} else {
    Write-Step "Building frontend (vue-tsc + vite)..."
    Push-Location $FrontendDir
    try {
        npm run build
        if ($LASTEXITCODE -ne 0) { Fail "Frontend build failed (exit $LASTEXITCODE). Live site untouched." }
    } finally { Pop-Location }
}
if (-not (Test-Path (Join-Path $DistDir "index.html"))) {
    Fail "frontend/dist/index.html is missing - nothing to deploy. (Run without -SkipBuild.)"
}
Write-Ok "Build present: $DistDir"

# ── 2. Stage and pack the payload (code only; never data) ─────────────────────
Write-Step "Packing deploy tarball..."
$stage = Join-Path $env:TEMP ("gallery-deploy-{0}" -f [guid]::NewGuid())
$tarball = "$stage.tgz"
New-Item -ItemType Directory -Path $stage | Out-Null
try {
    $backendItems = @('app', 'bootstrap', 'config', 'database', 'public', 'routes', 'artisan', 'composer.json', 'composer.lock', 'openapi.json')
    foreach ($p in $backendItems) {
        $source = Join-Path $BackendDir $p
        if (-not (Test-Path $source)) { Fail "Expected path missing: backend/$p" }
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
    Get-ChildItem $DistDir | Where-Object { $_.Name -ne 'index.html' } | Copy-Item -Destination (Join-Path $stage 'public') -Recurse
    New-Item -ItemType Directory -Path (Join-Path $stage 'resources/spa') | Out-Null
    Copy-Item (Join-Path $DistDir 'index.html') (Join-Path $stage 'resources/spa/index.html')

    & tar -czf $tarball -C $stage .
    if ($LASTEXITCODE -ne 0) { Fail "tar failed (exit $LASTEXITCODE)." }
} finally {
    Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
}
$sizeMB = (Get-Item $tarball).Length / 1MB
Write-Ok ("Tarball ready: {0:N1} MB" -f $sizeMB)

# ── 3. Upload the tarball (1 connection) ──────────────────────────────────────
Write-Step "Uploading tarball to $remoteTarball ..."
try {
    if (-not (Send-File $tarball $remoteTarball)) {
        Fail @"
Upload failed. Live site untouched.
  - If the .ppk has a passphrase, load it into Pageant first, then re-run:
        pageant.exe "$KeyPath"
  - First-ever connection also needs the host key cached once:
        plink -i "$KeyPath" $remoteTarget   (accept the fingerprint)
  - Otherwise verify host ($VpsHost) and user ($VpsUser).
"@
    }
} finally {
    Remove-Item $tarball -Force -ErrorAction SilentlyContinue
}
Write-Ok "Upload finished."

# ── 4. Build the remote deploy script ─────────────────────────────────────────
# Single-quoted here-strings keep shell $vars literal; {{APPROOT}}, {{TARBALL}} and
# {{HEALTHURL}} are substituted by PowerShell afterwards. Closing '@ at column 0.

$header = @'
set -e
cd "{{APPROOT}}" || { echo "ERROR: cannot cd to {{APPROOT}}" >&2; exit 2; }
if [ ! -f .env ]; then echo "ERROR: {{APPROOT}}/.env is missing. Create it before the first deploy (docs/laravel-migration.md, Deployment)." >&2; exit 2; fi
if [ ! -f "{{TARBALL}}" ]; then echo "ERROR: {{TARBALL}} not found (upload failed?)" >&2; exit 3; fi
export COMPOSER_ALLOW_SUPERUSER=1
CODE="app bootstrap/app.php bootstrap/providers.php config database public resources routes artisan composer.json composer.lock openapi.json"
# What a deploy gives to www-data. Not the whole folder: the data, backups/ and
# slim-rollback/ sit beside the code and keep their own owners.
OWNED="$CODE bootstrap vendor storage"
SUCCESS=0
'@

if ($NoBackup) {
    $backup = @'
echo "==> -NoBackup: skipping rollback snapshot"
'@
} else {
    $backup = @'
echo "==> backing up current code to .deploy-backup"
BACKUP=.deploy-backup
rm -rf "$BACKUP"; mkdir -p "$BACKUP"
for p in $CODE; do
  if [ -e "$p" ]; then cp -a --parents "$p" "$BACKUP/"; fi
done
restore() {
  rc=$?
  if [ "$SUCCESS" = "1" ]; then exit 0; fi
  echo "ERROR: deploy failed (rc=$rc) - restoring previous code from $BACKUP" >&2
  for p in $CODE; do rm -rf "$p"; done
  cp -a "$BACKUP/." ./ 2>/dev/null || true
  php artisan optimize:clear >/dev/null 2>&1 || true
  chown -R www-data:www-data $OWNED 2>/dev/null || true
  exit $rc
}
trap restore EXIT
'@
}

# Replaced, not extracted over: a file removed from the repo must not linger
# (a stale command or class would still be loaded).
$extract = @'
echo "==> replacing the code (.env and storage/ untouched)"
for p in $CODE; do rm -rf "$p"; done
tar xzf "{{TARBALL}}"
rm -f "{{TARBALL}}"
# The tarball is built on Windows, whose modes come through as 777/666.
chmod -R u=rwX,go=rX $CODE bootstrap
mkdir -p storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
rm -f bootstrap/cache/*.php
'@

if ($SkipComposer) {
    $composer = @'
echo "==> -SkipComposer: skipping composer install"
'@
} else {
    $composer = @'
echo "==> composer install (no dev packages)"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
'@
}

if ($SkipMigrate) {
    $migrate = @'
echo "==> -SkipMigrate: skipping database migrations"
'@
} else {
    # Snapshot the database immediately before anything can modify it.
    #
    # `cp gallery.db` is NOT safe: the connection runs in WAL mode, so a plain
    # copy misses whatever is still in the -wal file. `VACUUM INTO` is atomic and
    # WAL-aware, and reachable from PDO, so this needs no sqlite3 binary.
    #
    # The snapshot is deliberately NOT restored by the failure trap, which fires
    # on *any* non-zero exit, including after a successful migration: rolling
    # the database back would discard media uploaded during the deploy window.
    # Rolling data back is a decision, so the path and command are printed.
    if ($NoBackup) {
        $snapshot = @'
echo "==> -NoBackup: skipping database snapshot"
DB_SNAPSHOT=""
'@
    } else {
        $snapshot = @'
DB=$(grep -E '^DB_DATABASE=' .env | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//')
DB_SNAPSHOT=""
if [ -n "$DB" ] && [ -f "$DB" ]; then
  DB_SNAPSHOT="$(dirname "$DB")/.backups/gallery-$(date +%Y%m%d-%H%M%S).db"
  mkdir -p "$(dirname "$DB_SNAPSHOT")"
  echo "==> snapshotting the database before migrating"
  php -r '$pdo = new PDO("sqlite:" . $argv[1]); $pdo->exec("VACUUM INTO " . $pdo->quote($argv[2]));' "$DB" "$DB_SNAPSHOT" \
    || { echo "ERROR: database snapshot failed - aborting before migrate" >&2; exit 4; }
  echo "    snapshot: $DB_SNAPSHOT ($(du -h "$DB_SNAPSHOT" | cut -f1))"
  # Keep the five most recent; these are full copies of the database.
  ls -1t "$(dirname "$DB_SNAPSHOT")"/gallery-*.db 2>/dev/null | tail -n +6 | xargs -r rm -f
fi
'@
    }
    $migrate = $snapshot + "`n" + @'
echo "==> php artisan migrate"
if ! php artisan migrate --force --no-interaction; then
  echo "ERROR: migrations failed." >&2
  if [ -n "$DB_SNAPSHOT" ]; then
    echo "  Restore the database with:" >&2
    echo "    cp -a $DB_SNAPSHOT $DB && rm -f $DB-wal $DB-shm && chown www-data:www-data $DB" >&2
  fi
  exit 5
fi
'@
}

$finish = @'
echo "==> storage:link, optimize, queue:restart"
php artisan storage:link --no-interaction
# Config, routes and events; the app has no Blade views to cache.
php artisan optimize --except=views
php artisan queue:restart
chown -R www-data:www-data $OWNED
SUCCESS=1
code=$(curl -s -o /dev/null -w "%{http_code}" "{{HEALTHURL}}" 2>/dev/null || echo "000")
echo "==> health check: GET {{HEALTHURL}} -> HTTP $code"
echo "DEPLOY_OK"
'@

$remoteScript = ($header, $backup, $extract, $composer, $migrate, $finish) -join "`n"
$remoteScript = $remoteScript.Replace('{{APPROOT}}', $AppRoot).Replace('{{TARBALL}}', $remoteTarball).Replace('{{HEALTHURL}}', $HealthUrl)

# ── 5. Run the remote deploy (1 connection) ───────────────────────────────────
Write-Step "Running remote deploy (code -> composer -> migrate -> optimize)..."
$out = & $plink -batch -i $KeyPath $remoteTarget $remoteScript 2>&1
foreach ($line in $out) { if ("$line".Trim()) { Write-Host "    $line" } }

if ($LASTEXITCODE -ne 0 -or -not ($out -match 'DEPLOY_OK')) {
    Fail @"
Remote deploy failed (exit $LASTEXITCODE).
  The host restored the previous CODE from .deploy-backup (unless -NoBackup).
  The DATABASE was left as-is. If the failure was in the migrate step, the snapshot
  path and restore command are printed above.
  Inspect logs:  plink -i "$KeyPath" $remoteTarget "tail -n 50 $AppRoot/storage/logs/laravel-*.log"
"@
}

Write-Host "`n[OK] Gallery deployed to ${remoteTarget}:$AppRoot" -ForegroundColor Green
if (-not $NoBackup) {
    Write-Host "     Previous code kept at $AppRoot/.deploy-backup" -ForegroundColor DarkGray
}
Write-Host "     SPA assets are content-hashed and the service worker auto-updates; no manual cache bust needed." -ForegroundColor DarkGray
