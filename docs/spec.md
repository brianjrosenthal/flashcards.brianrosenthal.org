# Flashcards — application spec (flashcards.brianrosenthal.org)

A PHP/MySQL site for studying with personal flashcard decks. Each person makes
their own decks (a photo of a person → their name; a map with a country
highlighted → the country; a term → its definition), flips through them, marks
each card *Got it* or *Need More Review*, and can quiz themselves by typing the
answer. Every user also has a public page at `/{slug}/` where anyone can
browse and flip through their public decks, and signed-in visitors can study
them with their own progress.

No framework, no build step. It follows the conventions in
`docs/php-guidelines.md` throughout: PDO only, SQL only inside `lib/*Management`
classes, every write takes a `UserContext` and is activity-logged,
`page.php` + `page_eval.php` pairing, CSRF on all POSTs, dedicated single-purpose
AJAX endpoint files, no Markdown/HTML in user content.

Lineage: the infrastructure (accounts, settings, activity log, email log,
migrations, S3 client, per-user public page) was copied from the sibling
project `mastery.brianrosenthal.org`; the study UI, quiz, stats page and the
playful cream / violet / mint / coral theme were copied from
`vocab.lillyrosenthal.org`. This file describes what the app does today; the
original request is preserved at the bottom.

## Concepts

- **Category** — a big subject a user owns, e.g. *US History*. Has a name, URL
  slug, optional one-line description, a public switch, and a sort order.
- **Subcategory (deck)** — a set of cards inside a category, e.g. *Presidents*.
  Name, slug, description, sort order. Cards live here.
- **Card** — front + back. The **front** is text and/or an image (at least one).
  The **back** is text (required). Several acceptable answers can be listed on
  the back separated by `/`, `;` or a new line; a parenthesised note is
  ignored when judging quiz answers (`the mitochondria / powerhouse of the
  cell (informal)`).
- **Deck (for studying)** — either one subcategory or a whole category (every
  card of every subcategory in it). `lib/Deck.php` is the one place that turns
  `?subcategory=N` / `?category=N` into the set of cards and the access rule.
- **Page (site)** — every user has one public page at `/{slug}/` (title,
  tagline, colour scheme, public switch). Admins can also give it a custom
  domain; a wildcard subdomain per user is supported by the code once a
  dedicated domain exists (`MAIN_HOST`).
- **Progress** is per *viewer*, never per deck owner: marks, flags, resume
  points, shuffle order and quiz attempts are stored against the signed-in
  user who studied the card, whether that is the owner or a visitor of a
  public deck. Nothing a visitor does changes the owner's cards or stats.

## What a signed-in user can do

**My Decks** (`/manage/`, the home page after login): the whole tree of
categories → decks with card counts and how many of them the viewer has *got*,
plus per-node actions: Study / Quiz (deck or whole category), Cards (the list
editor), + Card, + Deck, Edit, Delete. Admins get a user switcher (`?user_id=`)
to manage anyone's decks. A user without a page can create one here.

**Editing decks** (`/manage/`):
- Category add/edit: name, URL name (generated from the name, de-duplicated,
  reserved names refused), description, *Show on my public page*, order.
- Deck (subcategory) add/edit: name, URL name, description, order.
- **Cards** (`cards.php?subcategory_id=N`): the list with thumbnails, front
  and back text, ▲/▼ reordering, Edit and Delete. Below it a **bulk add** box
  takes one card per line as `front | back` (a tab also separates) — the
  quickest way to enter a text-only deck. All-or-nothing: a bad line names its
  line number and nothing is saved.
- **Card add/edit**: an image file (JPEG, PNG, WebP or GIF, up to
  `IMAGE_MAX_BYTES`, previewed before upload), front text, back text; *Add
  another card after this one* keeps the form open for fast entry. Editing can
  replace or remove the image. If a save fails the text comes back pre-filled
  but the image has to be chosen again (browsers do not let a page re-submit a
  file).
- **Import pictures** (`cards.php` → Import pictures): upload a ZIP of
  images (and/or loose image files). Every picture becomes a card with the
  image on the front and the file name on the back, extension dropped and
  underscores turned into spaces (`Ada_Lovelace.jpg` → *Ada Lovelace*).
  Three steps, per the guidelines' import pattern: upload (the files are
  unpacked into a private temp folder, nothing is created yet) → review (a
  table with a preview of each picture, the back it will get, and a status:
  will import / already in this deck / duplicate name in this upload /
  skipped with the reason; a checkbox opts the "already in deck" ones in) →
  import, which runs a few pictures per request from a progress page so a
  hundred photos cannot hit the PHP time limit; progress is saved after every
  picture, so a reload continues where it left off. Folders, `__MACOSX` and
  hidden files inside the ZIP are ignored. Limits: 500 pictures per upload,
  and the whole upload must fit the server's `post_max_size`, which the form
  states. Pending imports expire after a day.
- **Deleting** a deck or a category deletes everything in it — cards, their
  images in storage and everyone's progress on them — behind a confirmation
  that states the card count. (Mastery only deletes empty containers; for a
  study app, throwing a deck away is normal.)
- **Page settings**: title, tagline, colour scheme (swatch picker), *Page is
  public*. URL name and custom domain are admin-only fields.

**Study** (`/review/`): a deck picker over the viewer's own decks (and any
other people's public decks they have progress on), then the engine at
`/review/study.php?subcategory=N` or `?category=N`:
- One large card at a time. Click / tap / space flips it with a 160 ms
  cross-fade. Front = the image and/or text; back = the answer, plus the deck
  name when studying a whole category.
- **Got it!** / **Need More Review** under the card. Marks are saved by AJAX
  with optimistic UI; failures surface in a toast.
- `<` / `>` buttons (and ←/→ keys) browse without marking. `>` only appears once
  the card has ever been marked, so the way forward is earned card by card.
- Flag icon top-right of the card, per viewer, AJAX-saved.
- Tabs **All / Flagged / Misses** (misses = cards whose latest mark is
  *Need More Review*).
- **Shuffle** re-deals the deck with a per-viewer, per-deck seed
  (`SHA2(seed:card_id)`), so newly added cards slot in automatically and
  shuffling one deck leaves the others alone; **Original order** restores the
  tree order. Either resets the resume point.
- **Resume**: each deck remembers where the viewer left off ("Card 37 of
  120"). Flagged/misses passes restart at the top since they shrink.
- Finishing a deck shows a celebration with the session tally and buttons to
  go again, shuffle, or review the misses / flagged cards.
- **Edit this card** (owner or admin only) under the buttons opens the card
  editor and returns to that same card afterwards (`?card=N`).
- Keyboard: space = flip, 1 = got it, 2 = needs review, f = flag, e = edit,
  ←/→ = navigate.
- The score chip (top-right, every page) shows ⭐ mastered / total cards with
  "N today"; *mastered* = the card's latest mark is Got it. It links to Stats.

**Quiz** (`/quiz/`): recall practice — see the front, type the back.
- Launcher: pick the deck, the pool (**All cards** or **Cards I miss** = latest
  flashcard mark is Need More Review, or flagged, or missed in a quiz and not
  gotten right since) and a round length (10 / 20 / 40 / all). Counts sit
  beside each pool and follow the chosen deck; Start is disabled on an empty
  pool.
- Rounds deal least-recently-quizzed cards first (never-quizzed lead), then
  shuffle, so successive rounds work through the deck before anything
  repeats.
- **Answers are judged on the server.** The page receives prompts only, never
  the backs. Each answer POSTs to `answer_eval.php` and waits for the verdict.
- Judging: both sides are normalised (lower-case, punctuation dropped, spaces
  collapsed, a leading *the / a / an / to* removed) and compared against every
  accepted answer on the back. **10** points for an exact match, **8** for a
  near miss (edit distance with adjacent swaps counting 1: no slack under 5
  letters, 1 edit for 5–8, 2 edits for 9+; multibyte-safe, so accents count
  as one edit), **5** for an answer claimed afterwards.
- **"I was right anyway"** — any answer that scored nothing can be claimed for
  partial credit. Claiming never rewrites what was typed or how the server
  judged it; it only sets `was_overridden` and the points.
- Hints: *N words, M letters, starts with X*; then a list of every answer in
  the deck starting with that letter as tappable chips (the answer is among
  them, unmarked).
- Feedback with a rotating set of cheers (extra fanfare at streaks), the
  answer in big type, "You typed …", and an encouraging message on a miss.
  Look back / forward arrows re-show any answered question's feedback.
- A refresh does not lose the round: progress is snapshotted to
  `sessionStorage` at each verdict and restored into the same settings; since
  the answer was already recorded, resuming lands on the next question.
- The quiz needs a signed-in user (attempts are per user); anonymous visitors
  of a public deck see a sign-in card.

**Stats** (`/progress/`): a deck picker (all decks by default) scopes tiles
(Got it, Need more review, Flagged, Reviewed today, all-time reviews), a
pure-CSS bar chart of the last 14 days, quiz tiles (points, accuracy,
questions today), and a **Cards you miss the most** table ranking flashcard
and quiz misses together, linking to the misses deck and the quiz pool.
Quiz points and flashcard marks are separate currencies.

**Account**: change password, logout. Remember-me is a stateless HMAC cookie
invalidated by password changes; a "public computer" checkbox on login skips it.

## The public page (`/{slug}/`)

- `/{slug}/` — the page title and tagline, then one card per public category
  (deck count, card count, Study all / Quiz all, Browse). The header nav lists
  the categories, and the whole page wears the owner's colour scheme
  (`SiteManagement::ACCENTS`, applied as CSS variables over the shared
  `styles.css`).
- `/{slug}/{category}/` — the decks in that category with Study / Quiz each.
- `/{slug}/{category}/{deck}/` — the cards as a preview grid: front (thumbnail
  and/or text); tapping a card reveals the back. Study / Quiz buttons.
- Anyone can browse and flip. Study works anonymously too (no saves; a note
  offers sign-in). A signed-in visitor gets their own marks, flags, resume
  point and quiz on the deck.
- A private page, or a private category, is a 404 for everyone but the owner
  and admins, who see a *Private* badge instead.
- The owner (or an admin) sees a dark **owner bar** on every public page with
  in-context actions: edit page settings, add category / deck / card, edit
  this node, My Decks. Actions carry `next=` back to the page.
- URL slugs are refused when they would collide with a real path
  (`review`, `quiz`, `progress`, `manage`, `admin`, `profile`, `cards`, …; see
  `Slugger::RESERVED` plus anything that exists in the web root), because the
  rewrite rule sends every unknown first segment to `public_site.php`.

## Images

Card images live in a **private Cloudflare R2 bucket**, never on the server
or in the database.

- Upload goes **through PHP** (a normal multipart form): the server sniffs the
  real type (never the client's MIME), refuses anything over 30 megapixels,
  fixes EXIF orientation, downsizes to 1600 px on the long edge (never
  upscales), re-encodes (JPEG q85 / PNG with alpha / WebP q85; GIF becomes
  PNG), strips metadata as a side effect, and also produces a 240 px thumbnail.
  Then `S3Client::putObject` stores both under
  `cards/{user_id}/{card_id}/{random}.{ext}` (+ `_thumb`). Keys are random, so
  replacing an image never reuses a URL a browser may have cached.
- Write ordering: create = validate → insert row → put → record columns (a put
  failure deletes the row and re-shows the form); replace = put new → update
  row → delete old (best effort, logged); remove/delete = delete from storage
  first, then the row.
- Display uses **presigned GET URLs** whose timestamp is rounded down to a
  6-hour window (identical URL for every render in the window, so browsers
  cache) and which stay valid for 24 hours. The bucket stays private; no CORS
  rule is needed because the browser never talks to the bucket directly.
- `R2_ACCESS_KEY` empty = uploads disabled; text-only cards still work and the
  card forms say so.
- Admin → **Image Storage**: configuration check, bucket exists, object count
  and size, reconciliation of recorded keys against the bucket (missing /
  orphans), *Create bucket*, *Test upload* (full put / head / GET / delete
  cycle with the raw response), *Delete orphans*.
- Because image bytes pass through PHP, production needs `upload_max_filesize`
  / `post_max_size` above `IMAGE_MAX_BYTES` and `memory_limit` around 256M (see
  `docs/deployment.md`).

## What an admin can do (Admin dropdown)

- **Users**: admin-created accounts only (no self-registration). Creating a
  user sends an activation email; the user verifies and sets their own
  password. A public page is created for them at once (slug from their first
  name). Admins can edit/delete users (a user who still has categories cannot
  be deleted),
  resend activation, send password resets, grant/revoke admin.
- **Pages**: every user's page with its path, subdomain (once a domain exists),
  custom domain and public flag; links to its settings and decks.
- **Settings**: site title, time zone, site URL (used in email links).
- **Image Storage** (above), **Migrations** (`db_migrations/*.sql` with
  applied status from `schema_migrations`; apply pending ones — `migrate.sh`
  does the same from a shell), **Activity Log** (every write and login,
  filterable), **Email Log** (every send attempt with errors).

## Data model (`www/schema.sql` is the complete, standalone truth)

Infrastructure: `users` (email + password_hash, verify/reset tokens,
is_admin), `settings`, `activity_log`, `emails_sent`, `schema_migrations`.

Content: `sites` (1:1 with users: slug, domain, title, tagline, accent_color,
is_public) → `categories` (user_id, slug, name, description, is_public,
sort_order) → `subcategories` (category_id, slug, name, description,
sort_order) → `cards` (subcategory_id, front_text, back_text, the
`image_*` columns recording the stored object, sort_order). Deleting is
RESTRICTed upward in the database; `CategoryManagement::delete` and
`SubcategoryManagement::delete` do the cascade explicitly (images first, in
one transaction) so the rules stay in code.

Progress, all keyed by the *viewer's* user id: `user_card_state` (one row per
user × card touched: is_flagged, last_mark, per-mark counters,
last_reviewed_at), `card_review_events` (append-only log of every mark),
`user_deck_positions` (resume point and shuffle seed per deck; `deck_type` +
`deck_id` is polymorphic, so those rows are removed in code when a deck is
deleted), `quiz_attempts` (append-only: answer as typed, the server's verdict,
whether it was claimed, points).

Seeded admin for fresh installs: `brian.rosenthal@gmail.com` / `flashcards`
(change it). Migrations live in `www/db_migrations/` (`001_initial_schema.sql`
today); `schema.sql` must always be updated alongside any migration.

## Code layout (web root = `www/`)

- `config.php` — session, `pdo()` (+ `set_pdo_for_testing()` seam), CSRF,
  remember-me, `current_user()`, `require_login()` / `require_admin()`,
  `request_host()` / `cookie_domain()` for the future subdomain routing.
  Secrets in git-ignored `config.local.php` (see `config.local.php.example`:
  DB, SMTP, `REMEMBER_TOKEN_KEY`, optional `SUPER_PASSWORD` test backdoor,
  `MAIN_HOST`, the `R2_*` keys, `IMAGE_MAX_BYTES`).
- `settings.php`, `partials.php` (`h()`, `header_html()`, `footer_html()`),
  `mailer.php` (raw SMTP + `EmailLog`; activation and password-reset emails).
- `lib/` — `Application` (init, timezone, legacy-host redirect), `ApplicationUI`
  (page shell, nav, score chip, filemtime cache-busted assets, colour theme),
  `ManageUI` (target user, form stash/take, `next=` helpers, dashboard tree),
  `SiteUI` + `SitePages` (public page chrome and renderers), `SiteResolver`
  (which user's page a request is for: `/{slug}` path, `{slug}.MAIN_HOST`
  subdomain, or custom domain), `SiteManagement`, `CategoryManagement`,
  `SubcategoryManagement`, `CardManagement` (cards, images, bulk lines,
  reorder), `ImageStorage` (R2 policy: validation, resizing, keys, signed
  URLs, diagnostics), `S3Client` (hand-rolled SigV4 client), `Deck`,
  `CardProgress` (marks, flags, positions, shuffle, score, stats),
  `QuizManagement` (rounds, judging, attempts, claims, stats),
  `CardImageImport` (ZIP/picture import: unpack, review manifest, batched
  commit),
  `ContentAccess` (edit = owner/admin; view = that, or public page + public
  category), `Slugger`, `UserManagement`, `UserContext`, `ActivityLog`,
  `EmailLog`, `MigrationRunner`.
- `manage/` — dashboard, category / deck / card / page-settings pages and
  their `_eval.php` handlers; the picture import wizard (`card_import.php` →
  `card_import_upload_eval.php` → `card_import_review.php` (+
  `card_import_preview.php` for thumbnails) → `card_import_start_eval.php` →
  `card_import_progress.php` calling `card_import_batch_eval.php` (JSON);
  `card_import_discard_eval.php`); `manage.js` (image preview, paste/drop an
  image into the card form, import progress loop).
- `review/` — `index.php` (deck picker), `study.php` (embeds the deck as JSON;
  reads are server-rendered, only writes are AJAX), `review.js`,
  `mark_card_eval.php`, `toggle_flag_eval.php`, `save_position_eval.php`
  (JSON), `shuffle_eval.php`, `order_eval.php` (PRG).
- `quiz/` — `index.php` (launcher) + `quiz_setup.js` (live pool counts via
  `pool_counts_eval.php`), `play.php` (embeds the round's prompts), `quiz.js`,
  `answer_eval.php`, `claim_correct_eval.php` (JSON), `celebrations.js`.
- `progress/index.php` — stats.
- `public_site.php` — router for `/{slug}/…` (from `.htaccess`, or
  `deploy/dev-router.php` locally).
- `admin/` — users, pages, settings, image storage, migrations, logs.
- Auth pages at root: `login`, `forgot/reset/set_password`, `verify_email`,
  `logout` (+ `profile/change_password`).
- `styles.css` (design tokens in `:root`; one theme for private and public
  pages), `main.js` (menus, auto-submit, confirms).

## Development & deployment

- Local: see `CLAUDE.md` — create DB `flashcards_brianrosenthal`, load
  `www/schema.sql`, copy `config.local.php.example` → `config.local.php`,
  `php -S localhost:8080 -t www deploy/dev-router.php`.
- Tests: `php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml` — unit
  tests over the lib classes (DI via `set_pdo_for_testing`; the bootstrap
  drops/recreates `flashcards_brianrosenthal_test` from `schema.sql`, or the
  database named in `FLASHCARDS_TEST_DB`; storage is faked by
  `tests/Support/FakeS3Client.php`). No endpoint or UI tests, per guidelines.
- Production: DreamHost VPS, one web directory, docroot = `www/`; `.htaccess`
  denies `logs/`, `db_migrations/`, `lib/`, `config.local.php`, `schema.sql`
  and rewrites `/{slug}/…` to `public_site.php`. Deploy = `deploy/deploy.sh`
  (rsync, never overwrites `config.local.php` or logs) + apply any new
  migration. Full runbook, including the R2 bucket, `phprc` upload limits and
  "adding a domain later": `docs/deployment.md`.

---

## Original request (historical)

I want to build a web site "flashcards.brianrosenthal.org". The front-end
should be very similar to the web application vocab.lillyrosenthal.org,
except it shouldn't be based on vocabulary — just flashcards. The back-end
should be similar to "mastery.brianrosenthal.org" (in that it should have
user accounts, should be able to create categories and subcategories).

Flashcards should have a front and a back. The front can have an image or
text. The back should have text (the use-case is a picture of a person and
on the back is their name… or a map with a country highlighted and on the
front it should be the name of the country).

Flashcards should have categories and subcategories. Users should be able to
create categories and subcategories.

People should have their own flashcard decks. The use case is that they
should create them to study for tests. No need to share them, at least for
now. I want to include all the infrastructure — db migrations, php
infrastructure, email log, activity log, etc.

Follow-ups during planning: store all the images in a Cloudflare bucket;
make flashcards.brianrosenthal.org/charlie the public version of Charlie's
flashcards (like mastery.brianrosenthal.org does with kidsthatteach.org) — a
domain name like "myflashcards.com" will be found later; visitors may browse
and flip, and signed-in visitors study with their own progress; a page-level
public switch plus a per-category public switch.
