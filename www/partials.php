<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/ApplicationUI.php';

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function header_html(string $title): void {
    ApplicationUI::headerHtml($title);
}

function footer_html(): void {
    ApplicationUI::footerHtml();
}
