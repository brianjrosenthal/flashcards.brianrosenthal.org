<?php
// Imports the next few pictures of a pending import (POST token + csrf from
// the progress page's JavaScript). JSON: {ok, done, created, failed,
// processed, total, failures: [{source, reason}]}.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Application.php';
require_once __DIR__ . '/../lib/CardImageImport.php';
Application::init();
header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session expired — reload the page.']);
    exit;
}

try {
    $manifest = CardImageImport::commitBatch((string)($_POST['token'] ?? ''), UserContext::getLoggedInUserContext());
    $summary = CardImageImport::summarize($manifest);
    $failures = [];
    foreach ($manifest['entries'] as $e) {
        if ($e['status'] === CardImageImport::STATUS_FAILED) {
            $failures[] = ['source' => $e['source'], 'reason' => $e['reason']];
        }
    }
    echo json_encode([
        'ok' => true,
        'done' => !empty($manifest['done']),
        'created' => (int)$manifest['created'],
        'failed' => (int)$manifest['failed'],
        'processed' => (int)$manifest['created'] + (int)$manifest['failed'],
        'total' => (int)$summary['will_import'] + (int)$summary[CardImageImport::STATUS_DONE] + (int)$summary[CardImageImport::STATUS_FAILED],
        'failures' => $failures,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
