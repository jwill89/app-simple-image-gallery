# Operations

What happens on the droplet beyond the deploy itself: how the server files ship, and the steps a release needs. How a deploy works is in [AGENTS.md](../AGENTS.md#deploy-to-the-digitalocean-droplet); the Slim-to-Laravel move, and the layout of the droplet's folder, are in [laravel-migration.md](laravel-migration.md).

## The server files

The two Apache VirtualHosts, the queue worker's unit and the scheduler's cron file are templates in `deploy/`. Every deploy fills in their host values from `scripts/deploy.config.ps1` (the app folder, the certificate folder and the `ServerAdmin` address; the site's name is `-PublicHost`) and installs each file on the droplet where it differs from the live one:

| Template | Installed as |
| --- | --- |
| `deploy/gallery-http.conf` | `/etc/apache2/sites-available/<site>.conf` |
| `deploy/gallery-https.conf` | `/etc/apache2/sites-available/<site>-le-ssl.conf` |
| `deploy/gallery-queue.service` | `/etc/systemd/system/gallery-queue.service` |
| `deploy/gallery-scheduler.cron` | `/etc/cron.d/gallery-scheduler` |

Apache's `configtest` must take the vhosts before the code is swapped in, Apache reloads once the deploy has finished its work if a vhost changed, and the queue worker restarts on a changed unit and must be active two seconds later. A deploy that fails afterwards, or whose site check fails, puts the previous files back with the previous code. Nobody edits these files on the droplet: the next deploy replaces them. A change goes into the template.

## Upgrading to 7.2.0

Sessions move from files to the database, the session cookie is renamed, passwords move to argon2id, and the server files above start shipping with the deploy. Do these in order.

1. **Add two settings to `scripts/deploy.config.ps1`** (the example next to it shows them). The deploy stops before it builds or connects while either is missing.

   ```powershell
   $DeployCertName = '<the folder under /etc/letsencrypt/live that the live vhost uses>'
   $DeployServerAdmin = '<the live vhost\'s ServerAdmin address>'
   ```

   Both are in `/etc/apache2/sites-available/<site>-le-ssl.conf` (`SSLCertificateFile`, `ServerAdmin`).

2. **Switch the session driver in the droplet's `.env`**, before deploying. The running site reads its cached config, so nothing changes until the deploy.

   ```bash
   sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=database/' /var/www/gallery.mathdad.me/.env
   grep -E '^(APP_URL|SESSION_[A-Z_]+)=' /var/www/gallery.mathdad.me/.env
   ```

   The second command shows what decides the cookie's name. `APP_URL` must be the `https://` address people use, and `SESSION_DOMAIN`, `SESSION_PATH` and `SESSION_COOKIE` must be empty, `null` or `/` (or absent); otherwise the cookie keeps the plain name `gallery-session`. A `BCRYPT_ROUNDS` line is no longer read and can go.

3. **Deploy** with `task deploy`. Besides the release itself:
   - the migration creates the `sessions` table. Everyone signs in again once, because the old sessions were files and the cookie has a new name; a browser that was kept signed in (the 30-day cookie) signs itself back in. If step 2 was missed, the deploy ends with a warning that the sessions are not in the database;
   - the vhosts, the unit and the cron file are installed, which changes their whitespace and comments (and the unit's description); Apache reloads and the queue worker restarts once.

4. **Check it**, in a fresh browser tab with the console open: sign in, and look under Application → Cookies for `__Host-gallery-session`, marked Secure and HttpOnly. On the droplet:

   ```bash
   systemctl is-active gallery-queue
   stat -c '%a %U:%G' /etc/cron.d/gallery-scheduler
   sqlite3 /var/www/gallery.mathdad.me/db/gallery.db 'select count(*) from sessions'
   ```

   The first prints `active`, the second `644 root:root`, and the count is the number of browsers that have visited since the deploy.

5. **See the passwords change.** An account's old bcrypt hash is replaced the first time it signs in with its password (a remembered browser or a passkey does not do it). After yours:

   ```bash
   sqlite3 /var/www/gallery.mathdad.me/db/gallery.db "select substr(password, 1, 31) from users where username = 'your-username'"
   ```

   It starts `$argon2id$v=19$m=65536,t=1,p=4$`. With `p=1` PHP's argon2 comes from libsodium, which has one lane: the hash is still argon2id at 64 MiB, and nothing needs doing. Accounts still on bcrypt: `select count(*) from users where password like '$2y$%'`.

6. **Remove the old session files**, which nothing reads now:

   ```bash
   find /var/www/gallery.mathdad.me/storage/framework/sessions -type f -delete
   ```

If the deploy fails, it puts the previous code and server files back by itself. The database stays as it was left, and the new `sessions` table does no harm to the previous code.
