<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';

final class ActivityLog {
  private static function pdo(): \PDO {
    return pdo();
  }

  /**
   * Write an activity entry to DB and append to a line-delimited log file.
   * IMPORTANT:
   * - Do not perform any extra queries here (e.g., to look up names/emails).
   * - Callers should pass only metadata they already have.
   *
   * @param ?UserContext $ctx  The actor context (may be null for system actions)
   * @param string       $actionType  Application-defined action identifier (e.g. 'user.update_profile')
   * @param array        $metadata  Arbitrary key-value pairs to json_encode and store
   */
  public static function log(?\UserContext $ctx, string $actionType, array $metadata = []): void {
    // Normalize
    $action = trim((string)$actionType);
    if ($action === '') return;

    $userId = $ctx ? (int)$ctx->id : null;

    // Store in DB
    try {
      $st = self::pdo()->prepare(
        "INSERT INTO activity_log (user_id, action_type, json_metadata, created_at)
         VALUES (:user_id, :action_type, :json_metadata, NOW())"
      );
      if ($userId === null) {
        $st->bindValue(':user_id', null, \PDO::PARAM_NULL);
      } else {
        $st->bindValue(':user_id', $userId, \PDO::PARAM_INT);
      }
      $st->bindValue(':action_type', $action, \PDO::PARAM_STR);
      $st->bindValue(':json_metadata', json_encode($metadata, JSON_UNESCAPED_SLASHES), \PDO::PARAM_STR);
      $st->execute();
    } catch (\Throwable $e) {
      // Swallow DB errors to avoid breaking main flows
    }

    // Append to file as JSON line (best effort)
    try {
      $logDir = dirname(__DIR__) . '/logs';
      if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
      }
      $logPath = $logDir . '/activity.log';

      $payload = [
        'ts' => gmdate('c'),
        'user_id' => $userId,
        'action' => $action,
        'meta' => $metadata,
      ];
      $line = json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
      @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
      // Best effort only
    }
  }

  /**
   * Retrieve recent activity with optional filters.
   * Filters:
   * - user_id: ?int
   * - action_type: ?string
   * - since: ?string (Y-m-d or Y-m-d H:i:s)
   * - until: ?string (Y-m-d or Y-m-d H:i:s)
   */
  public static function list(array $filters = [], int $limit = 50, int $offset = 0): array {
    $limit = max(1, min(500, (int)$limit));
    $offset = max(0, (int)$offset);

    $where = [];
    $params = [];

    if (isset($filters['user_id']) && $filters['user_id'] !== null && $filters['user_id'] !== '') {
      $where[] = 'user_id = :user_id';
      $params[':user_id'] = (int)$filters['user_id'];
    }
    if (isset($filters['action_type']) && trim((string)$filters['action_type']) !== '') {
      $where[] = 'action_type = :action_type';
      $params[':action_type'] = trim((string)$filters['action_type']);
    }
    if (isset($filters['since']) && trim((string)$filters['since']) !== '') {
      $where[] = 'created_at >= :since';
      $params[':since'] = trim((string)$filters['since']);
    }
    if (isset($filters['until']) && trim((string)$filters['until']) !== '') {
      $where[] = 'created_at <= :until';
      $params[':until'] = trim((string)$filters['until']);
    }

    $sql = 'SELECT id, created_at, user_id, action_type, json_metadata FROM activity_log';
    if (!empty($where)) {
      $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    $st = self::pdo()->prepare($sql);
    foreach ($params as $k => $v) {
      $st->bindValue($k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
    }
    $st->execute();
    $rows = $st->fetchAll() ?: [];

    // Optionally decode JSON for convenience (non-breaking)
    foreach ($rows as &$r) {
      $meta = (string)($r['json_metadata'] ?? '');
      $r['metadata'] = $meta !== '' ? json_decode($meta, true) : null;
    }

    return $rows;
  }

  /**
   * Count total activities matching filters (for pagination).
   * Filters: user_id, action_type, since, until
   */
  public static function count(array $filters = []): int {
    $where = [];
    $params = [];

    if (isset($filters['user_id']) && $filters['user_id'] !== null && $filters['user_id'] !== '') {
      $where[] = 'user_id = :user_id';
      $params[':user_id'] = (int)$filters['user_id'];
    }
    if (isset($filters['action_type']) && trim((string)$filters['action_type']) !== '') {
      $where[] = 'action_type = :action_type';
      $params[':action_type'] = trim((string)$filters['action_type']);
    }
    if (isset($filters['since']) && trim((string)$filters['since']) !== '') {
      $where[] = 'created_at >= :since';
      $params[':since'] = trim((string)$filters['since']);
    }
    if (isset($filters['until']) && trim((string)$filters['until']) !== '') {
      $where[] = 'created_at <= :until';
      $params[':until'] = trim((string)$filters['until']);
    }

    $sql = 'SELECT COUNT(*) AS c FROM activity_log';
    if (!empty($where)) {
      $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $st = self::pdo()->prepare($sql);
    foreach ($params as $k => $v) {
      $st->bindValue($k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
    }
    $st->execute();
    $row = $st->fetch();
    return (int)($row['c'] ?? 0);
  }

  /**
   * List distinct action types for UI filters.
   * Returns array of strings sorted ascending.
   */
  public static function distinctActionTypes(): array {
    $st = self::pdo()->query('SELECT DISTINCT action_type FROM activity_log ORDER BY action_type ASC');
    $rows = $st->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
      $v = trim((string)($r['action_type'] ?? ''));
      if ($v !== '') $out[] = $v;
    }
    return $out;
  }
}
