<?php
// Router for every public page of a user's flashcards. .htaccess (and the
// dev router) rewrite pretty URLs here:
//   /{slug}/{category}/{subcategory}/  -> ?site={slug}&path=...
// On a user's own hostname the first segment is part of the path instead
// (SiteResolver::pathFromRequest). SiteResolver decides whose page it is;
// SitePages renders it.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/SiteResolver.php';
require_once __DIR__ . '/lib/SitePages.php';

Application::init();

$resolved = SiteResolver::resolveFromRequest();
SitePages::render($resolved, SiteResolver::pathFromRequest($resolved));
