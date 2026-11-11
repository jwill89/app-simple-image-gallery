#!/usr/bin/env pwsh
#Requires -Version 7.4

<#
.SYNOPSIS
    Build and deploy the Gallery (Laravel backend + Vue SPA) to the DigitalOcean droplet.

.DESCRIPTION
    Built on the shared SharedDeploy module: one archive, one held SSH connection,
    one remote run.

    The droplet runs the Laravel app from the gallery's domain folder (WebRoot),
    whose public/ is Apache's DocumentRoot. The database, media and duplicate
    reports sit beside the code in that folder (db/, media/, dupes/, at the paths
    its .env names), as does backups/. A deploy ships and replaces code only, and
    never touches those.

    What ships is the newest release: the newest tag CI made on main for
    frontend/package.json's version (the tags are fetched first), checked out into a
    folder of its own, with npm ci from its lockfile, and built and packed from there.
    Commits after it, an update of the shared submodule or work not yet released, are
    not part of it, and neither is anything uncommitted. -Preview ships the working
    tree instead. The host records the release each deploy leaves running; when that
    is already this release, nothing is done (-Force deploys it again).

    Steps, on one SSH connection held for the whole run:
      1. Build the frontend (vue-tsc + vite) -> frontend/dist.
      2. Stage and pack the payload, code only, as one archive:
           from backend/  -> app/, bootstrap/ (not its cache), config/, database/
                             (not a local gallery.db), public/, routes/, artisan,
                             composer.json, composer.lock, openapi.json
           from frontend/ -> dist/* into public/, except index.html, which goes to
                             resources/spa/index.html, where the SPA shell reads it
         Never .env, storage/, vendor/ or bootstrap/cache/.
      3. Upload the archive.
      4. On the host, in one remote run, once the archive's SHA-256 and file count check:
           - build the new code whole in .deploy-next: unpacked, vendor/ copied from
             the live code, composer install --no-dev     (skip with -SkipComposer),
             the public/media link copied, all of it given to www-data,
           - snapshot the database next to it in .backups/, compressed and
             integrity-checked (Backup-RemoteDatabase), keeping
             the five most recent                          (skip with -NoBackup),
           - put the server files in deploy/ (the two Apache vhosts, the queue worker's
             unit and the scheduler's cron file, filled in with this machine's settings)
             where they differ from the live ones, the previous copies kept in
             .deploy-host; Apache's configtest must take the vhosts, or the deploy
             stops here with the live code untouched (HostConfig.ps1),
           - swap the code in by renames, moving the previous code to .deploy-backup
             (kept afterwards unless -NoBackup); the site runs the old code or the
             new, never a half-replaced folder,
           - php artisan migrate --force                   (skip with -SkipMigrate); it
             comes first because the new code needs its tables to serve a request,
           - package:discover, storage:link (public/media), optimize, queue:restart.
             Neither storage/ nor the .env file is touched,
           - reload Apache if a vhost changed, and restart the queue worker if its unit
             changed; it must stay active.
         If a step fails before the swap, the live code was never touched; after it,
         the previous CODE and server files are moved back automatically. The database
         is NOT rolled back automatically: the failure trap fires on any non-zero exit,
         including after a migration has already succeeded, so reverting data could
         discard media uploaded during the deploy. A failed migration prints the snapshot
         path and the exact restore command.
      5. Fetch the health URL and the site from here, through Cloudflare. If either
         fails, the previous code and server files go back (the database stays as it
         is), unless -NoBackup left nothing to go back to. Once both pass, the host
         records the release.
      6. Check the daily backup (Install-BackupTimer): a systemd timer that runs as
         www-data at 10:00 UTC and copies the database with VACUUM INTO to
         db/backups/gallery-YYYY-MM-DD.db.gz, integrity-checked and compressed, keeping
         28 days. Those sit apart from step 4's snapshots in db/.backups/. If the timer
         is already as it should be, nothing changes; if it is new or changed, it runs
         once first, and a failed run puts the previous timer back.
      7. Pull a copy of the live database to the local one backend/.env names
         (DB_DATABASE), keeping the three previous local copies     (skip with -NoDbPull).
         It is skipped, with a warning, while the local database is in use.
    Steps 6 and 7 run only once the site checks pass, so a deploy that rolled back
    changes neither.

    The first deploy to a new WebRoot needs its .env created there first, since the
    archive never carries one (it holds the app key, the database path and the
    secrets); this script refuses to run without it.

    The server files are installed by this script and nowhere else: a change to a vhost,
    the unit or the cron file is made in deploy/ and ships with the next deploy.

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
.PARAMETER WebRoot
    The Laravel app's folder on the host (its public/ is the DocumentRoot). Resolved from -WebRoot, then $env:DEPLOY_WEB_ROOT, then $DeployWebRoot.
.PARAMETER CertName
    The vhost's certificate: its folder's name under /etc/letsencrypt/live, one whose certificate covers PublicHost. Resolved from -CertName, then $env:DEPLOY_CERT_NAME, then $DeployCertName.
.PARAMETER ServerAdmin
    The address the vhost gives as ServerAdmin. Resolved from -ServerAdmin, then $env:DEPLOY_SERVER_ADMIN, then $DeployServerAdmin.
.PARAMETER HealthUrl
    URL fetched after the deploy. Default: https://<PublicHost>/api/media/count.
.PARAMETER PublicHost
    The site's public host name, which the vhosts serve and name their files after.
.PARAMETER SkipBuild
    With -Preview, deploy the existing frontend/dist without rebuilding. A release is
    always built from its tag.
.PARAMETER Force
    Deploy the release even when the host already runs it.
.PARAMETER SkipComposer
    Don't run `composer install` on the host.
.PARAMETER SkipMigrate
    Don't run database migrations on the host.
.PARAMETER NoBackup
    Don't keep the previous code in .deploy-backup once the deploy succeeds (it is still put back on a failure), or snapshot the database. The daily backup is unaffected.
.PARAMETER NoDbPull
    Don't pull a copy of the live database to the local one.

.PARAMETER Preview
    Deploy the working tree as it is, uncommitted changes included, stamped
    preview-<date-time>. Without it the deploy ships only the newest release.

.EXAMPLE
    .\scripts\deploy.ps1                       # full build + deploy + composer + migrate
.EXAMPLE
    .\scripts\deploy.ps1 -Force                # the release again, though the host runs it
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
    [string]$WebRoot,
    [string]$CertName,
    [string]$ServerAdmin,
    [string]$PublicHost = 'gallery.mathdad.me',
    [string]$HealthUrl,
    [switch]$SkipBuild,
    [switch]$Force,
    [switch]$SkipComposer,
    [switch]$SkipMigrate,
    [switch]$NoBackup,
    [switch]$NoDbPull,
    [switch]$Preview
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
Import-Module (Join-Path $PSScriptRoot '..\shared\deploy\pwsh\SharedDeploy.psd1') -Force
. (Join-Path $PSScriptRoot 'HostConfig.ps1')
trap { Close-DeployConnection; Write-Host "`nERROR: $($_.Exception.Message)" -ForegroundColor Red; exit 1 }

$settings = Resolve-DeploySetting -ConfigPath (Join-Path $PSScriptRoot 'deploy.config.ps1') `
    -Value @{ VpsHost = $VpsHost; VpsUser = $VpsUser; KeyPath = $KeyPath; WebRoot = $WebRoot; CertName = $CertName; ServerAdmin = $ServerAdmin } `
    -Required VpsHost, VpsUser, KeyPath, WebRoot, CertName, ServerAdmin
$webRoot = $settings.WebRoot
if (-not $HealthUrl) { $HealthUrl = "https://$PublicHost/api/media/count" }

$repoRoot = Split-Path $PSScriptRoot -Parent
# What the host records as running, for the next deploy to compare with.
$record = 'gallery'

# The local database the pull refreshes: the one backend/.env names, which the app reads.
# backend/database/gallery.db is gitignored for it, and the deploy never packs it. The
# .env is this checkout's: a release's own checkout has none.
$devDatabase = $null
if (-not $NoDbPull) {
    $envPath = Join-Path $repoRoot 'backend/.env'
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
# site runs either the old code or the new: never a half-replaced folder, which would
# fail every request while composer install, the migration and optimize ran.
$shipped = 'app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'artisan', 'composer.json', 'composer.lock', 'openapi.json'
$code = @($shipped) + 'vendor'
$queueRestart = 'php artisan queue:restart >/dev/null 2>&1 || true'

# The server files in deploy/, as the host has them, and the shell that installs them
# (HostConfig.ps1). The vhosts are named for the site, as Apache's tools name them.
$hostFiles = 'gallery-http.conf', 'gallery-https.conf', 'gallery-queue.service', 'gallery-scheduler.cron'
$hostShell = Get-HostConfigShell -VhostHttp "/etc/apache2/sites-available/$PublicHost.conf" `
    -VhostHttps "/etc/apache2/sites-available/$PublicHost-le-ssl.conf" `
    -Unit '/etc/systemd/system/gallery-queue.service' -Cron '/etc/cron.d/gallery-scheduler'
# What puts the previous server files back after the swap, in the same run or a later one.
$putBack = $hostShell.PutBack + "`n" + $queueRestart
$putBackLater = $hostShell.Functions + "`n" + $putBack

# Only a release ships: the newest tag CI made for frontend/package.json's version.
# -Preview ships the tree as it is, stamped preview-<date-time>.
if ($Preview) {
    $version = 'preview-' + (Get-Date -Format 'yyyyMMdd-HHmm')
    Write-Warning "Preview: deploying the working tree untagged, as $version."
} else {
    if ($SkipBuild) { throw 'A release is always built from its tag; -SkipBuild goes with -Preview only.' }
    $release = Get-DeployRelease -Path $repoRoot
    $version = $release.Tag
}

Write-Host "Deploying Gallery $version -> $($settings.VpsUser)@$($settings.VpsHost):$webRoot" -ForegroundColor Yellow

# 3. The host's steps. They run from WebRoot, with $stage (.deploy-next) and $backup
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

if ($SkipMigrate) {
    $snapshot = ''
    $migrate = "echo '==> -SkipMigrate: skipping database migrations'"
} else {
    # Snapshot the database before anything can modify it (Backup-RemoteDatabase):
    # sqlite3 .backup, which is WAL-aware where a plain `cp gallery.db` would miss
    # whatever is still in the -wal file, PRAGMA integrity_check, then gzip, to
    # db/.backups/gallery-<UTC time>.db.gz. The newest five stay, counting any
    # uncompressed gallery-<time>.db copies already in the folder, so those age out.
    # A failed snapshot stops the deploy before the code is swapped. It is taken before
    # the swap, not after, so that the migration is the first thing the new code does:
    # until it has run, the new code lacks the tables it needs.
    #
    # The snapshot is deliberately NOT restored by the failure trap, which fires on
    # *any* non-zero exit, including after a successful migration: rolling the database
    # back would discard media uploaded during the deploy window. Rolling data back is a
    # decision, so the path and command are printed.
    if ($NoBackup) {
        $snapshot = @'
echo '==> -NoBackup: skipping database snapshot'
snapshot=""
'@
    } else {
        $snapshot = @'
DB=$(grep -E '^DB_DATABASE=' .env | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//')
echo '==> snapshotting the database before migrating'
'@ + "`n" + (Backup-RemoteDatabase -DatabaseVariable 'DB' -SnapshotPrefix '.backups/gallery-' -SnapshotSuffix '.db' -Keep 5)
    }
    $migrate = @'
echo '==> php artisan migrate'
if ! php artisan migrate --force --no-interaction; then
  echo 'The migrations failed.' >&2
  if [ -n "$snapshot" ]; then
    echo "  Restore the database from $snapshot with:" >&2
    echo "    $snapshot_restore" >&2
  fi
  exit 5
fi
'@
}

# The pre-swap step ends with the database snapshot and the server files: a snapshot or
# a vhost that fails stops the deploy with the live code untouched.
$prepare = Format-RemoteScript -Value @{ COMPOSER = $composer; FUNCTIONS = $hostShell.Functions; SNAPSHOT = $snapshot; INSTALL = $hostShell.Install } -Template @'
__FUNCTIONS__
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
__SNAPSHOT__
__INSTALL__
'@

# The migration runs first after the swap. (The package manifest it needs is built as the
# app boots; package:discover, after it, only makes the file that boot would.)
$afterSwap = Format-RemoteScript -Value @{ MIGRATE = $migrate; FINISH = $hostShell.Finish } -Template @'
__MIGRATE__
php artisan package:discover --ansi
echo '==> storage:link, optimize, queue:restart'
# The media link came over with the new code; this makes it on a first deploy.
[ -L public/media ] || php artisan storage:link --no-interaction
# Config, routes and events; the app has no Blade views to cache.
php artisan optimize --except=views
php artisan queue:restart
# What the artisan runs above wrote as root. Not the whole folder: the data and backups/
# sit beside the code and keep their own owners.
chown -R www-data:www-data bootstrap storage
# A .env that still names the file driver works, but keeps sessions where the new code no
# longer expects them. The config was just cached, so this is what the site now runs.
if ! php artisan config:show session.driver 2>/dev/null | tr -d '\r' | grep -Eq '[. ]database[[:space:]]*$'; then
  echo 'WARNING: sessions are not in the database. Set SESSION_DRIVER=database in .env, then run: sudo -u www-data php artisan optimize --except=views' >&2
fi
__FINISH__
'@

$tree = $null
$archive = $null
try {
    $connection = $settings | Open-DeployConnection
    # A preview builds the working tree; a release, its tag checked out on its own.
    $build = $repoRoot
    if (-not $Preview) {
        $live = Get-DeployedRelease -Name $record
        if ($live -eq $version -and -not $Force) {
            Write-Ok "$version is already running on the host; nothing to deploy. (A version pushed since goes out once CI tags it; -Force deploys this one again.)"
            return
        }
        $after = if ($release.Since) { "; the $($release.Since) commit(s) on main after it are not part of it" } else { '' }
        Write-Step "Releasing $version (the host runs $(if ($live) { $live } else { 'a release not recorded' }))$after"
        $tree = New-ReleaseTree -Path $repoRoot -Tag $version -Npm 'frontend'
        $build = $tree
    }
    $backendDir = Join-Path $build 'backend'
    $frontendDir = Join-Path $build 'frontend'
    $distDir = Join-Path $frontendDir 'dist' # vite outDir is frontend/dist

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

        # The server files, filled in for this host; the host installs them where they
        # differ from the live ones (HostConfig.ps1).
        $hostFolder = Join-Path $stage 'host'
        New-Item -ItemType Directory -Path $hostFolder | Out-Null
        $hostValues = @{ SERVER_NAME = $PublicHost; SERVER_ADMIN = $settings.ServerAdmin; WEB_ROOT = $webRoot; CERT_NAME = $settings.CertName }
        foreach ($file in $hostFiles) {
            $null = Export-HostConfigFile -Template (Join-Path $build "deploy/$file") -Destination $hostFolder -Value $hostValues
        }

        $archive = Compress-DeployArchive -Root $stage -Item (@($shipped) + 'host')
    } finally {
        Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
    }
    Write-Ok ('{0} files, {1:N1} MB, one archive' -f $archive.FileCount, ((Get-Item $archive.Path).Length / 1MB))

    Write-Step 'Uploading...'
    $upload = Invoke-Upload -Path $archive.Path -Destination 'gallery-deploy.tgz' -Activity 'Uploading the Gallery' -FileCount $archive.FileCount
    if (-not $upload.Ok) { throw "Upload failed; the live site is untouched.`n$($upload.Err)" }

    Write-Step 'Running the deploy on the host (code -> composer -> snapshot -> server files -> swap -> migrate -> optimize)...'
    Switch-RemoteItemSet -Archive 'gallery-deploy.tgz' -Sha256 $archive.Sha256 -ExpectedFileCount $archive.FileCount `
        -Root $webRoot -Item $code -Prepare $prepare -AfterSwap $afterSwap -OnRestore $putBack -NoBackup:$NoBackup

    Write-Step "Checking $PublicHost..."
    if ($NoBackup) {
        # Nothing was kept to go back to.
        Invoke-SmokeCheck -Url $HealthUrl, "https://$PublicHost/" -Attempts 3 -DelaySeconds 2 -NoRollback
    } else {
        # The previous code and server files go back; the database stays as it is, as above.
        Invoke-SmokeCheck -Url $HealthUrl, "https://$PublicHost/" -Attempts 3 -DelaySeconds 2 -Rollback {
            Restore-RemoteItemSet -Root $webRoot -Item $code -OnRestore $putBackLater
        }
    }
    # Only a deploy that stayed is recorded; a preview's stamp makes the next release go out.
    Set-DeployedRelease -Name $record -Release $version

    # From here the new code is live and stays; these keep the data safe. The live database
    # is the one the host's .env names.
    $database = @(Invoke-Remote (Format-RemoteScript -Value @{ ENV = "$webRoot/.env" } -Template @'
grep -E '^DB_DATABASE=' "__ENV__" | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
'@))[0]
    if (-not $database) { throw "The Gallery is deployed and live, but $webRoot/.env names no DB_DATABASE, so its daily backup was not checked." }

    # The daily copies go in db/backups/, apart from the deploy's snapshots in db/.backups/.
    Write-Step 'Checking the daily backup...'
    try {
        Install-BackupTimer -Name 'gallery' -Database $database -Owner 'www-data' -Description 'Gallery daily database backup'
    } catch {
        throw "The Gallery is deployed and live, but its daily backup is not: $($_.Exception.Message)"
    }

    if ($devDatabase) { $null = Copy-LiveDatabase -Database $database -Destination $devDatabase }
} finally {
    Close-DeployConnection
    if ($archive) { Remove-Item -LiteralPath $archive.Path -ErrorAction SilentlyContinue }
    if ($tree) { Remove-ReleaseTree -Path $tree -Repository $repoRoot }
}

Write-Host "`n[OK] Gallery deployed to $($connection.Target):$webRoot" -ForegroundColor Green
if (-not $NoBackup) {
    Write-Host "     Previous code kept at $webRoot/.deploy-backup" -ForegroundColor DarkGray
}
Write-Host '     SPA assets are content-hashed and the service worker auto-updates; no manual cache bust needed.' -ForegroundColor DarkGray
