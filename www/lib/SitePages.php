<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/CategoryManagement.php';
require_once __DIR__ . '/SubcategoryManagement.php';
require_once __DIR__ . '/CardManagement.php';
require_once __DIR__ . '/ImageStorage.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/SiteResolver.php';
require_once __DIR__ . '/SiteUI.php';
require_once __DIR__ . '/UserContext.php';

/**
 * The three public pages of a user's flashcards, rendered from the resolved
 * site (SiteResolver) and the rewritten path:
 *
 *   /{slug}/                      home: a card per category
 *   /{slug}/{category}/           the category's decks
 *   /{slug}/{category}/{deck}/    the deck's cards as a flip-to-reveal grid
 *
 * Visitors see public categories of a public page; the owner (or an admin)
 * sees everything with a Private badge and an owner bar of editing links.
 * Study and Quiz buttons hand over to the shared engines, which check
 * access again themselves.
 */
final class SitePages {

    private static function h($s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** @param array{site:?array,base_path:string,is_custom_domain:bool} $resolved */
    public static function render(array $resolved, string $path): void {
        $site = $resolved['site'];
        $base = (string)$resolved['base_path'];
        if ($site === null) {
            SiteUI::notFoundPage(null, '', 'No page here.');
            return;
        }

        current_user(); // completes a remember-me login so the context below is set
        $ctx = UserContext::getLoggedInUserContext();
        $ownerId = (int)$site['user_id'];
        $canEdit = ContentAccess::canEdit($ctx, $ownerId);
        if (empty($site['is_public']) && !$canEdit) {
            SiteUI::notFoundPage(null, '', 'This page is not public yet.');
            return;
        }

        $split = SiteResolver::splitPath($path);
        if ($split === null) {
            SiteUI::notFoundPage($site, $base, 'No such page.');
            return;
        }
        [$catSlug, $subSlug] = $split;

        // Owners see private categories too (with a badge); visitors do not.
        $categories = CategoryManagement::listForUser($ownerId, !$canEdit);

        if ($catSlug === null) {
            self::homePage($site, $base, $canEdit, $categories);
            return;
        }
        $category = CategoryManagement::findBySlug($ownerId, $catSlug);
        if ($category === null || !ContentAccess::canView($ctx, $site, $category)) {
            SiteUI::notFoundPage($site, $base, 'No such category.');
            return;
        }
        if ($subSlug === null) {
            self::categoryPage($site, $base, $canEdit, $categories, $category);
            return;
        }
        $subcategory = SubcategoryManagement::findBySlug((int)$category['id'], $subSlug);
        if ($subcategory === null) {
            SiteUI::notFoundPage($site, $base, 'No such deck.');
            return;
        }
        self::deckPage($site, $base, $canEdit, $categories, $category, $subcategory);
    }

    // ---- pages ------------------------------------------------------------

    private static function homePage(array $site, string $base, bool $canEdit, array $categories): void {
        $here = SiteResolver::urlFor($base);
        $ownerActions = [
            ['label' => 'Edit page settings', 'href' => '/manage/site_settings.php?site_id=' . (int)$site['id']],
            ['label' => 'Add category', 'href' => '/manage/category_add.php?user_id=' . (int)$site['user_id'] . '&next=' . urlencode($here)],
        ];
        SiteUI::pageStart($site, $base, '', $categories, [], $canEdit ? $ownerActions : []);

        echo '<section class="hero">';
        echo '<h1 class="hero-title">' . self::h($site['title']) . self::privateBadge(empty($site['is_public'])) . '</h1>';
        if (trim((string)$site['tagline']) !== '') {
            echo '<p class="hero-tagline">' . self::h($site['tagline']) . '</p>';
        }
        echo '</section>';

        if ($categories === []) {
            echo self::emptyStateHtml(
                $canEdit,
                'No decks here yet.',
                'You have no categories yet. Add one to start making decks.',
                '/manage/category_add.php?user_id=' . (int)$site['user_id'] . '&next=' . urlencode($here),
                'Add a category'
            );
        } else {
            echo '<div class="deck-grid">';
            foreach ($categories as $cat) {
                $url = SiteResolver::urlFor($base, (string)$cat['slug']);
                $cards = (int)$cat['card_count'];
                echo '<article class="deck-card">';
                echo '<h3><a href="' . self::h($url) . '">' . self::h($cat['name']) . '</a>' . self::privateBadge(empty($cat['is_public'])) . '</h3>';
                if (trim((string)$cat['description']) !== '') {
                    echo '<p>' . self::h($cat['description']) . '</p>';
                }
                echo '<p class="deck-meta">' . SiteUI::countLabel((int)$cat['subcategory_count'], 'deck') . ' &middot; ' . SiteUI::countLabel($cards, 'card') . '</p>';
                echo '<div class="actions">' . self::studyQuizButtonsHtml('category', (int)$cat['id'], $cards, 'Study all', 'Quiz all')
                   . '<a class="button small" href="' . self::h($url) . '">Browse</a></div>';
                echo '</article>';
            }
            echo '</div>';
        }
        SiteUI::pageEnd();
    }

    private static function categoryPage(array $site, string $base, bool $canEdit, array $categories, array $category): void {
        $catId = (int)$category['id'];
        $here = SiteResolver::urlFor($base, (string)$category['slug']);
        $decks = SubcategoryManagement::listForCategory($catId);
        $cards = CategoryManagement::cardCount($catId);

        $ownerActions = [
            ['label' => 'Edit category', 'href' => '/manage/category_edit.php?id=' . $catId . '&next=' . urlencode($here)],
            ['label' => 'Add deck', 'href' => '/manage/subcategory_add.php?category_id=' . $catId . '&next=' . urlencode($here)],
        ];
        $crumbs = [['label' => $site['title'], 'url' => SiteResolver::urlFor($base)]];
        SiteUI::pageStart($site, $base, (string)$category['name'], $categories, $crumbs, $canEdit ? $ownerActions : []);

        echo '<div class="page-head"><h2>' . self::h($category['name']) . self::privateBadge(empty($category['is_public'])) . '</h2>';
        echo '<div class="actions">' . self::studyQuizButtonsHtml('category', $catId, $cards, 'Study all', 'Quiz all') . '</div></div>';
        if (trim((string)$category['description']) !== '') {
            echo '<p>' . self::h($category['description']) . '</p>';
        }
        echo '<p class="small">' . SiteUI::countLabel(count($decks), 'deck') . ' &middot; ' . SiteUI::countLabel($cards, 'card') . '</p>';

        if ($decks === []) {
            echo self::emptyStateHtml(
                $canEdit,
                'No decks in this category yet.',
                'This category has no decks yet.',
                '/manage/subcategory_add.php?category_id=' . $catId . '&next=' . urlencode($here),
                'Add a deck'
            );
        } else {
            echo '<div class="deck-grid">';
            foreach ($decks as $deck) {
                $url = SiteResolver::urlFor($base, (string)$category['slug'], (string)$deck['slug']);
                $n = (int)$deck['card_count'];
                echo '<article class="deck-card">';
                echo '<h3><a href="' . self::h($url) . '">' . self::h($deck['name']) . '</a></h3>';
                if (trim((string)$deck['description']) !== '') {
                    echo '<p>' . self::h($deck['description']) . '</p>';
                }
                echo '<p class="deck-meta">' . SiteUI::countLabel($n, 'card') . '</p>';
                echo '<div class="actions">' . self::studyQuizButtonsHtml('subcategory', (int)$deck['id'], $n, 'Study', 'Quiz')
                   . '<a class="button small" href="' . self::h($url) . '">Browse</a></div>';
                echo '</article>';
            }
            echo '</div>';
        }
        SiteUI::pageEnd();
    }

    private static function deckPage(array $site, string $base, bool $canEdit, array $categories, array $category, array $subcategory): void {
        $subId = (int)$subcategory['id'];
        $here = SiteResolver::urlFor($base, (string)$category['slug'], (string)$subcategory['slug']);
        $cards = CardManagement::listForSubcategory($subId);
        $n = count($cards);

        $ownerActions = [
            ['label' => 'Edit deck', 'href' => '/manage/subcategory_edit.php?id=' . $subId . '&next=' . urlencode($here)],
            ['label' => 'Cards', 'href' => '/manage/cards.php?subcategory_id=' . $subId],
            ['label' => 'Add card', 'href' => '/manage/card_add.php?subcategory_id=' . $subId . '&next=' . urlencode($here)],
        ];
        $crumbs = [
            ['label' => $site['title'], 'url' => SiteResolver::urlFor($base)],
            ['label' => $category['name'], 'url' => SiteResolver::urlFor($base, (string)$category['slug'])],
        ];
        SiteUI::pageStart($site, $base, (string)$subcategory['name'], $categories, $crumbs, $canEdit ? $ownerActions : []);

        echo '<div class="page-head"><h2>' . self::h($subcategory['name']) . self::privateBadge(empty($category['is_public'])) . '</h2>';
        echo '<div class="actions">' . self::studyQuizButtonsHtml('subcategory', $subId, $n, 'Study', 'Quiz') . '</div></div>';
        if (trim((string)$subcategory['description']) !== '') {
            echo '<p>' . self::h($subcategory['description']) . '</p>';
        }
        echo '<p class="small">' . SiteUI::countLabel($n, 'card') . ' &middot; tap a card to see its back</p>';

        if ($cards === []) {
            echo self::emptyStateHtml(
                $canEdit,
                'No cards in this deck yet.',
                'This deck has no cards yet.',
                '/manage/card_add.php?subcategory_id=' . $subId . '&next=' . urlencode($here),
                'Add cards'
            );
        } else {
            echo '<div class="card-preview-grid">';
            foreach ($cards as $card) {
                echo self::cardPreviewHtml($card);
            }
            echo '</div>';
            echo '<script>document.querySelectorAll(".card-preview").forEach(function (el) {'
               . 'var flip = function () { el.classList.toggle("revealed"); };'
               . 'el.addEventListener("click", flip);'
               . 'el.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); flip(); } });'
               . '});</script>';
        }
        SiteUI::pageEnd();
    }

    // ---- fragments --------------------------------------------------------

    /** One card of the deck grid: the front (image and/or text), the hidden back. */
    private static function cardPreviewHtml(array $card): string {
        $front = trim((string)$card['front_text']);
        $thumb = ImageStorage::thumbUrlForCard($card);
        $html = '<div class="card-preview" id="card-' . (int)$card['id'] . '" tabindex="0" role="button" aria-label="Flip card">';
        $html .= '<div class="card-preview-front">';
        if ($thumb !== null) {
            $html .= '<img src="' . self::h($thumb) . '" alt="' . self::h($front !== '' ? $front : 'Card image') . '" loading="lazy">';
        }
        if ($front !== '') {
            $html .= '<div>' . nl2br(self::h($front)) . '</div>';
        }
        $html .= '</div>';
        $html .= '<div class="card-preview-back">' . nl2br(self::h($card['back_text'])) . '</div>';
        $html .= '<span class="card-preview-hint">tap to flip</span>';
        $html .= '</div>';
        return $html;
    }

    /** Study/Quiz buttons for a deck, or '' when it has no cards to study. */
    private static function studyQuizButtonsHtml(string $deckType, int $deckId, int $cardCount, string $studyLabel, string $quizLabel): string {
        if ($cardCount <= 0) {
            return '';
        }
        $q = $deckType . '=' . $deckId;
        return '<a class="button primary small" href="/review/study.php?' . $q . '">' . self::h($studyLabel) . '</a>'
             . '<a class="button small" href="/quiz/?' . $q . '">' . self::h($quizLabel) . '</a>';
    }

    private static function privateBadge(bool $isPrivate): string {
        return $isPrivate ? ' <span class="badge draft">Private</span>' : '';
    }

    /** Visitors get a quiet note; the owner gets a nudge with the link to fix it. */
    private static function emptyStateHtml(bool $canEdit, string $visitorText, string $ownerText, string $ownerUrl, string $ownerLabel): string {
        if (!$canEdit) {
            return '<div class="card empty-deck"><p class="muted">' . self::h($visitorText) . '</p></div>';
        }
        return '<div class="notice">' . self::h($ownerText) . ' <a href="' . self::h($ownerUrl) . '">' . self::h($ownerLabel) . '</a></div>';
    }
}
