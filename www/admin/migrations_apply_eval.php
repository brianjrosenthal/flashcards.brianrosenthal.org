<?php
// Applies the selected migrations (POST from admin/migrations.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';
Application::init();
require_admin();

$returnTo = '/admin/migrations.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnTo);
    exit;
}
require_csrf();

$requested = $_POST['filenames'] ?? [];
if (!is_array($requested)) {
    $requested = [];
}
$requested = array_values(array_filter(array_map('strval', $requested), static fn(string $s): bool => $s !== ''));
if ($requested === []) {
    header('Location: ' . $returnTo . '?err=' . urlencode('No migrations were selected.'));
    exit;
}

try {
    $result = MigrationRunner::apply($requested, UserContext::getLoggedInUserContext());
} catch (Throwable $e) {
    header('Location: ' . $returnTo . '?err=' . urlencode($e->getMessage()));
    exit;
}

$appliedCount = count($result['applied']);
if ($result['failed'] !== null) {
    $msg = 'Migration failed on ' . $result['failed']['filename'] . ': ' . $result['failed']['error'];
    if ($appliedCount > 0) {
        $msg = $appliedCount . ' applied, then ' . $msg;
    }
    header('Location: ' . $returnTo . '?err=' . urlencode($msg));
} elseif ($appliedCount === 0) {
    header('Location: ' . $returnTo . '?err=' . urlencode('Nothing to apply — the selected migrations were already applied.'));
} else {
    header('Location: ' . $returnTo . '?msg=' . urlencode($appliedCount . ' migration' . ($appliedCount === 1 ? '' : 's') . ' applied.'));
}
exit;
