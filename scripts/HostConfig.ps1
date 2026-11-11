#Requires -Version 7.4

<#
.SYNOPSIS
The gallery's server files in deploy/, filled in for the host, and the shell that installs them.

.DESCRIPTION
Dot-sourced by deploy.ps1. The Apache vhosts, the queue worker's unit and the scheduler's cron
file are templates in deploy/, with the host's own values (server name, administrator's
address, app folder, certificate) as __PLACEHOLDERS__; Export-HostConfigFile fills them from the
deploy settings, and Get-HostConfigShell returns the shell that runs on the host as parts of
Switch-RemoteItemSet's run:

  Functions the shell functions the other parts use; first in Prepare
  Install   puts each file in place where it differs from the live one (the previous copy kept in
            .deploy-host), refuses a vhost Apache's configtest does not take, and checks the cron
            file; last in Prepare, so a refusal stops the deploy before the code is swapped
  Finish    reloads Apache if a vhost changed, and restarts the queue worker if its unit changed,
            failing the deploy if it does not stay up; last after the swap
  PutBack   puts every replaced file back, for a deploy that fails after the swap or a smoke
            check that fails (run after Functions)

The files do not depend on the code's version, so they go in before the swap; a deploy that
fails once the code is swapped in puts them back with it.

SharedDeploy's Install-ServiceBinary does this for a service that is a binary; the gallery's
worker runs the code the deploy swaps, so there is no binary to install, and the same steps are
written here.
#>

function Export-HostConfigFile {
    <#
    .SYNOPSIS
    Writes one of deploy/'s templates, filled in, to a folder, and returns its path.

    .DESCRIPTION
    Throws if a placeholder is left unfilled, or if the text would not end in a newline once
    written (cron ignores a file without one), so a mistake stops the deploy before anything is
    sent. Files are written as UTF-8 without a byte order mark, with LF line endings.

    .PARAMETER Template
    The template's path.

    .PARAMETER Destination
    The folder to write it to; the file keeps its name.

    .PARAMETER Value
    The value of each placeholder, by name without the underscores.

    .EXAMPLE
    Export-HostConfigFile -Template deploy/gallery-queue.service -Destination $folder -Value @{ APP_ROOT = '/var/www/example' }
    #>
    [CmdletBinding()]
    [OutputType([string])]
    param(
        [Parameter(Mandatory)]
        [string]$Template,

        [Parameter(Mandatory)]
        [string]$Destination,

        [Parameter(Mandatory)]
        [hashtable]$Value
    )
    $text = Format-RemoteScript -Template (Get-Content -LiteralPath $Template -Raw) -Value $Value
    $left = [regex]::Matches($text, '__[A-Z][A-Z0-9_]*__') | ForEach-Object { $_.Value } | Select-Object -Unique
    if ($left) {
        throw "$(Split-Path $Template -Leaf) still has $($left -join ', ') after the deploy settings were filled in; nothing was changed."
    }
    if (-not $text.EndsWith("`n")) { $text += "`n" }
    $path = Join-Path $Destination (Split-Path $Template -Leaf)
    [IO.File]::WriteAllText($path, $text, [Text.UTF8Encoding]::new($false))
    $path
}

function Get-HostConfigShell {
    <#
    .SYNOPSIS
    The shell that installs the gallery's server files on the host, in parts for Switch-RemoteItemSet.

    .DESCRIPTION
    The parts read the filled files from "$bundle/host/" (the folder the deploy packs beside the
    code) and keep the files they replace in "$root/.deploy-host/", outside the code's own backup
    folder, which the swap empties. A file is installed only where it differs from the live one
    (cmp), under a dotted name first and renamed into place, so cron and Apache never read half
    a file. Every step is checked: Apache's configtest before the deploy goes on, the cron
    file's owner and mode, and the worker active after its restart.

    The parts use these shell variables, which Switch-RemoteItemSet and Restore-RemoteItemSet
    set: root, and bundle (the upload; Install only).

    .PARAMETER VhostHttp
    The port-80 vhost's path on the host.

    .PARAMETER VhostHttps
    The port-443 vhost's path on the host.

    .PARAMETER Unit
    The queue worker's unit path on the host.

    .PARAMETER Cron
    The scheduler's cron file path on the host.

    .PARAMETER Service
    The queue worker's service name.

    .PARAMETER WebService
    The web server's service name, reloaded when a vhost changed.

    .EXAMPLE
    $shell = Get-HostConfigShell -VhostHttp /etc/apache2/sites-available/example.conf -VhostHttps /etc/apache2/sites-available/example-le-ssl.conf -Unit /etc/systemd/system/example-queue.service -Cron /etc/cron.d/example-scheduler
    #>
    [CmdletBinding()]
    [OutputType([pscustomobject])]
    param(
        [Parameter(Mandatory)]
        [string]$VhostHttp,

        [Parameter(Mandatory)]
        [string]$VhostHttps,

        [Parameter(Mandatory)]
        [string]$Unit,

        [Parameter(Mandatory)]
        [string]$Cron,

        [string]$Service = 'gallery-queue',

        [string]$WebService = 'apache2'
    )
    foreach ($path in $VhostHttp, $VhostHttps, $Unit, $Cron) {
        # The paths go into single quotes and into name:path pairs.
        if ($path -notmatch '^/[A-Za-z0-9_./-]+$') { throw "Not a path the host files can use: $path" }
    }

    $functions = Format-RemoteScript -Value @{
        VHOST_HTTP  = $VhostHttp
        VHOST_HTTPS = $VhostHttps
        UNIT        = $Unit
        CRON        = $Cron
        SERVICE     = $Service
        WEB         = $WebService
    } -Template @'
# ---- the gallery's server files ----
host_dir="$root/.deploy-host"
host_vhost_http='__VHOST_HTTP__'
host_vhost_https='__VHOST_HTTPS__'
host_unit='__UNIT__'
host_cron='__CRON__'

# host_install NAME SOURCE TARGET: puts SOURCE at TARGET unless they are the same, keeping what
# was there as $host_dir/NAME.old, or noting in NAME.new that nothing was. host_installed is 1
# when it installed.
host_install() {
  host_installed=
  if [ ! -f "$2" ]; then echo "The upload has no $2." >&2; exit 3; fi
  if cmp -s "$2" "$3"; then return 0; fi
  mkdir -p "$host_dir"
  rm -f "$host_dir/$1.old" "$host_dir/$1.new"
  if [ -e "$3" ]; then cp -p "$3" "$host_dir/$1.old"; else : > "$host_dir/$1.new"; fi
  host_next="$(dirname "$3")/.$(basename "$3").next"
  install -m 644 -o root -g root "$2" "$host_next"
  mv -f "$host_next" "$3"
  host_installed=1
  echo "Installed $3."
}

# host_put_back: every file host_install replaced goes back (or goes, if it was new), then
# systemd, Apache and the worker take the old ones. It can run twice; the second time finds
# nothing to do.
host_put_back() {
  host_back_vhost=
  host_back_unit=
  for host_pair in "gallery-http:$host_vhost_http" "gallery-https:$host_vhost_https" "gallery-queue:$host_unit" "gallery-scheduler:$host_cron"; do
    host_name=${host_pair%%:*}
    host_target=${host_pair#*:}
    if [ -f "$host_dir/$host_name.old" ]; then
      cp -p "$host_dir/$host_name.old" "$host_target"
      rm -f "$host_dir/$host_name.old"
    elif [ -f "$host_dir/$host_name.new" ]; then
      rm -f "$host_target"
      rm -f "$host_dir/$host_name.new"
    else
      continue
    fi
    echo "Put back $host_target."
    case "$host_name" in
      gallery-http|gallery-https) host_back_vhost=1 ;;
      gallery-queue) host_back_unit=1 ;;
    esac
  done
  if [ -n "$host_back_unit" ]; then
    systemctl daemon-reload
    systemctl restart __SERVICE__ || true
  fi
  if [ -n "$host_back_vhost" ]; then
    if apachectl configtest >/dev/null 2>&1; then systemctl reload __WEB__ || true; fi
  fi
}
'@

    $install = Format-RemoteScript -Value @{ SERVICE = $Service } -Template @'
echo '==> server files: Apache vhosts, queue unit, scheduler cron'
# What an earlier deploy replaced is not this deploy's to put back.
mkdir -p "$host_dir"
rm -f "$host_dir"/*.old "$host_dir"/*.new
host_vhosts=
host_install gallery-http "$bundle/host/gallery-http.conf" "$host_vhost_http"
if [ -n "$host_installed" ]; then host_vhosts=1; fi
host_install gallery-https "$bundle/host/gallery-https.conf" "$host_vhost_https"
if [ -n "$host_installed" ]; then host_vhosts=1; fi
if [ -n "$host_vhosts" ]; then
  if ! host_verdict=$(apachectl configtest 2>&1); then
    echo 'Apache refused the new vhost, so the previous files are back and the deploy stops before anything else changes:' >&2
    echo "$host_verdict" >&2
    host_put_back
    exit 5
  fi
fi
host_unit_changed=
host_install gallery-queue "$bundle/host/gallery-queue.service" "$host_unit"
if [ -n "$host_installed" ]; then
  host_unit_changed=1
  systemctl daemon-reload
  systemctl enable __SERVICE__ >/dev/null 2>&1 || true
fi
host_install gallery-scheduler "$bundle/host/gallery-scheduler.cron" "$host_cron"
if [ "$(stat -c '%a %U:%G' "$host_cron")" != '644 root:root' ]; then
  echo "$host_cron is not root:root with mode 644, which cron needs, so it would ignore it." >&2
  exit 5
fi
'@

    $finish = Format-RemoteScript -Value @{ SERVICE = $Service; WEB = $WebService } -Template @'
if [ -n "$host_vhosts" ]; then
  systemctl reload __WEB__
  echo 'Apache took the new vhosts.'
fi
if [ -n "$host_unit_changed" ]; then
  systemctl restart __SERVICE__
  sleep 2
  if ! systemctl is-active --quiet __SERVICE__; then
    journalctl -u __SERVICE__ -n 30 --no-pager >&2 || true
    echo '__SERVICE__ did not stay active on its new unit.' >&2
    exit 6
  fi
  echo '__SERVICE__ is running on its new unit.'
fi
'@

    [pscustomobject]@{
        Functions = $functions
        Install   = $install
        Finish    = $finish
        PutBack   = 'host_put_back'
    }
}
