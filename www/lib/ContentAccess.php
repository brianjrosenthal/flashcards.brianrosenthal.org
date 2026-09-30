<?php
declare(strict_types=1);

require_once __DIR__ . '/UserContext.php';

/**
 * The authorization rules for a user's decks (categories, subcategories,
 * cards): the owner or an app admin may change them; anyone may VIEW (browse
 * and study) a deck when the owner's page is public and the category is
 * public. Shared by the management and study classes so the rules cannot
 * drift between them.
 */
final class ContentAccess {

    public static function canEdit(?UserContext $ctx, int $ownerUserId): bool {
        return $ctx !== null && ($ctx->admin || $ctx->id === $ownerUserId);
    }

    public static function assertCanEdit(?UserContext $ctx, int $ownerUserId): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        if (!self::canEdit($ctx, $ownerUserId)) {
            throw new RuntimeException('You can only change your own content.');
        }
    }

    /**
     * Whether $ctx (null = anonymous visitor) may see a category and study its
     * cards. $site is the owner's sites row (null when they have none, which
     * counts as not public) and $category the categories row.
     */
    public static function canView(?UserContext $ctx, ?array $site, array $category): bool {
        $ownerUserId = (int)$category['user_id'];
        if (self::canEdit($ctx, $ownerUserId)) {
            return true;
        }
        return $site !== null && !empty($site['is_public']) && !empty($category['is_public']);
    }

    public static function assertCanView(?UserContext $ctx, ?array $site, array $category): void {
        if (!self::canView($ctx, $site, $category)) {
            throw new RuntimeException('This deck is private.');
        }
    }
}
