# Operations

What happens on the droplet beyond the deploy itself: how the server files ship, and the steps a release needs. How a deploy works is in [AGENTS.md](../AGENTS.md#deploy-to-the-digitalocean-droplet); the Slim-to-Laravel move, and the layout of the droplet's folder, are in [laravel-migration.md](laravel-migration.md).

## The server files

The Apache vhost, the queue worker's unit and the scheduler's cron file are templates in `deploy/`. Every deploy fills in their host values from `scripts/deploy.ps1` and `scripts/deploy.config.ps1` (the web root, the certificate folder and the `ServerAdmin` address; the site's name is `-PublicHost`) and installs each file on the droplet where it differs from the live one:

| Template | Installed as |
| --- | --- |
| `deploy/apache-vhost.conf` | `/etc/apache2/sites-available/<site>.conf`, enabled |
| `deploy/gallery-queue.service` | `/etc/systemd/system/gallery-queue.service` |
| `deploy/gallery-scheduler.cron` | `/etc/cron.d/gallery-scheduler` |

The vhost is one file for the site, `<site>` being the lower-case `-PublicHost`: the port-80 redirect and the https site together. The deploy disables every other enabled site that serves the name, such as certbot's `<site>-le-ssl.conf` (the file stays in `sites-available`), and refuses, changing nothing, if one of them serves another name or the new file drops a port. It tries Apache on a copy of its config first, and `configtest` must take the real change before the code is swapped in. Apache reloads once the deploy has finished its work, and the queue worker restarts on a changed unit and must be active two seconds later. A deploy that fails afterwards, or whose site check fails, puts the previous files and the retired sites back with the previous code.

Nobody edits these files on the droplet: the next deploy replaces them, and disables any `-le-ssl` site certbot writes again. A change goes into the template.

## Upgrading to 7.3.0

The deploy setting for the Laravel app's folder on the droplet is `WebRoot` (`$DeployWebRoot` in `scripts/deploy.config.ps1`, `DEPLOY_WEB_ROOT`, `-WebRoot`); it was `AppRoot`. Rename it in `scripts/deploy.config.ps1`. Until it is renamed the deploy stops before it connects, naming the setting.

The Apache configuration is one vhost file now, where it was two (a port-80 file and certbot's `-le-ssl` file). The deploy does the change; nothing is done by hand for it.

## Upgrading to 7.2.0

The first deploy after 7.1.0 does this, whichever release it ships (7.2.0 or a later one). Sessions move from files to the database, the session cookie is renamed, passwords move to argon2id, and the server files above start shipping. `scripts/deploy.config.ps1` must already hold every setting `scripts/deploy.config.example.ps1` lists; the deploy stops before it connects if one is missing. The droplet's `.env` must say `SESSION_DRIVER=database`, with `APP_URL` the `https://` address people use and `SESSION_DOMAIN`, `SESSION_COOKIE` and `SESSION_SECURE_COOKIE` empty and `SESSION_PATH=/`, which is what gives the cookie its `__Host-` name; a deploy that ends with a warning that the sessions are not in the database found it set otherwise. In order:

1. **Push to `main` and let CI tag the release.** The deploy ships the newest tag, never a commit: wait for the run on `main` to finish green, and `git fetch --tags && git tag --sort=-creatordate` should list the new `v<version>` first.

2. **Deploy** with `task deploy`. Besides the release itself:
   - the migration creates the `sessions` table. Everyone signs in again once, because the old sessions were files and the cookie has a new name; a browser that was kept signed in (the 30-day cookie) signs itself back in;
   - the vhost becomes one file, `<site>.conf`, and the `-le-ssl` site is disabled in its favour; the unit and the cron file are replaced (whitespace, comments and the unit's description change). Apache reloads and the queue worker restarts once.

3. **Check it,** in a fresh browser tab with the console open: sign in, and look under Application → Cookies for `__Host-gallery-session`, marked Secure and HttpOnly. On the droplet:

   ```bash
   ls /etc/apache2/sites-enabled | grep gallery
   systemctl is-active gallery-queue
   stat -c '%a %U:%G' /etc/cron.d/gallery-scheduler
   sqlite3 /var/www/gallery.mathdad.me/db/gallery.db "select substr(password, 1, 31) from users where username = 'your-username'"
   ```

   The first lists one site, `gallery.mathdad.me.conf`. The second prints `active`, the third `644 root:root`. The fourth, after that sign-in, starts `$argon2id$v=19$m=65536,t=1,p=4$`: an account's old bcrypt hash is replaced the first time it signs in with its password (a remembered browser or a passkey does not do it). With `p=1` PHP's argon2 comes from libsodium, which has one lane: the hash is still argon2id at 64 MiB, and nothing needs doing.

4. **A few days later, clean up.** The old session files and the retired site's file are unused:

   ```bash
   find /var/www/gallery.mathdad.me/storage/framework/sessions -type f -delete
   rm /etc/apache2/sites-available/gallery.mathdad.me-le-ssl.conf
   sqlite3 /var/www/gallery.mathdad.me/db/gallery.db "select count(*) from users where password like '\$2y\$%'"
   ```

   The count is the accounts still on bcrypt: those that have not signed in with their password since the deploy. Nothing needs doing about them.

If the deploy fails, it puts the previous code, vhost and server files back by itself. The database stays as it was left, and the new `sessions` table does no harm to the previous code.
