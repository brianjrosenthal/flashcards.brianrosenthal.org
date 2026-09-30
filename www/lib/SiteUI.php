<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/ApplicationUI.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/ManageUI.php';
require_once __DIR__ . '/SiteResolver.php';
require_once __DIR__ . '/UserContext.php';

/**
 * Page chrome for a user's public flashcards page (/{slug}/...): the same
 * top bar as the signed-in app but branded with the page's title, the dark
 * owner bar an owner or admin sees above it, and the 404 page. SitePages
 * renders the content between pageStart() and pageEnd().
 */
final class SiteUI {

    private static function h($s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Head, owner bar, top bar and the opening <main>.
     *
     * @param array  $site         the sites row
     * @param string $basePath     '' on the page's own hostname, else '/{slug}'
     * @param string $title        page title ('' on the home page)
     * @param array  $categories   categories rows for the nav (already filtered to what the viewer may see)
     * @param array  $crumbs       [['label' => ..., 'url' => ...], ...] before the current title
     * @param array  $ownerActions page-specific owner bar entries, each
     *                             ['label' => ..., 'href' => ...] or
     *                             ['label' => ..., 'post' => action, 'fields' => [...], 'confirm' => ...]
     */
    public static function pageStart(array $site, string $basePath, string $title, array $categories, array $crumbs = [], array $ownerActions = []): void {
        $siteTitle = (string)$site['title'];
        $u = current_user();
        $ctx = UserContext::getLoggedInUserContext();
        $home = SiteResolver::urlFor($basePath);

        echo ApplicationUI::headHtml($title === '' ? $siteTitle : $title . ' - ' . $siteTitle, $site);
        echo '<body class="public-page">';

        if (ContentAccess::canEdit($ctx, (int)$site['user_id'])) {
            echo self::ownerBarHtml($site, $ownerActions);
        }

        echo '<header class="topbar">';
        echo '<a class="brand" href="' . self::h($home) . '"><span class="brand-mark" aria-hidden="true">&#127183;</span> ' . self::h($siteTitle) . '</a>';
        if ($categories !== []) {
            echo '<nav class="topnav" aria-label="Categories">';
            foreach ($categories as $cat) {
                $url = SiteResolver::urlFor($basePath, (string)$cat['slug']);
                $active = self::isCurrentPath($url);
                echo '<a href="' . self::h($url) . '"' . ($active ? ' class="active"' : '') . '>' . self::h($cat['name']) . '</a>';
            }
            echo '</nav>';
        }

        echo '<div class="topbar-right">';
        if ($u) {
            echo self::scoreChipHtml((int)$u['id']);
            $initials = strtoupper((string)substr((string)($u['first_name'] ?? ''), 0, 1) . (string)substr((string)($u['last_name'] ?? ''), 0, 1));
            $name = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
            echo '<span class="menu-wrap">'
               . '<button type="button" id="profileToggle" class="avatar" aria-expanded="false" aria-controls="profileMenu" title="' . self::h($name) . '" aria-label="Account menu for ' . self::h($name) . '">' . self::h($initials) . '</button>'
               . '<span id="profileMenu" class="popup-menu popup-menu-right hidden" role="menu" aria-hidden="true">'
               .   '<a href="/manage/" role="menuitem">My Decks</a>'
               .   '<a href="/progress/" role="menuitem">Stats</a>'
               .   '<a href="/profile/change_password.php" role="menuitem">Change Password</a>'
               .   '<a href="/logout.php" role="menuitem">Logout</a>'
               . '</span>'
               . '</span>';
        } else {
            // Cookies are per hostname, so the owner may be signed in on the
            // main site yet anonymous on a custom domain; this is their way in.
            echo '<a class="button small" href="/login.php?next=' . self::h(urlencode(self::currentPath())) . '">Sign in</a>';
        }
        echo '</div></header>';

        echo '<div class="content"><main>';
        if ($crumbs !== []) {
            echo '<nav class="crumbs" aria-label="Breadcrumb">';
            foreach ($crumbs as $crumb) {
                echo '<a href="' . self::h($crumb['url']) . '">' . self::h($crumb['label']) . '</a><span aria-hidden="true">&rsaquo;</span>';
            }
            echo '<span>' . self::h($title) . '</span></nav>';
        }
    }

    /** Closes <main>, prints the footer and main.js. */
    public static function pageEnd(): void {
        echo '</main></div>';
        echo '<footer class="small muted" style="text-align:center;padding:20px 16px 40px">Made with <a href="/">' . self::h(Settings::siteTitle()) . '</a></footer>';
        echo ApplicationUI::jsScript('/main.js');
        echo '</body></html>';
    }

    /** A complete 404 page with a friendly card and a way back. */
    public static function notFoundPage(?array $site, string $basePath, string $message): void {
        http_response_code(404);
        $title = $site ? (string)$site['title'] : Settings::siteTitle();
        echo ApplicationUI::headHtml('Not found', $site);
        echo '<body class="public-page">';
        echo '<header class="topbar"><a class="brand" href="' . self::h($site ? SiteResolver::urlFor($basePath) : '/') . '"><span class="brand-mark" aria-hidden="true">&#127183;</span> ' . self::h($title) . '</a></header>';
        echo '<div class="content"><main><div class="card empty-deck">';
        echo '<h2>' . self::h($message) . '</h2>';
        echo '<p><a class="button primary" href="' . self::h($site ? SiteResolver::urlFor($basePath) : '/') . '">' . ($site ? 'Back to ' . self::h($title) : 'Home') . '</a></p>';
        echo '</div></main></div>';
        echo ApplicationUI::jsScript('/main.js');
        echo '</body></html>';
    }

    /**
     * The dark strip an owner or admin sees above the page: page-specific
     * actions on the left, "My Decks" on the right. POST actions render as a
     * one-button form with the CSRF token.
     */
    public static function ownerBarHtml(array $site, array $actions): string {
        $ctx = UserContext::getLoggedInUserContext();
        $ownerId = (int)$site['user_id'];
        $isOwner = $ctx !== null && $ctx->id === $ownerId;

        $html = '<div class="owner-bar"><div class="owner-bar-inner">';
        $html .= '<span class="owner-bar-label">' . ($isOwner ? 'Your page' : 'Owner tools') . '</span>';
        foreach ($actions as $a) {
            $label = self::h($a['label']);
            if (isset($a['post'])) {
                $html .= '<form method="post" action="' . self::h($a['post']) . '" class="owner-bar-form">'
                       . '<input type="hidden" name="csrf" value="' . self::h(csrf_token()) . '">';
                foreach ($a['fields'] ?? [] as $name => $value) {
                    $html .= '<input type="hidden" name="' . self::h($name) . '" value="' . self::h($value) . '">';
                }
                $html .= '<button type="submit" class="owner-bar-link' . (!empty($a['danger']) ? ' danger' : '') . '"'
                       . (!empty($a['confirm']) ? ' data-confirm="' . self::h($a['confirm']) . '"' : '')
                       . '>' . $label . '</button></form>';
            } else {
                $html .= '<a class="owner-bar-link" href="' . self::h($a['href']) . '">' . $label . '</a>';
            }
        }
        $html .= '<a class="owner-bar-link owner-bar-right" href="' . self::h(ManageUI::dashboardUrl($ownerId)) . '">My Decks</a>';
        $html .= '</div></div>';
        return $html;
    }

    /** "3 decks", "1 card". */
    public static function countLabel(int $n, string $noun): string {
        return number_format($n) . ' ' . $noun . ($n === 1 ? '' : 's');
    }

    /**
     * The mastered/total chip from the app chrome. CardProgress is being
     * built alongside this file, so the chip is skipped when the class does
     * not offer the summary yet rather than breaking every public page.
     */
    private static function scoreChipHtml(int $userId): string {
        if (!class_exists('CardProgress', false) && is_file(__DIR__ . '/CardProgress.php')) {
            require_once __DIR__ . '/CardProgress.php';
        }
        if (!class_exists('CardProgress', false) || !method_exists('CardProgress', 'getScoreSummary')) {
            return '';
        }
        return ApplicationUI::scoreChipHtml($userId);
    }

    /** The request path (no query string), for login return links and nav highlighting. */
    private static function currentPath(): string {
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return $path === '' ? '/' : $path;
    }

    private static function isCurrentPath(string $url): bool {
        return strpos(self::currentPath(), $url) === 0;
    }
}
