-- 001: initial schema (identical to schema.sql without the seed rows)
-- Create the database, then load this file. This file always represents the
-- complete current schema; migrations in db_migrations/ exist only to upgrade
-- older production installations.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===== Users =====
-- Email is the login identifier. An empty password_hash means the user cannot
-- sign in yet (admin-created accounts gain a password via the emailed
-- activation link).
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) DEFAULT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL DEFAULT '',
  is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'App administrator',
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  password_reset_token_hash CHAR(64) DEFAULT NULL,
  password_reset_expires_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_users_email_verify_token ON users(email_verify_token);
CREATE INDEX idx_users_pwreset_expires ON users(password_reset_expires_at);

-- ===== Settings key-value table =====
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(191) NOT NULL UNIQUE,
  value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (key_name, value) VALUES
  ('site_title', 'Flashcards'),
  ('timezone', 'America/New_York'),
  ('site_base_url', 'https://flashcards.brianrosenthal.org')
ON DUPLICATE KEY UPDATE value=VALUES(value);

-- ===== Activity Log =====
-- Every write action and login is recorded here (see docs/php-guidelines.md).
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action_type VARCHAR(64) NOT NULL,
  json_metadata LONGTEXT NULL,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_al_created_at ON activity_log(created_at);
CREATE INDEX idx_al_user_id ON activity_log(user_id);
CREATE INDEX idx_al_action_type ON activity_log(action_type);

-- ===== Email Log =====
CREATE TABLE emails_sent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_by_user_id INT NULL,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(255) DEFAULT NULL,
  cc_email VARCHAR(255) DEFAULT NULL,
  subject VARCHAR(500) NOT NULL,
  body_html LONGTEXT NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT DEFAULT NULL,
  CONSTRAINT fk_emails_sent_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_emails_sent_created_at ON emails_sent(created_at);
CREATE INDEX idx_emails_sent_user_id ON emails_sent(sent_by_user_id);
CREATE INDEX idx_emails_sent_to_email ON emails_sent(to_email);
CREATE INDEX idx_emails_sent_success ON emails_sent(success);

-- ===== Schema migrations =====
-- Which db_migrations/*.sql files have been applied (by Admin -> Migrations or
-- db_migrations/migrate.sh). A fresh install loads this file instead, so the
-- migrations that this file already contains are recorded as applied below.
CREATE TABLE schema_migrations (
  filename VARCHAR(255) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (filename) VALUES
  ('001_initial_schema.sql');

-- ===== Sites =====
-- One public page per user, served at /{slug}/ on the main host (and, once a
-- dedicated domain exists, at {slug}.MAIN_HOST or a custom domain). Admins set
-- slug and domain; the owner edits everything else. is_public = 0 hides the
-- whole page from everyone but the owner and admins.
CREATE TABLE sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  slug VARCHAR(50) NOT NULL UNIQUE,
  domain VARCHAR(255) DEFAULT NULL UNIQUE COMMENT 'Lowercase hostname, no scheme or port; NULL = path-only',
  title VARCHAR(150) NOT NULL,
  tagline VARCHAR(255) NOT NULL DEFAULT '',
  accent_color VARCHAR(20) NOT NULL DEFAULT 'violet' COMMENT 'Palette key, see SiteManagement::ACCENTS',
  is_public TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = only the owner/admin can view the page',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== Categories =====
-- Top level of a user's decks, e.g. "US History". Studying a category deals
-- every card of every subcategory in it. is_public = 0 keeps the category (and
-- its subcategories) off the public page. ON DELETE RESTRICT on users: a user
-- with content cannot be deleted until their categories are removed.
CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_user_slug (user_id, slug),
  CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_categories_user_sort ON categories(user_id, sort_order);

-- ===== Subcategories =====
-- A deck, e.g. "Presidents" inside "US History". Cards live here. Deleting a
-- subcategory removes its cards (SubcategoryManagement::delete does that
-- explicitly, images and progress included), hence RESTRICT from cards.
CREATE TABLE subcategories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subcategories_category_slug (category_id, slug),
  CONSTRAINT fk_subcategories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_subcategories_category_sort ON subcategories(category_id, sort_order);

-- ===== Cards =====
-- A flashcard inside a subcategory. Front = optional text and/or an image in
-- Cloudflare R2 (at least one, enforced in CardManagement); back = text. The
-- image columns record what the server produced after resizing; the object
-- key is cards/{user_id}/{card_id}/{random}.{ext} and a "_thumb" sibling
-- object holds the list-page thumbnail.
CREATE TABLE cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subcategory_id INT NOT NULL,
  front_text TEXT NOT NULL,
  back_text TEXT NOT NULL,
  image_object_key VARCHAR(255) DEFAULT NULL,
  image_content_type VARCHAR(100) DEFAULT NULL,
  image_width SMALLINT UNSIGNED DEFAULT NULL,
  image_height SMALLINT UNSIGNED DEFAULT NULL,
  image_size_bytes INT UNSIGNED DEFAULT NULL,
  image_uploaded_at DATETIME DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cards_subcategory FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_cards_subcategory_sort ON cards(subcategory_id, sort_order);

-- ===== Per-viewer card state =====
-- One row per (viewer, card) once touched: flag and the latest Got it /
-- Need More Review mark with counters. The viewer is whoever studied the
-- card: the owner or any signed-in visitor of a public deck.
CREATE TABLE user_card_state (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  card_id INT NOT NULL,
  is_flagged TINYINT(1) NOT NULL DEFAULT 0,
  last_mark ENUM('got_it','needs_review') DEFAULT NULL,
  got_it_count INT NOT NULL DEFAULT 0,
  needs_review_count INT NOT NULL DEFAULT 0,
  last_reviewed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_ucs_user_card (user_id, card_id),
  CONSTRAINT fk_ucs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ucs_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_ucs_user_flagged ON user_card_state(user_id, is_flagged);
CREATE INDEX idx_ucs_user_mark ON user_card_state(user_id, last_mark);

-- ===== Card review events =====
-- Append-only log of every mark, for "reviewed today" and the daily chart.
CREATE TABLE card_review_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  card_id INT NOT NULL,
  mark ENUM('got_it','needs_review') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cre_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cre_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_cre_user_created ON card_review_events(user_id, created_at);
CREATE INDEX idx_cre_user_card ON card_review_events(user_id, card_id);

-- ===== Per-viewer deck positions =====
-- Resume point and shuffle seed per (viewer, deck). A deck is a subcategory or
-- a whole category, so deck_id is polymorphic and has no foreign key;
-- CategoryManagement / SubcategoryManagement delete these rows explicitly
-- (CardProgress::forgetDeck). shuffle_seed NULL = original order.
CREATE TABLE user_deck_positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  deck_type ENUM('category','subcategory') NOT NULL,
  deck_id INT NOT NULL,
  position INT NOT NULL DEFAULT 0,
  shuffle_seed INT UNSIGNED DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_udp_user_deck (user_id, deck_type, deck_id),
  CONSTRAINT fk_udp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== Quiz attempts =====
-- Append-only log of every typed quiz answer: the answer as typed, how the
-- server judged it, whether the user claimed "I was right anyway", and the
-- points. Keeping the verdict and the claim apart lets a claim award credit
-- without falsifying the record.
CREATE TABLE quiz_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  card_id INT NOT NULL,
  answer_text VARCHAR(255) NOT NULL DEFAULT '',
  result ENUM('correct','close','incorrect') NOT NULL,
  was_overridden TINYINT(1) NOT NULL DEFAULT 0,
  points_awarded INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_qa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_qa_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_qa_user_created ON quiz_attempts(user_id, created_at);
CREATE INDEX idx_qa_user_card ON quiz_attempts(user_id, card_id);

