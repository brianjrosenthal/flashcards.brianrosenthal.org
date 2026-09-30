See ALL FILES in the docs/ directory: `docs/spec.md` (what the app does),
`docs/php-guidelines.md` (coding conventions, followed throughout) and
`docs/deployment.md` (DreamHost + Cloudflare R2 runbook).

## Local development

- Create a MySQL database `flashcards_brianrosenthal` and load `www/schema.sql`
  into it (local MySQL is `root` with no password).
- Copy `www/config.local.php.example` to `www/config.local.php` and fill in the
  DB credentials. The Cloudflare R2 keys are optional locally: with
  `R2_ACCESS_KEY` empty, image upload is disabled and text-only cards still work.
- Run: `php -S localhost:8080 -t www deploy/dev-router.php` (the router mimics
  `.htaccess` so pretty URLs like `/charlie/us-history/presidents/` work).
- Sign in with the seeded admin: email `brian.rosenthal@gmail.com`, password
  `flashcards` (change it after first login). Setting `SUPER_PASSWORD` in
  `config.local.php` lets you sign in as any user with that password, which is
  handy for trying a non-admin account; leave it `''` in production.
- A user's public page is at `/{slug}/` on any host (locally
  `http://localhost:8080/brian/`). Once a dedicated domain exists it is also
  served at `{slug}.MAIN_HOST` and at the user's custom domain
  (`sites.domain`); see `docs/deployment.md`.
- Tests: `php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml`. The
  bootstrap drops and recreates `flashcards_brianrosenthal_test` from
  `www/schema.sql` on every run and fakes the image storage. Set
  `FLASHCARDS_TEST_DB=some_other_name` to run against a different scratch
  database (for instance when two test runs share one MySQL server).
- When the database changes, update `www/schema.sql` to the complete current
  state AND add a migration in `www/db_migrations/` (`NNN_description.sql`).
  Production applies migrations from Admin → Migrations or
  `bash www/db_migrations/migrate.sh`.
- Deployment: `docs/deployment.md` (rsync via `deploy/deploy.sh.example`).
