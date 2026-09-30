<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CardManagementTest extends TestCase
{
    private UserContext $charlie;
    private int $categoryId;
    private int $subcategoryId;
    /** @var int[] */
    private array $cardIds;

    protected function setUp(): void
    {
        test_reset_all();
        test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $ids = test_seed_tree($this->charlie, 'charlie', 3);
        $this->categoryId = $ids['category_id'];
        $this->subcategoryId = $ids['subcategory_id'];
        $this->cardIds = $ids['card_ids'];
    }

    private function storage(): FakeS3Client
    {
        return ImageStorage::storage();
    }

    private function preparedImage(int $width = 80, int $height = 60): array
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 100, 50));
        ob_start();
        imagepng($img);
        return ImageStorage::prepareFromBytes((string)ob_get_clean());
    }

    private function actionTypes(): array
    {
        return array_column(ActivityLog::list([], 100), 'action_type');
    }

    // --- create / lookups ---------------------------------------------------

    public function testCreateTextCardAndLookups(): void
    {
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => '  George Washington ', 'back_text' => "1st president\n"]);
        $card = CardManagement::findById($id);
        $this->assertSame('George Washington', $card['front_text'], 'text is trimmed');
        $this->assertSame('1st president', $card['back_text']);
        $this->assertNull($card['image_object_key']);
        $this->assertSame(4, (int)$card['sort_order'], 'appended after the three seeded cards');

        $full = CardManagement::findWithAncestors($id);
        $this->assertSame('Presidents', $full['subcategory_name']);
        $this->assertSame('presidents', $full['subcategory_slug']);
        $this->assertSame($this->categoryId, (int)$full['category_id']);
        $this->assertSame('US History', $full['category_name']);
        $this->assertSame('us-history', $full['category_slug']);
        $this->assertSame($this->charlie->id, (int)$full['user_id']);
        $this->assertSame($this->charlie->id, CardManagement::ownerUserIdOf($id));
        $this->assertNull(CardManagement::ownerUserIdOf(9999));
        $this->assertNull(CardManagement::findById(9999));
        $this->assertNull(CardManagement::findWithAncestors(9999));

        $this->assertSame(4, CardManagement::countForSubcategory($this->subcategoryId));
        $this->assertSame(array_merge($this->cardIds, [$id]), array_map('intval', array_column(CardManagement::listForSubcategory($this->subcategoryId), 'id')));
        $inCategory = CardManagement::listForCategory($this->categoryId);
        $this->assertCount(4, $inCategory);
        $this->assertSame('Presidents', $inCategory[0]['subcategory_name']);
        $this->assertContains('card.create', $this->actionTypes());
    }

    public function testListForCategoryFollowsDeckOrderThenCardOrder(): void
    {
        $second = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Vice Presidents']);
        $vp = CardManagement::create($this->charlie, $second, ['front_text' => 'VP', 'back_text' => 'Adams']);
        SubcategoryManagement::update($this->charlie, $second, ['sort_order' => 0]);
        $ids = array_map('intval', array_column(CardManagement::listForCategory($this->categoryId), 'id'));
        $this->assertSame(array_merge([$vp], $this->cardIds), $ids);
    }

    public function testFrontOrImageRuleAndBackRequired(): void
    {
        try {
            CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'Q', 'back_text' => '  ']);
            $this->fail('back is required');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('The back of the card is required.', $e->getMessage());
        }
        try {
            CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => '', 'back_text' => 'A']);
            $this->fail('front text or image is required');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('some text, an image, or both', $e->getMessage());
        }
        try {
            CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => str_repeat('x', CardManagement::MAX_TEXT + 1), 'back_text' => 'A']);
            $this->fail('front too long');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString((string)CardManagement::MAX_TEXT, $e->getMessage());
        }
        $this->assertSame(3, CardManagement::countForSubcategory($this->subcategoryId), 'nothing was written');

        // An image alone is a valid front.
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => '', 'back_text' => 'Lincoln'], $this->preparedImage());
        $this->assertSame('', CardManagement::findById($id)['front_text']);

        // ...and taking that image away again is refused while the front is empty.
        try {
            CardManagement::update($this->charlie, $id, [], null, true);
            $this->fail('removing the only front must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('some text, an image, or both', $e->getMessage());
        }
        $this->assertNotNull(CardManagement::findById($id)['image_object_key']);
        try {
            CardManagement::removeImage($this->charlie, $id);
            $this->fail('removeImage must apply the same rule');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('some text, an image, or both', $e->getMessage());
        }
        // Giving it text in the same save makes the removal fine.
        CardManagement::update($this->charlie, $id, ['front_text' => 'Who?'], null, true);
        $this->assertNull(CardManagement::findById($id)['image_object_key']);
    }

    public function testCreateInUnknownDeckFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deck not found.');
        CardManagement::create($this->charlie, 9999, ['front_text' => 'x', 'back_text' => 'y']);
    }

    // --- images --------------------------------------------------------------

    public function testCreateWithImageStoresTwoObjectsAndRecordsColumns(): void
    {
        $prepared = $this->preparedImage(80, 60);
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'Who is this?', 'back_text' => 'Lincoln'], $prepared);
        $card = CardManagement::findById($id);
        $key = (string)$card['image_object_key'];
        $this->assertTrue(ImageStorage::keyBelongsToCard($key, $id));
        $this->assertStringStartsWith('cards/' . $this->charlie->id . '/' . $id . '/', $key, 'keyed by the deck OWNER, not the actor');
        $this->assertSame('image/png', $card['image_content_type']);
        $this->assertSame([80, 60], [(int)$card['image_width'], (int)$card['image_height']]);
        $this->assertSame($prepared['size'], (int)$card['image_size_bytes']);
        $this->assertNotNull($card['image_uploaded_at']);

        $bucket = ImageStorage::bucket();
        $objects = $this->storage()->listObjects($bucket);
        $this->assertCount(2, $objects);
        $this->assertTrue($this->storage()->objectExists($bucket, $key));
        $this->assertTrue($this->storage()->objectExists($bucket, ImageStorage::thumbKeyFor($key)));
        $this->assertSame([$key], CardManagement::listImageObjectKeys());
        $this->assertNotNull(ImageStorage::displayUrlForCard($card));
    }

    public function testCreateRollsBackTheRowWhenStorageFails(): void
    {
        // FakeS3Client is final; a minimal S3Client whose only reachable call fails.
        $failing = new class('https://r2-test.example', 'auto', 'AKIATEST', 'secret-test') extends S3Client {
            public function putObject(string $bucket, string $key, string $body, string $contentType, string $cacheControl): void {
                throw new RuntimeException('Could not save the object to storage: simulated outage');
            }
            protected function send(string $method, string $url, array $headers, string $body): array {
                throw new LogicException('must not reach the network');
            }
        };
        $original = $this->storage();
        ImageStorage::storage($failing);
        try {
            try {
                CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => '', 'back_text' => 'B'], $this->preparedImage());
                $this->fail('storage failure must surface');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('simulated outage', $e->getMessage());
            }
            $this->assertSame(3, CardManagement::countForSubcategory($this->subcategoryId), 'the half-made row was removed');
        } finally {
            ImageStorage::storage($original);
        }
    }

    public function testReplacingAnImageDeletesTheOldObjects(): void
    {
        $bucket = ImageStorage::bucket();
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'A', 'back_text' => 'B'], $this->preparedImage(80, 60));
        $key1 = (string)CardManagement::findById($id)['image_object_key'];

        CardManagement::update($this->charlie, $id, ['front_text' => 'A2'], $this->preparedImage(50, 50));
        $card = CardManagement::findById($id);
        $key2 = (string)$card['image_object_key'];
        $this->assertNotSame($key1, $key2, 'a replacement never reuses a key');
        $this->assertSame('A2', $card['front_text']);
        $this->assertSame([50, 50], [(int)$card['image_width'], (int)$card['image_height']]);
        $this->assertFalse($this->storage()->objectExists($bucket, $key1), 'old image deleted');
        $this->assertFalse($this->storage()->objectExists($bucket, ImageStorage::thumbKeyFor($key1)), 'old thumb deleted');
        $this->assertTrue($this->storage()->objectExists($bucket, $key2));
        $this->assertCount(2, $this->storage()->listObjects($bucket));

        // setImage() does the same without touching the text.
        CardManagement::setImage($this->charlie, $id, $this->preparedImage(30, 30));
        $card = CardManagement::findById($id);
        $this->assertNotSame($key2, $card['image_object_key']);
        $this->assertSame('A2', $card['front_text']);
        $this->assertFalse($this->storage()->objectExists($bucket, $key2));
        $this->assertCount(2, $this->storage()->listObjects($bucket));
        $this->assertContains('card.image_set', $this->actionTypes());
    }

    public function testReplacingWhenTheOldDeleteFailsKeepsTheNewImageAndLogs(): void
    {
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'A', 'back_text' => 'B'], $this->preparedImage());
        $key1 = (string)CardManagement::findById($id)['image_object_key'];
        $this->storage()->failDeletes = true;
        CardManagement::update($this->charlie, $id, [], $this->preparedImage(20, 20));
        $key2 = (string)CardManagement::findById($id)['image_object_key'];
        $this->assertNotSame($key1, $key2, 'the row points at the new image even though the old one could not be deleted');
        $this->assertTrue($this->storage()->objectExists(ImageStorage::bucket(), $key1), 'old object left behind (shows up as an orphan)');
        $this->assertContains('card.image_delete_failed', $this->actionTypes());
    }

    public function testRemoveImageDeletesStorageThenClearsColumns(): void
    {
        $bucket = ImageStorage::bucket();
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'A', 'back_text' => 'B'], $this->preparedImage());
        $key = (string)CardManagement::findById($id)['image_object_key'];

        CardManagement::removeImage($this->charlie, $id);
        $card = CardManagement::findById($id);
        $this->assertNull($card['image_object_key']);
        $this->assertNull($card['image_content_type']);
        $this->assertNull($card['image_width']);
        $this->assertNull($card['image_size_bytes']);
        $this->assertNull($card['image_uploaded_at']);
        $this->assertSame([], $this->storage()->listObjects($bucket));
        $this->assertSame([], CardManagement::listImageObjectKeys());
        CardManagement::removeImage($this->charlie, $id); // no image: no-op
        $this->assertContains('card.image_remove', $this->actionTypes());

        // When storage refuses, the row keeps pointing at the object so the user can retry.
        $id2 = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'A', 'back_text' => 'B'], $this->preparedImage());
        $key2 = (string)CardManagement::findById($id2)['image_object_key'];
        $this->storage()->failDeletes = true;
        try {
            CardManagement::update($this->charlie, $id2, [], null, true);
            $this->fail('storage failure must surface');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated', $e->getMessage());
        }
        $this->assertSame($key2, CardManagement::findById($id2)['image_object_key']);
    }

    public function testUpdateTextAndOrderLeavesImageAlone(): void
    {
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'A', 'back_text' => 'B'], $this->preparedImage());
        $key = (string)CardManagement::findById($id)['image_object_key'];
        CardManagement::update($this->charlie, $id, ['back_text' => 'B2', 'sort_order' => 42]);
        $card = CardManagement::findById($id);
        $this->assertSame(['A', 'B2', 42, $key], [$card['front_text'], $card['back_text'], (int)$card['sort_order'], $card['image_object_key']]);
        $this->assertCount(2, $this->storage()->listObjects(ImageStorage::bucket()));
        $this->assertContains('card.update', $this->actionTypes());

        $this->expectException(RuntimeException::class);
        CardManagement::update($this->charlie, 9999, ['back_text' => 'x']);
    }

    // --- delete --------------------------------------------------------------

    public function testDeleteCleansStorageAndCascadesProgress(): void
    {
        $bucket = ImageStorage::bucket();
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'A', 'back_text' => 'B'], $this->preparedImage());
        $key = (string)CardManagement::findById($id)['image_object_key'];
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        pdo()->prepare('INSERT INTO user_card_state (user_id, card_id, is_flagged, last_mark) VALUES (?, ?, 1, ?)')->execute([$lilly->id, $id, 'got_it']);
        pdo()->prepare('INSERT INTO card_review_events (user_id, card_id, mark) VALUES (?, ?, ?)')->execute([$lilly->id, $id, 'got_it']);
        pdo()->prepare("INSERT INTO quiz_attempts (user_id, card_id, answer_text, result, points_awarded) VALUES (?, ?, 'B', 'correct', 10)")->execute([$lilly->id, $id]);

        CardManagement::delete($this->charlie, $id);
        $this->assertNull(CardManagement::findById($id));
        $this->assertFalse($this->storage()->objectExists($bucket, $key));
        $this->assertFalse($this->storage()->objectExists($bucket, ImageStorage::thumbKeyFor($key)));
        foreach (['user_card_state', 'card_review_events', 'quiz_attempts'] as $table) {
            $st = pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE card_id = ?');
            $st->execute([$id]);
            $this->assertSame(0, (int)$st->fetchColumn(), $table . ' rows cascade with the card');
        }
        $this->assertContains('card.delete', $this->actionTypes());
    }

    public function testDeleteStillRemovesTheRowWhenStorageFails(): void
    {
        $id = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => 'A', 'back_text' => 'B'], $this->preparedImage());
        $this->storage()->failDeletes = true;
        CardManagement::delete($this->charlie, $id);
        $this->assertNull(CardManagement::findById($id));
        $this->assertContains('card.image_delete_failed', $this->actionTypes());
    }

    public function testDeleteAllInSubcategory(): void
    {
        $bucket = ImageStorage::bucket();
        CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => '', 'back_text' => 'B'], $this->preparedImage());
        CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => '', 'back_text' => 'C'], $this->preparedImage());
        $other = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Other']);
        $keep = CardManagement::create($this->charlie, $other, ['front_text' => 'K', 'back_text' => 'K'], $this->preparedImage());
        $this->assertCount(6, $this->storage()->listObjects($bucket));

        $this->assertSame(5, CardManagement::deleteAllInSubcategory($this->charlie, $this->subcategoryId));
        $this->assertSame(0, CardManagement::countForSubcategory($this->subcategoryId));
        $this->assertCount(2, $this->storage()->listObjects($bucket), 'only the other deck\'s image remains');
        $this->assertNotNull(CardManagement::findById($keep));
        $this->assertSame(0, CardManagement::deleteAllInSubcategory($this->charlie, $this->subcategoryId), 'empty deck: nothing to do');

        // A storage outage does not stop the deck from being emptied.
        CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => '', 'back_text' => 'B'], $this->preparedImage());
        $this->storage()->failDeletes = true;
        $this->assertSame(1, CardManagement::deleteAllInSubcategory($this->charlie, $this->subcategoryId));
        $this->assertContains('card.image_delete_failed', $this->actionTypes());

        $this->expectException(RuntimeException::class);
        CardManagement::deleteAllInSubcategory($this->charlie, 9999);
    }

    public function testDeletingTheDeckRemovesCardsAndImages(): void
    {
        CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => '', 'back_text' => 'B'], $this->preparedImage());
        SubcategoryManagement::delete($this->charlie, $this->subcategoryId);
        $this->assertNull(SubcategoryManagement::findById($this->subcategoryId));
        $this->assertSame(0, CardManagement::countForSubcategory($this->subcategoryId));
        $this->assertSame([], $this->storage()->listObjects(ImageStorage::bucket()));
    }

    // --- ordering ------------------------------------------------------------

    public function testMoveInOrderSwapsNeighboursAndStopsAtTheEnds(): void
    {
        [$a, $b, $c] = $this->cardIds;
        $order = fn(): array => array_map('intval', array_column(CardManagement::listForSubcategory($this->subcategoryId), 'id'));

        CardManagement::moveInOrder($this->charlie, $b, 'up');
        $this->assertSame([$b, $a, $c], $order());
        CardManagement::moveInOrder($this->charlie, $b, 'up');
        $this->assertSame([$b, $a, $c], $order(), 'already first: no-op');
        CardManagement::moveInOrder($this->charlie, $a, 'down');
        $this->assertSame([$b, $c, $a], $order());
        CardManagement::moveInOrder($this->charlie, $a, 'down');
        $this->assertSame([$b, $c, $a], $order(), 'already last: no-op');
        $this->assertContains('card.move', $this->actionTypes());

        // Duplicate sort_orders (e.g. after bulk edits) are normalized first.
        pdo()->exec('UPDATE cards SET sort_order = 0');
        $this->assertSame([$a, $b, $c], $order(), 'ties are broken by id');
        CardManagement::moveInOrder($this->charlie, $b, 'up');
        $this->assertSame([$b, $a, $c], $order(), 'renumbered 1..n first, then the swap applies');
        $this->assertSame([1, 2, 3], array_map('intval', array_column(CardManagement::listForSubcategory($this->subcategoryId), 'sort_order')));

        $this->expectException(InvalidArgumentException::class);
        CardManagement::moveInOrder($this->charlie, $a, 'sideways');
    }

    // --- bulk add ------------------------------------------------------------

    public function testParseBulkLinesAcceptsPipeAndTab(): void
    {
        $parsed = CardManagement::parseBulkLines("George Washington | 1st president\r\n\n  John Adams\t2nd president  \nJefferson | 3rd | with a pipe\n");
        $this->assertSame([
            ['front' => 'George Washington', 'back' => '1st president'],
            ['front' => 'John Adams', 'back' => '2nd president'],
            ['front' => 'Jefferson', 'back' => '3rd | with a pipe'],
        ], $parsed, 'blank lines skipped, first "|" splits, tab accepted');
    }

    public function testParseBulkLinesNamesEveryBadLine(): void
    {
        try {
            CardManagement::parseBulkLines("ok | fine\n\nno separator here\nok | fine\n | empty front\nempty back |\n");
            $this->fail('bad lines must be reported');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Line 3 has no "|" between front and back; line 5 has an empty front; line 6 has an empty back.', $e->getMessage());
        }
        try {
            CardManagement::parseBulkLines("   \n\n");
            $this->fail('nothing to add');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at least one line', $e->getMessage());
        }
        try {
            CardManagement::parseBulkLines('a | ' . str_repeat('b', CardManagement::MAX_TEXT + 1));
            $this->fail('too long');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('line 1 has a back longer than', strtolower($e->getMessage()));
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most ' . CardManagement::BULK_MAX_LINES);
        CardManagement::parseBulkLines(str_repeat("a | b\n", CardManagement::BULK_MAX_LINES + 1));
    }

    public function testBulkCreateIsAllOrNothing(): void
    {
        $count = CardManagement::bulkCreateFromLines($this->charlie, $this->subcategoryId, "Lincoln | 16th\nGrant | 18th\n");
        $this->assertSame(2, $count);
        $cards = CardManagement::listForSubcategory($this->subcategoryId);
        $this->assertCount(5, $cards);
        $this->assertSame(['Lincoln', 'Grant'], [$cards[3]['front_text'], $cards[4]['front_text']]);
        $this->assertSame([4, 5], [(int)$cards[3]['sort_order'], (int)$cards[4]['sort_order']], 'appended in order');
        $this->assertSame(1, count(array_filter($this->actionTypes(), fn($t) => $t === 'card.bulk_create')), 'logged once');

        try {
            CardManagement::bulkCreateFromLines($this->charlie, $this->subcategoryId, "Fine | ok\nbroken line\n");
            $this->fail('one bad line rejects the batch');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Line 2', $e->getMessage());
        }
        $this->assertSame(5, CardManagement::countForSubcategory($this->subcategoryId), 'nothing from the failed batch was written');

        $this->expectException(RuntimeException::class);
        CardManagement::bulkCreateFromLines($this->charlie, 9999, 'a | b');
    }

    // --- access ----------------------------------------------------------------

    public function testOnlyOwnerOrAdminMayWrite(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $admin = new UserContext(1, true);
        $id = $this->cardIds[0];

        CardManagement::update($admin, $id, ['back_text' => 'Admin touched']);
        $this->assertSame('Admin touched', CardManagement::findById($id)['back_text']);
        $adminCard = CardManagement::create($admin, $this->subcategoryId, ['front_text' => 'By admin', 'back_text' => 'x'], $this->preparedImage());
        $this->assertStringStartsWith('cards/' . $this->charlie->id . '/', CardManagement::findById($adminCard)['image_object_key'], 'stored under the owner even when an admin uploads');
        CardManagement::moveInOrder($admin, $id, 'down');
        $this->assertSame(2, CardManagement::bulkCreateFromLines($admin, $this->subcategoryId, "a | b\nc | d"));
        CardManagement::delete($admin, $adminCard);

        $attempts = [
            'create' => fn() => CardManagement::create($lilly, $this->subcategoryId, ['front_text' => 'Planted', 'back_text' => 'x']),
            'update' => fn() => CardManagement::update($lilly, $id, ['back_text' => 'Hijack']),
            'delete' => fn() => CardManagement::delete($lilly, $id),
            'setImage' => fn() => CardManagement::setImage($lilly, $id, $this->preparedImage()),
            'removeImage' => fn() => CardManagement::removeImage($lilly, $id),
            'moveInOrder' => fn() => CardManagement::moveInOrder($lilly, $id, 'up'),
            'bulkCreateFromLines' => fn() => CardManagement::bulkCreateFromLines($lilly, $this->subcategoryId, 'a | b'),
            'deleteAllInSubcategory' => fn() => CardManagement::deleteAllInSubcategory($lilly, $this->subcategoryId),
        ];
        foreach ($attempts as $method => $attempt) {
            try {
                $attempt();
                $this->fail($method . ' must be refused for another user');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('own content', $e->getMessage(), $method);
            }
        }
        try {
            CardManagement::create(null, $this->subcategoryId, ['front_text' => 'Anon', 'back_text' => 'x']);
            $this->fail('anonymous must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Login required', $e->getMessage());
        }
        $this->assertSame(5, CardManagement::countForSubcategory($this->subcategoryId));
        $this->assertSame([], $this->storage()->listObjects(ImageStorage::bucket()), 'no stray objects from refused writes');
    }

    public function testNeighborsFollowDisplayOrder(): void {
        $ids = [];
        foreach (['A', 'B', 'C'] as $letter) {
            $ids[] = CardManagement::create($this->charlie, $this->subcategoryId, ['front_text' => $letter, 'back_text' => 'back ' . $letter]);
        }
        // The seeded deck already holds three text cards, so A is card 4 of 6.
        $veryFirst = CardManagement::neighbors($this->cardIds[0]);
        $this->assertNull($veryFirst['prev']);
        $this->assertSame(1, $veryFirst['index']);

        $first = CardManagement::neighbors($ids[0]);
        $this->assertSame($this->cardIds[2], (int)$first['prev']['id']);
        $this->assertSame($ids[1], (int)$first['next']['id']);
        $this->assertSame(4, $first['index']);
        $this->assertSame(6, $first['total']);

        $middle = CardManagement::neighbors($ids[1]);
        $this->assertSame($ids[0], (int)$middle['prev']['id']);
        $this->assertSame($ids[2], (int)$middle['next']['id']);
        $this->assertSame(5, $middle['index']);

        $last = CardManagement::neighbors($ids[2]);
        $this->assertNull($last['next']);

        // Moving C to the front changes who its neighbours are.
        CardManagement::moveInOrder($this->charlie, $ids[2], 'up');
        CardManagement::moveInOrder($this->charlie, $ids[2], 'up');
        $this->assertSame($ids[0], (int)CardManagement::neighbors($ids[2])['next']['id']);
        $this->assertSame(['prev' => null, 'next' => null, 'index' => 0, 'total' => 0], CardManagement::neighbors(999999));
    }
}
