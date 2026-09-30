# Deploying Flashcards on the DreamHost VPS

One directory, one database, on the panel-managed DreamHost VPS (no root;
everything is done from the DreamHost panel and a shell as the site user),
plus a private Cloudflare R2 bucket for card images.

| Hostname | What it shows |
|---|---|
| `flashcards.brianrosenthal.org` | Login, everyone's decks (`/manage/`), Study, Quiz, Stats, admin, and every user's public page at `/{slug}/`. |
| a custom domain (optional, later) | That user's public page at `/`. One panel entry per domain; see *Adding a domain later*. |

PHP decides whose page to render from the first path segment or the hostname
(`www/lib/SiteResolver.php`, using `request_host()` in `www/config.php`), so
every hostname simply reaches the same document root.

## 1. DreamHost panel

1. *Websites → Manage Websites → Add Website* for `flashcards.brianrosenthal.org`.
   Choose **Fully hosted** (a *Mirror* domain cannot get HTTPS). Set **Web
   directory** to `/home/brosenthvps/flashcards.brianrosenthal.org` (it will
   hold the contents of the repo's `www/`). Pick the PHP version the other
   sites use (PHP 8.x; the app needs `gd`, `exif`, `curl`, `mbstring`,
   `pdo_mysql` and `iconv`). Enable **Let's Encrypt** and the HTTPS-only
   redirect. Leave *Add WWW* off.
2. *MySQL Databases*: create the database `flashcards_brianrosenthal` on the
   existing MySQL hostname (e.g. `mysql.brianrosenthal.org`) with a user that
   has full access to it.

`.htaccess` rewrites are honoured by default on panel-managed hosting.

### phprc

Card images pass through PHP (it validates, orients and resizes them before
sending them to R2), so the PHP limits must allow the upload. In the panel's
per-domain `phprc` (`~/.php/8.x/phprc`, *Manage Websites → the domain →
PHP → Edit phprc*) set:

```
upload_max_filesize = 12M
post_max_size = 16M
memory_limit = 256M
```

`IMAGE_MAX_BYTES` in `config.local.php` (default 10 MB) must stay below
`upload_max_filesize`. The image code raises its own memory limit to 256M
while decoding, so the `memory_limit` line matters only if the panel's default
is lower and cannot be raised at runtime.

## 2. Files on the server

Everything runs as one Linux user:

```
~/flashcards.brianrosenthal.org/                    # DocumentRoot = a copy of the repo's www/
~/flashcards.brianrosenthal.org/config.local.php    # secrets, never in git
~/flashcards.brianrosenthal.org/logs/               # writable by the web user; .htaccess denies web access
```

Deploy with rsync: copy `deploy/deploy.sh.example` to `deploy/deploy.sh` and
run `bash deploy/deploy.sh`. It never overwrites `config.local.php` or logs.

`config.local.php` values that matter in production (see
`www/config.local.php.example` for every option):

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — the MySQL database from step 1.
- `APP_NAME = 'Flashcards'`, `SMTP_FROM_NAME`.
- `MAIN_HOST = 'flashcards.brianrosenthal.org'`.
- `LEGACY_HOSTS = []` — hostnames that should 301 to `MAIN_HOST` (none yet).
- `SUBDOMAIN_PROXY_KEY = ''` — only needed once a dedicated domain with
  wildcard subdomains exists (see *Adding a domain later*).
- `SMTP_*` — for activation and password-reset emails. Links in emails use the
  `site_base_url` setting (Admin → Settings), so set it to
  `https://flashcards.brianrosenthal.org`.
- `REMEMBER_TOKEN_KEY` — a long random string (`php -r 'echo bin2hex(random_bytes(32));'`).
- `SUPER_PASSWORD` — leave `''` in production.
- `R2_ENDPOINT`, `R2_ACCESS_KEY`, `R2_SECRET_KEY`, `R2_IMAGE_BUCKET`,
  `IMAGE_MAX_BYTES` — see step 4.

## 3. Database

First install, from a shell on the server (or any machine that can reach the
MySQL host):

```bash
mysql -h mysql.brianrosenthal.org -u USER -p -e 'CREATE DATABASE flashcards_brianrosenthal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql -h mysql.brianrosenthal.org -u USER -p flashcards_brianrosenthal < www/schema.sql
```

`schema.sql` seeds the admin `brian.rosenthal@gmail.com` (password
`flashcards`) with his page at `/brian/`, the settings rows, and records
`001_initial_schema.sql` as applied. **Change the seeded admin password
immediately** (profile menu → Change Password) and check Admin → Settings.

Later releases: after deploying, apply pending migrations from **Admin →
Migrations** in the browser or with
`bash ~/flashcards.brianrosenthal.org/db_migrations/migrate.sh` on the server
(`--dry-run` first if unsure). Both record applied files in
`schema_migrations`, so they can be mixed freely.

## 4. Image storage (Cloudflare R2)

Card images live in a private S3-compatible bucket, not on the server. The
secret key never leaves PHP: uploads go browser → PHP (resize) → R2, and
pages show images through presigned GET URLs whose timestamp is rounded down
to a 6-hour window (cacheable) and which stay valid for 24 hours. R2 charges
nothing for egress.

1. Cloudflare dashboard → *R2 Object Storage* → **Create bucket** named
   `flashcards-images`. Leave it private: no public access, no custom domain.
   On the bucket's *Settings* tab copy the **S3 API** URL,
   `https://<account-id>.r2.cloudflarestorage.com/flashcards-images`.
2. *R2 Object Storage* → *Manage R2 API Tokens* → **Create API token**:
   permission *Object Read & Write*, scoped to the `flashcards-images` bucket.
   Copy the *Access Key ID* and *Secret Access Key* (shown once).
3. In `config.local.php`: the S3 API URL as `R2_ENDPOINT` (with or without the
   trailing `/flashcards-images`, both work), the two keys as `R2_ACCESS_KEY` /
   `R2_SECRET_KEY`, and `R2_IMAGE_BUCKET = 'flashcards-images'`. Leave
   `R2_REGION` unset (`auto`).
4. **Admin → Image Storage** should read *Ready* with the bucket present
   (**Create bucket** is there too if you skipped step 1). Click **Test
   upload**: it puts a tiny generated PNG, HEADs it, fetches it through a
   display URL and deletes it, printing the raw storage response, so any
   misconfiguration shows up here before a user hits it.
5. Add a card with an image from *My Decks → a deck → Cards → Add card* and
   check that it shows on the card list and on the public page.

The same admin page reconciles the database against the bucket (missing
objects are linked to their card editors; orphans can be deleted). Deleting a
card, replacing its image, or deleting a deck removes the objects from R2.

## 5. Smoke test after deploying

- `https://flashcards.brianrosenthal.org/` → login page; sign in → `/manage/`.
- Admin → Users → add a user; the activation email appears in Admin → Email
  Log and their page is created automatically with a slug from their first
  name (Admin → Pages lists it).
- `https://flashcards.brianrosenthal.org/{slug}/` → their public page (404
  "This page is not public yet" if they turned *public* off in Page settings).
  Signed in as them or as an admin, the dark owner bar shows above it.
- Create a category and a deck, add a text card and an image card, then Study
  (flip, Got it / Need more review, flag, shuffle, resume after reload) and
  Quiz (typed answers, hints, "I was right anyway"); Stats moves; Admin →
  Activity Log shows each write.
- Sign out: the public page still browses and flips; Study saves nothing and
  Quiz asks to sign in.

## Adding a domain later

The app is written so that a dedicated domain (say `myflashcards.com`) can
be added without code changes:

1. Set `MAIN_HOST = 'myflashcards.com'` in `config.local.php` and add the old
   hostname to `LEGACY_HOSTS` so existing links 301 to the new host. Update
   `site_base_url` in Admin → Settings. Add the domain in the DreamHost panel
   (fully hosted, the same web directory, Let's Encrypt).
2. Every page is then also served at `{slug}.myflashcards.com`. DreamHost's
   managed hosting cannot host a wildcard domain, so put the domain's DNS on
   Cloudflare and use a Worker that forwards `*.myflashcards.com` to the
   main host with the visitor's hostname in `X-Forwarded-Host` and a shared
   secret in `X-Site-Proxy-Key`; `request_host()` trusts the header only when
   the secret equals `SUBDOMAIN_PROXY_KEY`. The Worker and its setup steps can
   be borrowed as-is from the sibling repo
   `mastery.brianrosenthal.org/deploy/cloudflare-worker/` (change
   `ORIGIN_HOST`). The session cookie's `Domain` is `MAIN_HOST` there, so one
   login covers the main host and all subdomains.
3. A user who wants their own domain (`flashcards.charlierosenthal.org`):
   add it in the DreamHost panel like the main one (fully hosted, same web
   directory, Let's Encrypt; DNS `A` record to the VPS), then Admin → Pages →
   that page's Settings → Routing → Custom domain. Custom domains keep
   host-only cookies, so the owner signs in there separately (the login page
   is branded with their page title).
