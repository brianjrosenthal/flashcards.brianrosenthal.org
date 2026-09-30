<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/CategoryManagement.php';
require_once __DIR__ . '/SubcategoryManagement.php';
require_once __DIR__ . '/SiteManagement.php';
require_once __DIR__ . '/ContentAccess.php';

/**
 * The thing a person studies: one subcategory, or a whole category (every
 * card of every subcategory in it). Every review/quiz page and endpoint
 * resolves its deck through here so the rules (which cards, who may view,
 * how it is named in URLs) cannot drift.
 */
final class Deck {
    public const TYPE_CATEGORY = 'category';
    public const TYPE_SUBCATEGORY = 'subcategory';

    public string $type;
    public int $id;
    public int $ownerUserId;
    public string $name;
    /** The category's name when this deck is a subcategory, else null. */
    public ?string $parentName;
    public int $categoryId;
    /** The categories row (for access checks and public URLs). */
    public array $category;
    /** The subcategories row when type = subcategory, else null. */
    public ?array $subcategory;

    private function __construct(string $type, array $category, ?array $subcategory) {
        $this->type = $type;
        $this->category = $category;
        $this->subcategory = $subcategory;
        $this->categoryId = (int)$category['id'];
        $this->ownerUserId = (int)$category['user_id'];
        if ($subcategory !== null) {
            $this->id = (int)$subcategory['id'];
            $this->name = (string)$subcategory['name'];
            $this->parentName = (string)$category['name'];
        } else {
            $this->id = (int)$category['id'];
            $this->name = (string)$category['name'];
            $this->parentName = null;
        }
    }

    /** Null when the type is unknown or the row does not exist. */
    public static function of(string $type, int $id): ?Deck {
        if ($id <= 0) {
            return null;
        }
        if ($type === self::TYPE_CATEGORY) {
            $cat = CategoryManagement::findById($id);
            return $cat ? new Deck($type, $cat, null) : null;
        }
        if ($type === self::TYPE_SUBCATEGORY) {
            $sub = SubcategoryManagement::findById($id);
            if (!$sub) {
                return null;
            }
            $cat = CategoryManagement::findById((int)$sub['category_id']);
            return $cat ? new Deck($type, $cat, $sub) : null;
        }
        return null;
    }

    /**
     * From ?subcategory=N / ?category=N (or POST deck_type + deck_id). A
     * subcategory parameter wins when both are present. Null when absent or
     * unknown. Access is NOT checked here: call assertCanView() next.
     */
    public static function fromRequest(array $params): ?Deck {
        if (!empty($params['subcategory'])) {
            return self::of(self::TYPE_SUBCATEGORY, (int)$params['subcategory']);
        }
        if (!empty($params['category'])) {
            return self::of(self::TYPE_CATEGORY, (int)$params['category']);
        }
        if (!empty($params['deck_type']) && !empty($params['deck_id'])) {
            return self::of((string)$params['deck_type'], (int)$params['deck_id']);
        }
        return null;
    }

    /** The deck a card belongs to, as a subcategory deck. */
    public static function ofCard(int $cardId): ?Deck {
        require_once __DIR__ . '/CardManagement.php';
        $card = CardManagement::findById($cardId);
        return $card ? self::of(self::TYPE_SUBCATEGORY, (int)$card['subcategory_id']) : null;
    }

    public function isCategory(): bool {
        return $this->type === self::TYPE_CATEGORY;
    }

    private ?array $siteRow = null;
    private bool $siteLoaded = false;

    /** The owner's sites row, for access checks and public URLs (loaded once per Deck). */
    public function site(): ?array {
        if (!$this->siteLoaded) {
            $this->siteRow = SiteManagement::findByUserId($this->ownerUserId);
            $this->siteLoaded = true;
        }
        return $this->siteRow;
    }

    public function canView(?UserContext $ctx): bool {
        return ContentAccess::canView($ctx, $this->site(), $this->category);
    }

    public function assertCanView(?UserContext $ctx): void {
        ContentAccess::assertCanView($ctx, $this->site(), $this->category);
    }

    public function canEdit(?UserContext $ctx): bool {
        return ContentAccess::canEdit($ctx, $this->ownerUserId);
    }

    /**
     * WHERE fragment restricting the cards table (aliased $cardAlias) to this
     * deck, with its parameters.
     * @return array{0:string,1:array}
     */
    public function scopeSql(string $cardAlias = 'k'): array {
        if ($this->isCategory()) {
            return [$cardAlias . '.subcategory_id IN (SELECT id FROM subcategories WHERE category_id = ?)', [$this->id]];
        }
        return [$cardAlias . '.subcategory_id = ?', [$this->id]];
    }

    /** 'subcategory=7' or 'category=3', for building study/quiz links. */
    public function queryString(): string {
        return $this->type . '=' . $this->id;
    }

    /** "US History › Presidents" or "US History (all)". */
    public function label(): string {
        return $this->isCategory() ? $this->name . ' (all)' : $this->parentName . ' › ' . $this->name;
    }

    public function studyUrl(string $filter = ''): string {
        return '/review/study.php?' . $this->queryString() . ($filter !== '' ? '&filter=' . urlencode($filter) : '');
    }

    public function quizUrl(): string {
        return '/quiz/?' . $this->queryString();
    }

    public function cardCount(): int {
        [$where, $params] = $this->scopeSql('k');
        $st = pdo()->prepare('SELECT COUNT(*) FROM cards k WHERE ' . $where);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }
}
