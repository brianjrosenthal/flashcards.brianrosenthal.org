<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../www/lib/CardImageImport.php';

/**
 * Picture import: file names become backs, ZIPs are unpacked, junk and
 * duplicates are flagged, and commitBatch() creates cards with images in
 * (fake) storage a few at a time.
 */
final class CardImageImportTest extends TestCase {

    private string $dir;
    private UserContext $owner;
    private array $tree;

    protected function setUp(): void {
        test_reset_all();
        $this->dir = sys_get_temp_dir() . '/flashcards-import-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        CardImageImport::setDirForTesting($this->dir);
        test_seed_admin();
        $this->owner = test_seed_user('owner@example.com', 'Charlie');
        UserContext::set($this->owner);
        $this->tree = test_seed_tree($this->owner, 'charlie', 1);   // one text card: "Front 1" / "Back 1"
    }

    protected function tearDown(): void {
        CardImageImport::setDirForTesting(null);
        $this->rmdir($this->dir);
    }

    private function rmdir(string $dir): void {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    private function pngBytes(int $w = 40, int $h = 30): string {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 120, 60, 240));
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    /** @param array<string,string> $members name => bytes */
    private function zipFile(array $members): string {
        $path = $this->dir . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($members as $name => $bytes) {
            if (substr($name, -1) === '/') {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $bytes);
            }
        }
        $zip->close();
        return $path;
    }

    private function upload(string $path, string $name): array {
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($path)];
    }

    // ---- names ---------------------------------------------------------------

    public function testBackTextFromFilename(): void {
        $this->assertSame('Ada Lovelace', CardImageImport::backTextFromFilename('faces/Ada_Lovelace.JPG'));
        $this->assertSame('Grace Hopper', CardImageImport::backTextFromFilename('Grace%20Hopper.png'));
        $this->assertSame('Mr. T', CardImageImport::backTextFromFilename('Mr._T.webp'));
        $this->assertSame('Two words', CardImageImport::backTextFromFilename('Two  words.gif'), 'runs of whitespace collapse');
        $this->assertSame('Two words', CardImageImport::backTextFromFilename('Two___words.gif'));
        $this->assertSame('', CardImageImport::backTextFromFilename('.jpg'));
        $this->assertSame('noext', CardImageImport::backTextFromFilename('noext'));
        $this->assertSame('José Martí', CardImageImport::backTextFromFilename('José_Martí.jpeg'));
    }

    public function testJunkEntries(): void {
        $this->assertTrue(CardImageImport::isJunkEntry('__MACOSX/faces/._Ada.jpg'));
        $this->assertTrue(CardImageImport::isJunkEntry('faces/'));
        $this->assertTrue(CardImageImport::isJunkEntry('faces/.DS_Store'));
        $this->assertFalse(CardImageImport::isJunkEntry('faces/Ada_Lovelace.jpg'));
    }

    // ---- stash ----------------------------------------------------------------

    public function testZipIsUnpackedWithStatuses(): void {
        $png = $this->pngBytes();
        $zip = $this->zipFile([
            'faces/' => '',
            'faces/Ada_Lovelace.png' => $png,
            'faces/Grace_Hopper.png' => $png,
            'faces/grace hopper.png' => $png,          // same back, different file -> duplicate
            'faces/Back_1.png' => $png,                // already a card in the deck -> exists
            'faces/notes.txt' => 'hello',              // not an image
            'faces/fake.png' => 'not really a png',    // wrong bytes
            '__MACOSX/faces/._Ada_Lovelace.png' => 'junk',
        ]);
        $token = CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [$this->upload($zip, 'faces.zip')]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);

        $m = CardImageImport::load($token, $this->owner);
        $byName = [];
        foreach ($m['entries'] as $e) {
            $byName[$e['source']] = $e;
        }
        $this->assertCount(6, $m['entries'], 'folders and __MACOSX are not entries');
        $this->assertSame(CardImageImport::STATUS_OK, $byName['faces/Ada_Lovelace.png']['status']);
        $this->assertSame('Ada Lovelace', $byName['faces/Ada_Lovelace.png']['back']);
        $this->assertSame(40, $byName['faces/Ada_Lovelace.png']['width']);
        $this->assertSame(CardImageImport::STATUS_OK, $byName['faces/Grace_Hopper.png']['status']);
        $this->assertSame(CardImageImport::STATUS_DUPLICATE, $byName['faces/grace hopper.png']['status']);
        $this->assertSame(CardImageImport::STATUS_EXISTS, $byName['faces/Back_1.png']['status']);
        $this->assertSame(CardImageImport::STATUS_SKIPPED, $byName['faces/notes.txt']['status']);
        $this->assertSame(CardImageImport::STATUS_SKIPPED, $byName['faces/fake.png']['status']);

        $summary = CardImageImport::summarize($m);
        $this->assertSame(2, $summary['will_import']);
        $this->assertSame(1, $summary[CardImageImport::STATUS_EXISTS]);
        $this->assertNotNull(CardImageImport::entryFilePath($m, 0));
        $this->assertNull(CardImageImport::entryFilePath($m, 4), 'skipped text file has no stored image');
    }

    public function testLooseImagesAndZipTogether(): void {
        $png = $this->pngBytes();
        $loose = $this->dir . '/Alan_Turing.png';
        file_put_contents($loose, $png);
        $zip = $this->zipFile(['Ada_Lovelace.png' => $png]);
        $token = CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [
            $this->upload($loose, 'Alan_Turing.png'),
            $this->upload($zip, 'more.zip'),
        ]);
        $m = CardImageImport::load($token, $this->owner);
        $this->assertSame(['Alan Turing', 'Ada Lovelace'], array_column($m['entries'], 'back'));
    }

    public function testAZipWithNoImagesStillReviewsButImportsNothing(): void {
        $zip = $this->zipFile(['readme.txt' => 'x']);
        $token = CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [$this->upload($zip, 'empty.zip'), ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]]);
        $summary = CardImageImport::summarize(CardImageImport::load($token, $this->owner));
        $this->assertSame(0, $summary['will_import']);
        $this->assertSame(1, $summary[CardImageImport::STATUS_SKIPPED]);
    }

    public function testNothingUploadedThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]]);
    }

    public function testOnlyTheOwnerOrAnAdminMayImport(): void {
        $stranger = test_seed_user('stranger@example.com');
        $zip = $this->zipFile(['Ada_Lovelace.png' => $this->pngBytes()]);
        try {
            CardImageImport::stashUpload($stranger, $this->tree['subcategory_id'], [$this->upload($zip, 'faces.zip')]);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('own content', $e->getMessage());
        }
        $token = CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [$this->upload($zip, 'faces.zip')]);
        try {
            CardImageImport::load($token, $stranger);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('someone else', $e->getMessage());
        }
        $admin = new UserContext(1, true);
        $this->assertSame($token, CardImageImport::load($token, $admin)['token']);
    }

    // ---- commit ----------------------------------------------------------------

    public function testCommitBatchCreatesCardsWithImagesInBatches(): void {
        $png = $this->pngBytes(2000, 500);
        $members = [];
        for ($i = 1; $i <= 6; $i++) {
            $members['Person_' . $i . '.png'] = $png;
        }
        $members['Back_1.png'] = $png;   // exists; excluded unless import_existing
        $zip = $this->zipFile($members);
        $token = CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [$this->upload($zip, 'people.zip')]);

        $m = CardImageImport::commitBatch($token, $this->owner, 4);
        $this->assertFalse($m['done']);
        $this->assertSame(4, $m['created']);

        $m = CardImageImport::commitBatch($token, $this->owner, 4);
        $this->assertTrue($m['done']);
        $this->assertSame(6, $m['created']);
        $this->assertSame(0, $m['failed']);

        $cards = CardManagement::listForSubcategory($this->tree['subcategory_id']);
        $this->assertCount(7, $cards, 'the original text card plus six pictures');
        $backs = array_column($cards, 'back_text');
        $this->assertContains('Person 1', $backs);
        $this->assertContains('Person 6', $backs);
        $this->assertNotContains('Back 1 (copy)', $backs);
        $imageCards = array_values(array_filter($cards, static fn(array $c): bool => !empty($c['image_object_key'])));
        $this->assertCount(6, $imageCards);
        $this->assertSame('', $imageCards[0]['front_text']);
        $this->assertSame(1600, (int)$imageCards[0]['image_width'], 'resized on the way in');
        $this->assertSame(12, count(ImageStorage::storage()->objects), 'main + thumb per card');

        // Files are cleaned up once done, the manifest stays for the summary.
        $this->assertSame([], glob($this->dir . '/' . $token . '/entry-*') ?: []);
        $this->assertTrue(CardImageImport::load($token, $this->owner)['done']);
        // A further call is a no-op.
        $this->assertSame(6, CardImageImport::commitBatch($token, $this->owner)['created']);
    }

    public function testImportExistingOptionAddsASecondCard(): void {
        $zip = $this->zipFile(['Back_1.png' => $this->pngBytes()]);
        $token = CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [$this->upload($zip, 'one.zip')]);
        $this->assertSame(0, CardImageImport::summarize(CardImageImport::load($token, $this->owner))['will_import']);

        CardImageImport::setOptions($token, $this->owner, ['import_existing' => true]);
        $this->assertSame(1, CardImageImport::summarize(CardImageImport::load($token, $this->owner))['will_import']);
        $m = CardImageImport::commitBatch($token, $this->owner);
        $this->assertTrue($m['done']);
        $this->assertSame(1, $m['created']);
        $this->assertCount(2, CardManagement::listForSubcategory($this->tree['subcategory_id']));
    }

    public function testDiscardRemovesTheFolder(): void {
        $zip = $this->zipFile(['Ada_Lovelace.png' => $this->pngBytes()]);
        $token = CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [$this->upload($zip, 'faces.zip')]);
        $this->assertDirectoryExists($this->dir . '/' . $token);
        CardImageImport::discard($token, $this->owner);
        $this->assertDirectoryDoesNotExist($this->dir . '/' . $token);
        $this->expectException(InvalidArgumentException::class);
        CardImageImport::load($token, $this->owner);
    }

    public function testStaleImportsArePurged(): void {
        $zip = $this->zipFile(['Ada_Lovelace.png' => $this->pngBytes()]);
        $token = CardImageImport::stashUpload($this->owner, $this->tree['subcategory_id'], [$this->upload($zip, 'faces.zip')]);
        $this->assertSame(0, CardImageImport::purgeStale());
        $this->assertSame(1, CardImageImport::purgeStale(time() + CardImageImport::STALE_SECONDS + 10));
        $this->assertDirectoryDoesNotExist($this->dir . '/' . $token);
    }

    public function testNormalizeFilesArray(): void {
        $rows = CardImageImport::normalizeFilesArray([
            'name' => ['a.zip', 'b.png'], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'error' => [0, 4], 'size' => [10, 0],
        ]);
        $this->assertSame('a.zip', $rows[0]['name']);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $rows[1]['error']);
        $this->assertSame([], CardImageImport::normalizeFilesArray([]));
    }
}
