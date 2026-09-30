<?php
// Page settings form (title, tagline, colour, visibility; slug and domain
// for admins). Evaluates to site_settings_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
Application::init();
require_login();

$siteId = (int)($_GET['site_id'] ?? 0);
$site = $siteId > 0 ? SiteManagement::findById($siteId) : null;
if (!$site) {
    header('Location: /manage/?err=' . urlencode('Site not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
if (!ContentAccess::canEdit($ctx, (int)$site['user_id'])) {
    http_response_code(403);
    die('You can only change your own page.');
}
$isAdmin = $ctx->admin;

$stash = ManageUI::takeForm('site_' . $siteId);
$form = $stash['data'] + $site;
$err = $stash['err'] ?? ($_GET['err'] ?? null);
$msg = $_GET['msg'] ?? null;
$userId = (int)$site['user_id'];

ApplicationUI::useSiteTheme($site);
header_html('Page settings');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">My Decks</a> › Page settings</div>
<div class="page-head">
  <h2>Page settings</h2>
  <a class="button" href="<?=h(SiteResolver::publicHomeUrl($site))?>">View my page</a>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/site_settings_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="site_id" value="<?= $siteId ?>">
    <?= ManageUI::nextInputHtml() ?>

    <div class="grid form-grid">
      <label>Page title
        <input type="text" name="title" value="<?=h($form['title'])?>" required maxlength="150">
      </label>
      <label>Tagline <span class="hint">one line under the title on the homepage</span>
        <input type="text" name="tagline" value="<?=h($form['tagline'])?>" maxlength="255" placeholder="e.g. Everything I'm studying this year">
      </label>
    </div>

    <div class="stack" style="gap:6px">
      <label style="margin:0">Colour scheme <span class="hint">colours the header, links and cards on your page</span></label>
      <div class="scheme-picker">
        <?php foreach (SiteManagement::ACCENTS as $key => $accent): ?>
          <label class="scheme-option" style="--swatch:<?=h($accent['color'])?>;--swatch-dark:<?=h($accent['dark'])?>;--swatch-light:<?=h($accent['light'])?>;--swatch-soft:<?=h($accent['soft'])?>">
            <input type="radio" name="accent_color" value="<?=h($key)?>" <?= $form['accent_color'] === $key ? 'checked' : '' ?>>
            <span class="scheme-swatch" aria-hidden="true"><span class="scheme-swatch-bar"></span><span class="scheme-swatch-body"><span></span><span></span></span></span>
            <span class="scheme-label"><?=h($accent['label'])?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <label class="inline">
      <input type="checkbox" name="is_public" value="1" <?= !empty($form['is_public']) ? 'checked' : '' ?>>
      Page is public <span class="hint">(unchecked: only you and admins can see it; each category also has its own public switch)</span>
    </label>

    <?php if ($isAdmin): ?>
      <h3>Routing <span class="small">(admins only)</span></h3>
      <div class="grid form-grid">
        <label>URL name <span class="hint">serves the page at /{name}/ (and {name}.<?=h(SiteResolver::mainHost())?> once a dedicated domain exists)</span>
          <input type="text" name="slug" value="<?=h($form['slug'])?>" required maxlength="50" pattern="[a-z0-9]+(-[a-z0-9]+)*">
        </label>
        <label>Custom domain <span class="hint">optional, for a different domain name (e.g. flashcards.NAME.org); it must also be added in the DreamHost panel (see docs/deployment.md). Subdomains of <?=h(SiteResolver::mainHost())?> need nothing here.</span>
          <input type="text" name="domain" value="<?=h($form['domain'] ?? '')?>" maxlength="253" placeholder="leave blank for none">
        </label>
      </div>
    <?php else: ?>
      <p class="small">Address: <?=h(SiteResolver::canonicalHomeUrl($site))?> (ask an admin to change the URL name or domain).</p>
    <?php endif; ?>

    <div class="actions">
      <button type="submit" class="button primary">Save settings</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
