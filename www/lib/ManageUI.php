<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/SiteManagement.php';
require_once __DIR__ . '/SiteResolver.php';

/**
 * Helpers shared by the /manage/ pages: whose decks are being edited, form
 * round-tripping, and the dashboard tree fragment.
 */
final class ManageUI {

    /**
     * The user whose decks this page manages. Admins may pass ?user_id= to
     * work on someone else's decks; everyone else always gets themselves.
     * Exits with 403/404 when the request is not allowed.
     */
    public static function targetUser(?string $requestedUserId): array {
        $me = current_user();
        $requested = (int)($requestedUserId ?? 0);
        if ($requested <= 0 || $requested === (int)$me['id']) {
            return $me;
        }
        if (empty($me['is_admin'])) {
            http_response_code(403);
            die('You can only manage your own decks.');
        }
        $user = UserManagement::findById($requested);
        if (!$user) {
            http_response_code(404);
            die('User not found.');
        }
        return $user;
    }

    /** "?user_id=N" when managing someone other than the current user, else ''. */
    public static function userParam(int $userId): string {
        $me = current_user();
        return ((int)$me['id'] === $userId) ? '' : '?user_id=' . $userId;
    }

    public static function dashboardUrl(int $userId): string {
        $p = self::userParam($userId);
        return '/manage/' . $p;
    }

    /** Admin-only dropdown to jump between users' decks. */
    public static function siteSwitcherHtml(int $currentUserId): string {
        $me = current_user();
        if (empty($me['is_admin'])) {
            return '';
        }
        $users = UserManagement::listUsers();
        $html = '<form method="get" action="/manage/" class="site-switcher" data-auto-submit>'
              . '<label class="inline"><span class="small">Managing:</span> <select name="user_id">';
        foreach ($users as $u) {
            $name = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']);
            $html .= '<option value="' . (int)$u['id'] . '"' . ((int)$u['id'] === $currentUserId ? ' selected' : '') . '>'
                   . h($name) . '</option>';
        }
        $html .= '</select></label></form>';
        return $html;
    }

    // ---- form round-tripping ---------------------------------------------

    /**
     * Keep a failed form's data in the session for the redirect back, instead
     * of the query string.
     */
    public static function stashForm(string $key, array $data, string $error): void {
        $_SESSION['form_' . $key] = ['data' => $data, 'err' => $error];
    }

    /** @return array{data:array,err:?string} */
    public static function takeForm(string $key): array {
        $stash = $_SESSION['form_' . $key] ?? null;
        unset($_SESSION['form_' . $key]);
        if (!is_array($stash)) {
            return ['data' => [], 'err' => null];
        }
        return ['data' => (array)($stash['data'] ?? []), 'err' => (string)($stash['err'] ?? '') ?: null];
    }

    /** The validated in-context return URL from ?next= / POST next, or $default. */
    public static function nextOr(string $default): string {
        $next = validate_relative_next_path($_POST['next'] ?? $_GET['next'] ?? '');
        return $next !== '' ? $next : $default;
    }

    public static function nextInputHtml(): string {
        $next = validate_relative_next_path($_GET['next'] ?? '');
        return $next !== '' ? '<input type="hidden" name="next" value="' . h($next) . '">' : '';
    }

    /** "&next=..." to forward the current next= to another page's link. */
    public static function nextParam(): string {
        $next = validate_relative_next_path($_GET['next'] ?? '');
        return $next !== '' ? '&next=' . urlencode($next) : '';
    }

    /**
     * The next= return URL adjusted for a different card: a study page URL
     * that names a card (?card=N) is pointed at $cardId so "Previous / Next
     * card" on the editor still returns to the card being edited; any other
     * URL is kept as it is.
     */
    public static function nextForCard(string $next, int $cardId): string {
        if ($next === '' || strpos($next, '/review/study.php') === false) {
            return $next;
        }
        $stripped = preg_replace('/([?&])card=\d+/', '$1', $next) ?? $next;
        $stripped = str_replace(['?&', '&&'], ['?', '&'], $stripped);
        $stripped = rtrim($stripped, '?&');
        return $stripped . (strpos($stripped, '?') === false ? '?' : '&') . 'card=' . $cardId;
    }

    /** $url with query parameters appended (after any it already has) and an optional #fragment. */
    public static function urlWith(string $url, array $params, string $fragment = ''): string {
        $query = http_build_query($params);
        if ($query !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        }
        return $fragment !== '' ? $url . '#' . $fragment : $url;
    }

    // ---- fragments --------------------------------------------------------

    /** A small POST form rendering as one button (delete etc.). */
    public static function postButtonHtml(string $action, array $fields, string $label, string $class = '', string $confirm = ''): string {
        $html = '<form method="post" action="' . h($action) . '" style="display:inline">'
              . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
        foreach ($fields as $k => $v) {
            $html .= '<input type="hidden" name="' . h((string)$k) . '" value="' . h((string)$v) . '">';
        }
        $html .= '<button type="submit"' . ($class !== '' ? ' class="' . h($class) . '"' : '')
               . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . h($label) . '</button></form>';
        return $html;
    }

    /**
     * The dashboard tree: categories → subcategories (decks), each with card
     * counts, learned/total from $summaries (CardProgress::deckSummariesForUser)
     * and links to study, quiz, the card list and the editors.
     *
     * @param array $summaries ['category' => [id => ['total'=>n,'learned'=>n]], 'subcategory' => [...]]
     */
    public static function treeHtml(array $tree, ?array $site, int $userId, array $summaries = []): string {
        $base = $site ? SiteResolver::basePathFor($site) : '';
        $dash = self::dashboardUrl($userId);
        if ($tree === []) {
            return '<p class="muted">No decks yet. Start with a subject, like "US History" or "Spanish".</p>'
                 . '<p><a class="button primary" href="/manage/category_add.php?user_id=' . $userId . '">Add your first category</a></p>';
        }
        $html = '<ul class="tree">';
        foreach ($tree as $cat) {
            $catId = (int)$cat['id'];
            $catCards = (int)$cat['card_count'];
            $catSummary = $summaries['category'][$catId] ?? ['total' => $catCards, 'learned' => 0];
            $html .= '<li><div class="tree-node level-1">'
                   . '<a class="title" href="' . ($site ? h(SiteResolver::urlFor($base, (string)$cat['slug'])) : '/manage/category_edit.php?id=' . $catId) . '">' . h($cat['name']) . '</a>'
                   . (empty($cat['is_public']) ? '<span class="badge draft">Private</span>' : '')
                   . '<span class="small">' . $catCards . ' card' . ($catCards === 1 ? '' : 's')
                   . ($catCards > 0 ? ' · ' . (int)$catSummary['learned'] . ' got' : '') . '</span>'
                   . '<span class="tools">'
                   . ($catCards > 0 ? '<a class="study" href="/review/study.php?category=' . $catId . '">Study all</a><a href="/quiz/?category=' . $catId . '">Quiz all</a>' : '')
                   . '<a href="/manage/category_edit.php?id=' . $catId . '">Edit</a>'
                   . '<a href="/manage/subcategory_add.php?category_id=' . $catId . '">+ Deck</a>'
                   . self::postButtonHtml('/manage/category_delete_eval.php', ['id' => $catId, 'next' => $dash], 'Delete', 'danger',
                        'Delete the category "' . $cat['name'] . '"' . ($catCards > 0 ? ' and its ' . $catCards . ' card' . ($catCards === 1 ? '' : 's') : '') . '? This cannot be undone.')
                   . '</span></div>';
            foreach ($cat['subcategories'] as $sub) {
                $subId = (int)$sub['id'];
                $subCards = (int)$sub['card_count'];
                $subSummary = $summaries['subcategory'][$subId] ?? ['total' => $subCards, 'learned' => 0];
                $html .= '<div class="tree-node level-2">'
                       . '<a class="title" href="/manage/cards.php?subcategory_id=' . $subId . '">' . h($sub['name']) . '</a>'
                       . '<span class="small">' . $subCards . ' card' . ($subCards === 1 ? '' : 's')
                       . ($subCards > 0 ? ' · ' . (int)$subSummary['learned'] . ' got' : '') . '</span>'
                       . '<span class="tools">'
                       . ($subCards > 0 ? '<a class="study" href="/review/study.php?subcategory=' . $subId . '">Study</a><a href="/quiz/?subcategory=' . $subId . '">Quiz</a>' : '')
                       . '<a href="/manage/cards.php?subcategory_id=' . $subId . '">Cards</a>'
                       . '<a href="/manage/card_add.php?subcategory_id=' . $subId . '">+ Card</a>'
                       . '<a href="/manage/subcategory_edit.php?id=' . $subId . '">Edit</a>'
                       . self::postButtonHtml('/manage/subcategory_delete_eval.php', ['id' => $subId, 'next' => $dash], 'Delete', 'danger',
                            'Delete the deck "' . $sub['name'] . '"' . ($subCards > 0 ? ' and its ' . $subCards . ' card' . ($subCards === 1 ? '' : 's') : '') . '? This cannot be undone.')
                       . '</span></div>';
                if ($subCards === 0) {
                    $html .= '<div class="tree-add level-3"><a href="/manage/card_add.php?subcategory_id=' . $subId . '">+ Add the first card</a></div>';
                }
            }
            if ($cat['subcategories'] === []) {
                $html .= '<div class="tree-add level-2"><a href="/manage/subcategory_add.php?category_id=' . $catId . '">+ Add the first deck</a></div>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        $html .= '<div class="tree-add level-1"><a class="button small" href="/manage/category_add.php?user_id=' . $userId . '">+ Add category</a></div>';
        return $html;
    }
}
