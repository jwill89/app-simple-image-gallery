# Operations

The runbook for the live gallery, `gallery.mathdad.me`: what the droplet needs, what is backed up and how it comes back, what to check after a deploy, the steps a release needs, and where the logs are. How a deploy works is in [AGENTS.md](../AGENTS.md#deploy-to-the-digitalocean-droplet); the Slim-to-Laravel move is in [laravel-migration.md](laravel-migration.md).

Paths on the droplet are written as placeholders, filled from the deploy settings in `scripts/deploy.config.ps1`: `<web root>` (`WebRoot`), `<cert>` (`CertName`), `<admin address>` (`ServerAdmin`) and `<service user>` (`ServiceUser`, the queue worker's account). The site's name and address are the `ServerName` and `SiteUrl` settings, `gallery.mathdad.me` and `https://gallery.mathdad.me` unless set.

## Host setup

The gallery is a PHP site on the shared host: Apache serves `<web root>/public` through the PHP-FPM pool every site uses, so it has no port of its own and no `AppPort` setting. Its queue worker is a systemd service under an account of its own, and its scheduler a cron file. Run as root; every command is safe to run again.

```bash
W='<web root>'
SU='<service user>'

# PHP 8.5 with the extensions the app uses (exif, fileinfo, openssl and sodium come with php8.5-common), SQLite, ffmpeg.
apt-get install -y php8.5-fpm php8.5-cli php8.5-bcmath php8.5-curl php8.5-gd php8.5-intl php8.5-mbstring php8.5-sqlite3 php8.5-xml php8.5-zip sqlite3 ffmpeg

# Apache: TLS, headers (HSTS), the visitor's address from Cloudflare, rewrites, expiry headers, HTTP/2.
a2enmod ssl headers remoteip rewrite expires http2
[ -s /etc/apache2/cloudflare-ips.txt ] || { curl -fsS https://www.cloudflare.com/ips-v4; echo; curl -fsS https://www.cloudflare.com/ips-v6; echo; } > /etc/apache2/cloudflare-ips.txt

# The queue worker's account: a locked system account with no home and no shell, www-data its primary group.
id -u "$SU" >/dev/null 2>&1 || useradd --system --gid www-data --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$SU"

# The site's folder, root's; the folders the worker and PHP-FPM share, www-data's, setgid and group-writable.
install -d -o root -g root -m 755 "$W"
for d in storage storage/app storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs media media/full media/thumbs; do
  [ -d "$W/$d" ] || install -d -o www-data -g www-data -m 2775 "$W/$d"
done
[ -d "$W/db" ] || install -d -o www-data -g www-data -m 2770 "$W/db"
[ -d "$W/dupes" ] || install -d -o www-data -g www-data -m 755 "$W/dupes"

# The database, in WAL mode, 660.
[ -f "$W/db/gallery.db" ] || runuser -u www-data -- sqlite3 "$W/db/gallery.db" 'pragma journal_mode=wal;'
chmod 660 "$W/db/gallery.db"

# .env: from backend/.env.example (copy its text into the file), then fill in the values for the host.
[ -f "$W/.env" ] || install -o www-data -g www-data -m 640 /dev/null "$W/.env"
# After the first deploy, if APP_KEY is empty: sudo -u www-data php "$W/artisan" key:generate

# The shared certificate must cover the site's name, for 30 days at least.
openssl x509 -in /etc/letsencrypt/live/<cert>/fullchain.pem -noout -ext subjectAltName | grep -E 'DNS:(gallery\.mathdad\.me|\*\.mathdad\.me)'
openssl x509 -in /etc/letsencrypt/live/<cert>/fullchain.pem -noout -checkend 2592000
```

DNS: `gallery.mathdad.me`, proxied by Cloudflare (Full (strict) to the origin's certificate).

The deploy config names `VpsHost`, `VpsUser`, `KeyPath`, `WebRoot`, `CertName`, `ServerAdmin` and `ServiceUser`; `ServerName` and `SiteUrl` are optional. The deploy refuses to run until the account and the shared folders above are in place.

Who writes what: PHP-FPM and the scheduler run as www-data, the queue worker as `<service user>`, whose primary group is www-data, under `UMask=0002`. The folders they share are www-data's, setgid and group-writable, so either side can replace and remove what the other made; the app makes its log 0664 and its file cache 0770 (`config/logging.php`, `config/cache.php`), and SQLite gives the database's `-wal` and `-shm` the database file's own mode, 660.

The queue worker's memory: its peak was 37.3 MB over 52 hourly runs, 2026-11-03 to 2026-11-16, none of them ingesting anything, so it has no `MemoryMax=` yet: decoding one of the largest originals (60 MP) takes about 229 MB, and a cap from that peak would stop the ingest. Upgrading to 8.0.0, step 6, measures the heavy path.

### The server files

The Apache vhost, the queue worker's unit and the scheduler's cron file are templates in `deploy/`. Every deploy fills in their host values with `Expand-DeployTemplate` and installs each where it differs from the live one:

| Template | Installed as | By |
| --- | --- | --- |
| `deploy/apache-vhost.conf` | `/etc/apache2/sites-available/gallery.mathdad.me.conf`, enabled | `Switch-RemoteItemSet -Vhost`, before the code is swapped in |
| `deploy/gallery-queue.service` | `/etc/systemd/system/gallery-queue.service` | `Install-HostConfig`, after the swap; the worker restarts on the new code and must stay active |
| `deploy/gallery-scheduler.cron` | `/etc/cron.d/gallery-scheduler` | `Install-HostConfig`, after the swap |

The vhost is one file for the site: the port-80 redirect and the https site, which sends HSTS and takes the visitor's address from Cloudflare's `CF-Connecting-IP` (`mod_remoteip`, trusting only the ranges in `/etc/apache2/cloudflare-ips.txt`). The deploy disables every other enabled site that serves the name, such as certbot's `-le-ssl` file, tries the change on a copy of Apache's config first, and refuses, changing nothing, if Apache would. The unit's and cron file's previous copies are kept in `/var/lib/shared-deploy/gallery`. A deploy that fails afterwards, or whose site check fails, puts the previous code, vhost, unit and cron file back.

Nobody edits these files on the droplet: the next deploy replaces them. A change goes into the template.

## Backups

| What | How | Where | Kept |
| --- | --- | --- | --- |
| The database, daily | `gallery-backup.timer` (installed by the deploy's `Install-BackupTimer`), 10:00 UTC, as www-data: `VACUUM INTO`, `PRAGMA integrity_check`, gzip | `<web root>/db/backups/gallery-YYYY-MM-DD.db.gz` | 28 days |
| The database, before each deploy's migration | `Backup-RemoteDatabase`: `sqlite3 .backup`, checked, gzip | `<web root>/db/.backups/gallery-<UTC time>.db.gz` | the newest 5 |
| The database, off the host | each deploy's `Copy-LiveDatabase`, unless `-NoDbPull` | the file `backend/.env`'s `DB_DATABASE` names on the dev machine | the 3 previous copies |
| The originals and the ingest folder | each deploy's `Sync-LiveFolder` (new and changed files only, the thumbnails left out), unless `-NoDbPull` | `devdata\media` in the dev machine's checkout, git-ignored | one copy; a file deleted on the host stays in it |

A host-only folder up to 250 MB is archived on the host daily (`Install-BackupTimer -Folder`); a larger one is copied to the dev machine instead.

| Folder | Size, measured 2026-11-16 | Kind |
| --- | --- | --- |
| `<web root>/media/full` | 9.4 GB, 5372 files | host-only, over 250 MB: the `devdata\media` copy |
| `<web root>/media`, its top (the ingest folder) | 0 files | host-only while a file waits for the midnight ingest: in the same copy |
| `<web root>/media/thumbs` | 98 MB, 10718 files | not host-only: `php artisan gallery:regenerate thumbnails` rebuilds it |
| `<web root>/dupes` | 41 KB, 280 files | not host-only: a scan writes its report again from the database |

The database was 22 MB, its daily copies 52 MB and the deploy's snapshots 46 MB (2026-11-16).

## Restore steps

The database, from a daily copy (or a deploy snapshot in `db/.backups/`, the same way):

```bash
cd <web root>
sudo -u www-data php artisan down
systemctl stop gallery-queue
rm -f db/gallery.db-wal db/gallery.db-shm
gunzip -c db/backups/gallery-YYYY-MM-DD.db.gz > db/gallery.db    # keeps the file's owner and mode (660 www-data)
systemctl start gallery-queue
sudo -u www-data php artisan up
```

The originals, from `devdata\media` on the dev machine. Pack and send them on the deploy's connection (PowerShell, from the checkout):

```powershell
Import-Module .\shared\deploy\pwsh\SharedDeploy.psd1
$s = Resolve-DeploySetting -ConfigPath .\scripts\deploy.config.ps1 -Value @{} -Required VpsHost, VpsUser, KeyPath
& (Resolve-TarTool) --format=pax --exclude=.shared-deploy-sync -czf media-restore.tar.gz -C devdata media
$null = $s | Open-DeployConnection
try { $null = Invoke-Upload -Path media-restore.tar.gz -Destination 'media-restore.tar.gz' } finally { Close-DeployConnection }
```

Then on the host:

```bash
cd <web root>
sudo -u www-data php artisan down
systemctl stop gallery-queue
mkdir /root/media-restore && tar -xzf ~/media-restore.tar.gz -C /root/media-restore
mv media/full media/full.before-restore
mv /root/media-restore/media/full media/full
chown -R www-data:www-data media/full
find media/full -type d -exec chmod 2775 {} + ; find media/full -type f -exec chmod 664 {} +
systemctl start gallery-queue
sudo -u www-data php artisan up
sudo -u www-data php artisan gallery:regenerate thumbnails   # only if the thumbnails went too
```

The thumbnails: `sudo -u www-data php artisan gallery:regenerate thumbnails`. The duplicate reports: run a scan (the Duplicates page, or `sudo -u www-data php artisan gallery:dupes`).

## Restore drill

The owner decided on 2026-11-15 that no drill is run on the host. When one is wanted, it runs on the dev machine, against copies:

1. Fetch the newest daily copy (`ls <web root>/db/backups | tail -n 1` on the host) with `Get-File '<web root>/db/backups/gallery-YYYY-MM-DD.db.gz' "$env:TEMP\drill\gallery.db.gz"` on a deploy connection, opened as in Restore steps.
2. Decompress it (`gunzip -k`, in WSL or Git Bash). `sqlite3 gallery.db 'pragma integrity_check'` prints `ok`, and `select count(*) from media` matches `https://gallery.mathdad.me/api/media/count`, less what was added since that day.
3. Count `devdata\media\full`'s files against the host's `find <web root>/media/full -type f | wc -l`, and compare three files' `Get-FileHash` with the host's `sha256sum <web root>/media/full/<name>`.
4. Write the date here.

Last drill on the host: none, by the owner's decision of 2026-11-15.

## Production-only checks

After every deploy, in a fresh browser tab with the console open:

- The gallery loads with no console errors, among them no "Refused to …" line from the Content-Security-Policy. Sign in, and Application → Cookies shows `__Host-gallery-session`, Secure and HttpOnly. The sign-in page draws Cloudflare's Turnstile check.
- `/api/docs` draws the API reference.
- `curl -sI https://gallery.mathdad.me/ | grep -iE 'strict-transport-security|content-security-policy|referrer-policy'` shows `max-age=31536000`, a policy with a nonce, and `same-origin`, and `curl -sI https://gallery.mathdad.me/ | grep -ci '^x-content-type-options'` prints `1`: Apache adds the header for its own files and must not repeat it on the app's answers.
- An upload (as an admin) gets its thumbnail within a minute: the worker ran ffmpeg and GD under its hardening.
- On the host: `systemctl is-active gallery-queue` is `active`, `ps -o user= -p "$(systemctl show -p MainPID --value gallery-queue)"` is `<service user>`, and `journalctl -u gallery-queue -n 30` shows no errors.
- `tail -n 3 /var/log/apache2/access.log`, after loading a page, shows your own address, not a Cloudflare one.
- `curl -s -o /dev/null -w '%{http_code}\n' https://gallery.mathdad.me/assets/missing.js` is 404, and so is `/media/thumbs/` (no listing).
- `ls /etc/apache2/sites-enabled | grep gallery` lists one site; `stat -c '%a %U:%G' /etc/cron.d/gallery-scheduler` is `644 root:root`.

### When the policy blocks something

If the live site breaks because the Content-Security-Policy refuses something (a blank page, Turnstile not drawing, a console line "Refused to …"), switch the policy to report-only first, then fix it: set `GALLERY_CSP_REPORT_ONLY=true` in `<web root>/.env` and run `sudo -u www-data php artisan optimize --except=views` (the config is cached). The browser then reports what it would refuse and refuses nothing. Fix the cause in code, deploy, set it back to `false` and run `optimize` again.

## Upgrading to 8.0.0

The API moves to 8.0.0 (its clients change with it, and the SPA ships with it). The queue worker moves to an account of its own, hardened; the folders it shares with PHP-FPM become group-writable; the vhost gains HSTS and takes the visitor's address from Cloudflare; several settings are renamed; the deploy copies the media to the dev machine. In order:

1. **On the droplet, as root, just before `task deploy`.** The deploy refuses to run until the account and the folder modes hold. 7.3.0 keeps working with all of it, so it can run any time, but run it again right before the deploy: 7.3.0 keeps making cache and log files 644 and cache folders 755, which the new worker can neither write nor change. Every line is safe to run again; the first run keeps the previous modes and `.env` in `/root/gallery-8.0.0-before/`.

   ```bash
   W='<web root>'
   SU='<service user>'
   K=/root/gallery-8.0.0-before

   # What changes, kept once: every mode under storage, db and media, and .env.
   if [ ! -e "$K" ]; then
     install -d -m 700 "$K"
     { find "$W" -maxdepth 0 -printf '%m %u:%g %p\n'; find "$W/storage" "$W/db" "$W/media" -printf '%m %u:%g %p\n'; } | gzip -9 > "$K/modes.txt.gz"
     cp -p "$W/.env" "$K/env"
   fi

   # The worker's account: locked, no home, no shell, www-data its primary group.
   id -u "$SU" >/dev/null 2>&1 || useradd --system --gid www-data --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$SU"
   passwd -S "$SU"    # the second field is L: locked

   # The site's folder: root's, writable by no one else (it was 777).
   chown root:root "$W" && chmod 755 "$W"

   # storage/: setgid, group-writable. The file cache's folders and files exactly 0770, as the app makes them:
   # 00770, since chmod 770 keeps a folder's setgid bit, and the app re-chmods a folder whose mode differs,
   # which fails when the worker meets one PHP-FPM made.
   chown -R www-data:www-data "$W/storage"
   find "$W/storage" -type d -exec chmod 2775 {} +
   find "$W/storage" -type f -exec chmod 664 {} +
   find "$W/storage/framework/cache/data" -mindepth 1 -type d -exec chmod 00770 {} +
   find "$W/storage/framework/cache/data" -type f -exec chmod 770 {} +

   # The database: its folder closed to others (it was 777); the file and its -wal and -shm 660 (they were 755).
   chown www-data:www-data "$W/db" && chmod 2770 "$W/db"
   for f in "$W/db/gallery.db" "$W/db/gallery.db-wal" "$W/db/gallery.db-shm"; do if [ -e "$f" ]; then chown www-data:www-data "$f"; chmod 660 "$f"; fi; done
   chmod 700 "$W/db/.backups" && find "$W/db/.backups" -type f -exec chmod 600 {} +

   # The media: setgid, group-writable folders; files 664 (most were 755).
   chown -R www-data:www-data "$W/media"
   find "$W/media" -type d -exec chmod 2775 {} +
   find "$W/media" -type f -exec chmod 664 {} +

   # .env: each renamed setting added under its new name beside the old one, its value copied, never shown.
   [ -z "$(tail -c 1 "$W/.env")" ] || printf '\n' >> "$W/.env"
   for old in CLOUDFLARE_TURNSTILE_SITEKEY CLOUDFLARE_TURNSTILE_SECRET DANBOORU_LOGIN DANBOORU_API_KEY DANBOORU_USER_ID DUPLICATE_MAX_DISTANCE DUPLICATE_MAX_REGION_DIFF SCHEDULE_TIMEZONE; do
     new="GALLERY_${old#CLOUDFLARE_}"
     grep -qE "^$new=" "$W/.env" || grep -E "^$old=" "$W/.env" | head -n 1 | sed "s/^$old=/$new=/" >> "$W/.env"
   done
   ```

   Apache needs nothing new: `mod_remoteip` and `mod_headers` are loaded and `/etc/apache2/cloudflare-ips.txt` is there (checked 2026-11-16). Without them the deploy would stop before changing anything. The block first ran on the droplet on 2026-11-16: the account, every mode above and the settings' new names are in place, and the previous modes and `.env` are in `/root/gallery-8.0.0-before/`. What's left is the run just before the deploy.

2. **The settings' new names** go into `<web root>/.env` beside the old ones, through the block above. 7.3.0 reads only the old names and 8.0.0 only the new, so both work until the cleanup in step 7 takes the old ones out, and a rollback needs nothing put back. A setting under its old name alone is ignored by 8.0.0 without a word: Turnstile would switch off, Danbooru would read as not configured, the duplicate scan would use its defaults and the schedule would run in UTC. Rename them in your local `backend/.env` too.

   | Was | Now |
   | --- | --- |
   | `CLOUDFLARE_TURNSTILE_SITEKEY` | `GALLERY_TURNSTILE_SITEKEY` |
   | `CLOUDFLARE_TURNSTILE_SECRET` | `GALLERY_TURNSTILE_SECRET` |
   | `DANBOORU_LOGIN` | `GALLERY_DANBOORU_LOGIN` |
   | `DANBOORU_API_KEY` | `GALLERY_DANBOORU_API_KEY` |
   | `DANBOORU_USER_ID` | `GALLERY_DANBOORU_USER_ID` |
   | `DUPLICATE_MAX_DISTANCE` | `GALLERY_DUPLICATE_MAX_DISTANCE` |
   | `DUPLICATE_MAX_REGION_DIFF` | `GALLERY_DUPLICATE_MAX_REGION_DIFF` |
   | `SCHEDULE_TIMEZONE` | `GALLERY_SCHEDULE_TIMEZONE` |

   `GALLERY_CSP_REPORT_ONLY` is new and off unless set (When the policy blocks something). The deploy runs `optimize`, which caches the renamed values.

3. **On the dev machine:** `$DeployServiceUser = '<service user>'` in `scripts/deploy.config.ps1` (done 2026-11-16). `-PublicHost` is gone: the site's name is the `ServerName` setting, `gallery.mathdad.me` unless set.

4. **Push to `main` and let CI tag the release, then `task deploy`.** Besides the release:
   - the migrations drop the Slim app's leftover tables (`auth_tokens`, `rate_limits`, `phinx_migrations`) and rewrite the stored times as RFC 3339 text, after the deploy's snapshot;
   - the vhost is replaced (HSTS, the visitor's address from Cloudflare, no folder listings) and Apache reloads;
   - the cron file and the worker's unit are replaced, and the worker restarts as `<service user>`;
   - the first copy of the media folder downloads about 9.4 GB into `devdata\media`, so this deploy takes longer.

5. **Check it** with the production-only checks above.

6. **The worker's memory cap,** once it has done heavy work: queue a rebuild that decodes the largest originals (`sudo -u www-data php artisan gallery:regenerate fingerprints`), let it finish, then read the highest peak of its hourly runs with `journalctl -u gallery-queue --since today -o cat | grep -o '[0-9.]*[KMG] memory peak' | sort -h | tail -n 1`. Set `MemoryMax=` in `deploy/gallery-queue.service` to twice it, rounded up to a multiple of 16M, record the peak, the cap and the date under Host setup, and ship it with the next release.

7. **A few days later, clean up** what nothing uses any more. Check it first, then:

   ```bash
   W='<web root>'
   rm -rf "$W/.deploy-host"     # the previous deploys' unit and cron copies; the deploy keeps them in /var/lib/shared-deploy/gallery now
   # The settings' old names, which 8.0.0 doesn't read.
   sed -i -E '/^(CLOUDFLARE_TURNSTILE_SITEKEY|CLOUDFLARE_TURNSTILE_SECRET|DANBOORU_LOGIN|DANBOORU_API_KEY|DANBOORU_USER_ID|DUPLICATE_MAX_DISTANCE|DUPLICATE_MAX_REGION_DIFF|SCHEDULE_TIMEZONE)=/d' "$W/.env"
   stat -c '%a %U:%G' "$W/.env"    # 640 www-data:www-data
   rm -rf /root/gallery-8.0.0-before   # step 1's copy of the previous modes and .env
   ```

   The Slim app as it last ran (`<web root>/slim-rollback`) and the vhost files that pointed Apache at it (`<web root>/backups/rollback-vhosts`) were deleted on 2026-11-16, before this release. `<web root>/backups/ROLLBACK.txt` still describes going back to the Slim app; with its code gone and its tables dropped, those steps no longer apply. The pre-cutover backup beside it stays.

If the deploy fails, it puts the previous code, vhost, unit and cron file back by itself, and the worker restarts on the previous code. The database stays as the migrations left it, and 7.3.0 can't read the times the migration rewrote: if the deploy rolled back after its migration ran (a failed smoke check, or the worker not staying up), put the database back from the snapshot with the command the deploy printed (Restore steps), accepting that an upload made in those minutes goes with it. The account, the folder modes and the settings' new names do no harm to 7.3.0, which still finds the old names beside them.

## Upgrading to 7.3.0

The deploy setting for the Laravel app's folder on the droplet is `WebRoot` (`$DeployWebRoot` in `scripts/deploy.config.ps1`, `DEPLOY_WEB_ROOT`, `-WebRoot`); it was `AppRoot`. Rename it in `scripts/deploy.config.ps1`. Until it is renamed the deploy stops before it connects, naming the setting.

The Apache configuration is one vhost file now, where it was two (a port-80 file and certbot's `-le-ssl` file). The deploy does the change; nothing is done by hand for it.

## Upgrading to 7.2.0

The first deploy after 7.1.0 does this, whichever release it ships. Sessions move from files to the database, the session cookie is renamed, passwords move to argon2id, and the server files start shipping. `scripts/deploy.config.ps1` must already hold every setting `scripts/deploy.config.example.ps1` lists; the deploy stops before it connects if one is missing. The droplet's `.env` must say `SESSION_DRIVER=database`, with `APP_URL` the `https://` address people use and `SESSION_DOMAIN`, `SESSION_COOKIE` and `SESSION_SECURE_COOKIE` empty and `SESSION_PATH=/`, which is what gives the cookie its `__Host-` name. In order:

1. **Push to `main` and let CI tag the release.** The deploy ships the newest tag, never a commit: wait for the run on `main` to finish green, and `git fetch --tags && git tag --sort=-creatordate` lists the new `v<version>` first.

2. **Deploy** with `task deploy`. The migration creates the `sessions` table; everyone signs in again once, because the old sessions were files and the cookie has a new name, and a browser that was kept signed in signs itself back in.

3. **Check it,** in a fresh browser tab with the console open: sign in, and look under Application → Cookies for `__Host-gallery-session`, marked Secure and HttpOnly. On the droplet, `sqlite3 <web root>/db/gallery.db "select substr(password, 1, 31) from users where username = 'your-username'"`, after that sign-in, starts `$argon2id$v=19$m=65536,t=1,p=4$`. With `p=1` PHP's argon2 comes from libsodium, which has one lane: the hash is still argon2id at 64 MiB, and nothing needs doing.

4. **A few days later, clean up** the old session files and the retired site's file:

   ```bash
   find <web root>/storage/framework/sessions -type f -delete
   rm /etc/apache2/sites-available/gallery.mathdad.me-le-ssl.conf
   sqlite3 <web root>/db/gallery.db "select count(*) from users where password like '\$2y\$%'"
   ```

   The count is the accounts still on bcrypt: those that have not signed in with their password since the deploy. Nothing needs doing about them.

## Rolling back

The deploy's own rollback puts back the code (from `<web root>/.deploy-backup`), the vhost (`/etc/apache2/sites-available/gallery.mathdad.me.conf.old`, its `.rollback` record, and any site it disabled), and the unit and cron file (`/var/lib/shared-deploy/gallery`: the `*.old` copies and the `rollback` record), and restarts the worker on the previous code. When that didn't happen, do the same by hand from the checkout, in PowerShell:

```powershell
Import-Module .\shared\deploy\pwsh\SharedDeploy.psd1
$s = Resolve-DeploySetting -ConfigPath .\scripts\deploy.config.ps1 -Value @{} -Required VpsHost, VpsUser, KeyPath, WebRoot -Default @{ ServerName = 'gallery.mathdad.me' }
$null = $s | Open-DeployConnection
try {
    Invoke-RemoteStep -Script @(
        Restore-RemoteItemSet -AsScript -Root $s.WebRoot -ServerName $s.ServerName -Item app, bootstrap, config, database, public, routes, artisan, composer.json, composer.lock, openapi.json, resources, package.json, vendor
        Restore-HostConfig -AsScript -Name gallery -Service gallery-queue
    )
} finally { Close-DeployConnection }
```

It needs the previous copies, so it can't follow a `-NoBackup` deploy. The database stays as the migrations left it; to put it back, use the snapshot's command the deploy printed, the same as Restore steps with `db/.backups/gallery-<UTC time>.db.gz`.

## Logs

- The app (PHP-FPM, the worker, the scheduler's commands): `<web root>/storage/logs/laravel.log`.
- The queue worker: `journalctl -u gallery-queue` (each hourly run's `Consumed … memory peak` line on exit, and anything it prints).
- The scheduler: cron's own lines, `journalctl -u cron | grep gallery` (the schedule's output goes to `/dev/null`; its work logs to `laravel.log`).
- The daily backup: `journalctl -t gallery-backup`, and `systemctl list-timers gallery-backup.timer`.
- Apache: `/var/log/apache2/error.log` and `access.log`, with PHP's errors in the error log as `[proxy_fcgi:error] … AH01071: Got error 'PHP message: …'` lines; PHP-FPM's own `/var/log/php8.5-fpm.log`.
