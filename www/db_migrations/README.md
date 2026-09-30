# Database Migrations

Migration files upgrade existing production installations of Flashcards. A
fresh install never needs them: load `www/schema.sql`, which always represents
the complete current database structure (and records the migrations it already
contains in `schema_migrations`).

## Naming convention

`NNN_description.sql`, zero-padded and sequential, e.g. `002_add_card_tags.sql`.
`001_initial_schema.sql` is a copy of the first `schema.sql` without its seed rows.

## Applying migrations

Applied files are tracked in the `schema_migrations` table so nothing runs twice.
Two equivalent ways to apply what is pending:

- **Admin → Migrations** in the web app (lists every file with its status and
  applies the selected ones).
- `bash www/db_migrations/migrate.sh` from a shell on the server
  (`--dry-run` shows what would be applied). It reads the credentials from
  `www/config.local.php`.

## Rules

- Write migrations to be idempotent (safe to run more than once) when possible:
  `CREATE TABLE IF NOT EXISTS`, and information_schema guards around
  `ALTER TABLE ... ADD COLUMN`.
- Whenever the database changes, update `schema.sql` to the complete current
  state AND add a migration file here for production installations. The unit
  test `MigrationRunnerTest` checks that `schema.sql` lists every migration
  file as already applied.
