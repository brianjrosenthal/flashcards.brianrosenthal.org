-- 002: the quiz can run back-to-front (see the back, type the front).
-- Record which way each attempt was asked. Existing rows were all asked
-- front-to-back, which is the column default. Safe to run more than once.
SET @direction_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_attempts' AND COLUMN_NAME = 'direction'
);
SET @direction_ddl := IF(@direction_exists = 0,
  'ALTER TABLE quiz_attempts ADD COLUMN direction ENUM(''front'',''back'') NOT NULL DEFAULT ''front'' COMMENT ''front = saw the front, typed the back; back = the reverse'' AFTER card_id',
  'SELECT ''quiz_attempts.direction already exists''');
PREPARE direction_stmt FROM @direction_ddl;
EXECUTE direction_stmt;
DEALLOCATE PREPARE direction_stmt;
