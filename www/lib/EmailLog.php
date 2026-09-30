<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';

final class EmailLog {
  private static function pdo(): \PDO {
    return pdo();
  }

  /**
   * Log an email sending attempt to both database and file.
   * 
   * @param ?UserContext $ctx The user context (may be null for system emails)
   * @param string $toEmail Recipient email address
   * @param string $toName Recipient name (optional)
   * @param string $subject Email subject
   * @param string $bodyHtml Email body (HTML)
   * @param bool $success Whether the email was sent successfully
   * @param ?string $errorMessage Error message if sending failed
   * @param ?string $ccEmail CC email address (optional, for future use)
   */
  public static function log(
    ?\UserContext $ctx,
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyHtml,
    bool $success,
    ?string $errorMessage = null,
    ?string $ccEmail = null
  ): void {
    // Normalize inputs
    $toEmail = trim($toEmail);
    $toName = trim($toName);
    $subject = trim($subject);
    $ccEmail = $ccEmail ? trim($ccEmail) : null;
    
    if ($toEmail === '' || $subject === '') {
      return; // Skip logging if essential fields are empty
    }

    $userId = $ctx ? (int)$ctx->id : null;

    // Store in database
    try {
      $st = self::pdo()->prepare(
        "INSERT INTO emails_sent (sent_by_user_id, to_email, to_name, cc_email, subject, body_html, success, error_message, created_at)
         VALUES (:sent_by_user_id, :to_email, :to_name, :cc_email, :subject, :body_html, :success, :error_message, NOW())"
      );
      
      if ($userId === null) {
        $st->bindValue(':sent_by_user_id', null, \PDO::PARAM_NULL);
      } else {
        $st->bindValue(':sent_by_user_id', $userId, \PDO::PARAM_INT);
      }
      
      $st->bindValue(':to_email', $toEmail, \PDO::PARAM_STR);
      $st->bindValue(':to_name', $toName !== '' ? $toName : null, \PDO::PARAM_STR);
      $st->bindValue(':cc_email', $ccEmail, \PDO::PARAM_STR);
      $st->bindValue(':subject', $subject, \PDO::PARAM_STR);
      $st->bindValue(':body_html', $bodyHtml, \PDO::PARAM_STR);
      $st->bindValue(':success', $success ? 1 : 0, \PDO::PARAM_INT);
      $st->bindValue(':error_message', $errorMessage, \PDO::PARAM_STR);
      
      $st->execute();
    } catch (\Throwable $e) {
      // Swallow DB errors to avoid breaking email flows
    }

    // Append to file as JSON line (best effort)
    try {
      $logDir = dirname(__DIR__) . '/logs';
      if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
      }
      $logPath = $logDir . '/emails.log';

      $payload = [
        'ts' => gmdate('c'),
        'sent_by_user_id' => $userId,
        'to_email' => $toEmail,
        'to_name' => $toName !== '' ? $toName : null,
        'cc_email' => $ccEmail,
        'subject' => $subject,
        'body_length' => strlen($bodyHtml),
        'success' => $success,
        'error_message' => $errorMessage,
      ];
      
      $line = json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
      @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
      // Best effort only
    }
  }

  /**
   * Retrieve recent email logs with optional filters.
   * Filters:
   * - sent_by_user_id: ?int
   * - to_email: ?string
   * - success: ?bool
   * - since: ?string (Y-m-d or Y-m-d H:i:s)
   * - until: ?string (Y-m-d or Y-m-d H:i:s)
   */
  public static function list(array $filters = [], int $limit = 50, int $offset = 0): array {
    $limit = max(1, min(500, (int)$limit));
    $offset = max(0, (int)$offset);

    $where = [];
    $params = [];

    if (isset($filters['sent_by_user_id']) && $filters['sent_by_user_id'] !== null && $filters['sent_by_user_id'] !== '') {
      $where[] = 'sent_by_user_id = :sent_by_user_id';
      $params[':sent_by_user_id'] = (int)$filters['sent_by_user_id'];
    }
    if (isset($filters['to_email']) && trim((string)$filters['to_email']) !== '') {
      $where[] = 'to_email = :to_email';
      $params[':to_email'] = trim((string)$filters['to_email']);
    }
    if (isset($filters['success']) && $filters['success'] !== null) {
      $where[] = 'success = :success';
      $params[':success'] = $filters['success'] ? 1 : 0;
    }
    if (isset($filters['since']) && trim((string)$filters['since']) !== '') {
      $where[] = 'created_at >= :since';
      $params[':since'] = trim((string)$filters['since']);
    }
    if (isset($filters['until']) && trim((string)$filters['until']) !== '') {
      $where[] = 'created_at <= :until';
      $params[':until'] = trim((string)$filters['until']);
    }

    $sql = 'SELECT id, created_at, sent_by_user_id, to_email, to_name, cc_email, subject, success, error_message FROM emails_sent';
    if (!empty($where)) {
      $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    $st = self::pdo()->prepare($sql);
    foreach ($params as $k => $v) {
      $st->bindValue($k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
    }
    $st->execute();
    
    return $st->fetchAll() ?: [];
  }

  /**
   * Count total emails matching filters (for pagination).
   */
  public static function count(array $filters = []): int {
    $where = [];
    $params = [];

    if (isset($filters['sent_by_user_id']) && $filters['sent_by_user_id'] !== null && $filters['sent_by_user_id'] !== '') {
      $where[] = 'sent_by_user_id = :sent_by_user_id';
      $params[':sent_by_user_id'] = (int)$filters['sent_by_user_id'];
    }
    if (isset($filters['to_email']) && trim((string)$filters['to_email']) !== '') {
      $where[] = 'to_email = :to_email';
      $params[':to_email'] = trim((string)$filters['to_email']);
    }
    if (isset($filters['success']) && $filters['success'] !== null) {
      $where[] = 'success = :success';
      $params[':success'] = $filters['success'] ? 1 : 0;
    }
    if (isset($filters['since']) && trim((string)$filters['since']) !== '') {
      $where[] = 'created_at >= :since';
      $params[':since'] = trim((string)$filters['since']);
    }
    if (isset($filters['until']) && trim((string)$filters['until']) !== '') {
      $where[] = 'created_at <= :until';
      $params[':until'] = trim((string)$filters['until']);
    }

    $sql = 'SELECT COUNT(*) AS c FROM emails_sent';
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
}
