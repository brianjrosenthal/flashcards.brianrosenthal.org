<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EmailLog.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

function int_param(string $key, int $default = 0): int {
  $v = $_GET[$key] ?? null;
  if ($v === null) return $default;
  if (is_string($v)) $v = trim($v);
  return (int)$v;
}

function str_param(string $key, string $default = ''): string {
  $v = $_GET[$key] ?? null;
  if ($v === null) return $default;
  return trim((string)$v);
}

$limitOptions = [10, 25, 50, 100];

// Filters
$qToEmail = str_param('to_email', '');
$qSuccess = str_param('success', '');
$qLimit = int_param('limit', 25);
if (!in_array($qLimit, $limitOptions, true)) { $qLimit = 25; }
$qPage = max(1, int_param('page', 1));

// Build filters for EmailLog
$filters = [];
if ($qToEmail !== '') $filters['to_email'] = $qToEmail;
if ($qSuccess === 'success') $filters['success'] = true;
if ($qSuccess === 'failed') $filters['success'] = false;

// Count + paging
$total = EmailLog::count($filters);
$totalPages = max(1, (int)ceil($total / $qLimit));
if ($qPage > $totalPages) $qPage = $totalPages;
$offset = ($qPage - 1) * $qLimit;

// Fetch rows
$rows = EmailLog::list($filters, $qLimit, $offset);

function build_url(array $overrides): string {
  $base = [
    'to_email' => $_GET['to_email'] ?? '',
    'success' => $_GET['success'] ?? '',
    'limit' => $_GET['limit'] ?? '',
    'page' => $_GET['page'] ?? '',
  ];
  foreach ($overrides as $k => $v) {
    if ($v === null) {
      unset($base[$k]);
    } else {
      $base[$k] = $v;
    }
  }
  $base = array_filter($base, fn($v) => !empty($v));
  $qs = http_build_query($base);
  return '/admin/email_log.php' . ($qs ? ('?' . $qs) : '');
}

header_html('Email Log');
?>
<h2>Email Log</h2>

<div class="card">
  <form method="get" class="grid filter-grid">
    <label>To Email
      <input type="email" name="to_email" value="<?= h($qToEmail) ?>" placeholder="recipient@example.com">
    </label>
    <label>Status
      <select name="success">
        <option value="">All</option>
        <option value="success"<?= $qSuccess === 'success' ? ' selected' : '' ?>>Success</option>
        <option value="failed"<?= $qSuccess === 'failed' ? ' selected' : '' ?>>Failed</option>
      </select>
    </label>
    <label>Page size
      <select name="limit">
        <?php foreach ($limitOptions as $opt): $sel = ($qLimit === $opt) ? ' selected' : ''; ?>
          <option value="<?= (int)$opt ?>"<?= $sel ?>><?= (int)$opt ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="actions">
      <button class="button primary" type="submit">Filter</button>
      <a class="button" href="/admin/email_log.php">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="page-head">
    <h3>Results</h3>
    <div class="small">Total: <?= (int)$total ?> | Page <?= (int)$qPage ?> of <?= (int)$totalPages ?></div>
  </div>

  <?php if (empty($rows)): ?>
    <p class="small">No email entries found.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="list">
      <thead>
        <tr>
          <th>When</th>
          <th>To</th>
          <th>Subject</th>
          <th>Status</th>
          <th>Error</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small"><?= h(date('Y-m-d H:i:s', strtotime($r['created_at'] ?? ''))) ?></td>
            <td class="small">
              <?php
                $toName = trim((string)($r['to_name'] ?? ''));
                $toEmail = (string)($r['to_email'] ?? '');
                if ($toName !== '' && $toName !== $toEmail) {
                  echo h($toName) . '<br><small>' . h($toEmail) . '</small>';
                } else {
                  echo h($toEmail);
                }
              ?>
            </td>
            <td class="small"><?= h($r['subject'] ?? '') ?></td>
            <td class="small">
              <?php if (!empty($r['success'])): ?>
                <span class="status-verified">&#10003; Success</span>
              <?php else: ?>
                <span class="status-failed">&#10007; Failed</span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php
                $error = trim((string)($r['error_message'] ?? ''));
                if ($error !== '') {
                  if (mb_strlen($error) > 100) {
                    $error = mb_substr($error, 0, 100) . '…';
                  }
                  echo '<span class="error-text">' . h($error) . '</span>';
                } else {
                  echo '<span class="muted">&mdash;</span>';
                }
              ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div class="actions pager">
      <?php if ($qPage > 1): ?>
        <a class="button" href="<?= h(build_url(['page' => $qPage - 1])) ?>">Prev</a>
      <?php else: ?>
        <span class="button disabled" aria-disabled="true">Prev</span>
      <?php endif; ?>
      <?php if ($qPage < $totalPages): ?>
        <a class="button" href="<?= h(build_url(['page' => $qPage + 1])) ?>">Next</a>
      <?php else: ?>
        <span class="button disabled" aria-disabled="true">Next</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
