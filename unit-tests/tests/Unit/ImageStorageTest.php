<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ImageStorageTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
    }

    private function storage(): FakeS3Client
    {
        return ImageStorage::storage();
    }

    /** A GD-generated PNG: transparent background, opaque red square in the top-left quarter. */
    private function pngBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 0, 0, intdiv($width, 2) - 1, intdiv($height, 2) - 1, imagecolorallocatealpha($img, 255, 0, 0, 0));
        ob_start();
        imagepng($img);
        return (string)ob_get_clean();
    }

    private function jpegBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 60, 90));
        ob_start();
        imagejpeg($img, null, 90);
        return (string)ob_get_clean();
    }

    public function testConfigurationIsReadFromConstants(): void
    {
        $this->assertSame('flashcards-images', ImageStorage::bucket());
        $this->assertSame('auto', ImageStorage::region());
        $this->assertStringStartsNotWith('/', ImageStorage::endpoint());
        $this->assertStringEndsNotWith('/' . ImageStorage::bucket(), ImageStorage::endpoint(), 'a bucket suffix pasted into R2_ENDPOINT is stripped');
        $this->assertTrue(ImageStorage::isConfigured(), 'the test config has no R2 keys, but an injected fake client counts as configured');
        $this->assertGreaterThanOrEqual(256 * 1024, ImageStorage::maxBytes());
        $this->assertSame('https://r2-test.example', $this->storage()->endpoint(), 'tests/bootstrap.php injected the fake');
    }

    public function testWidePngIsDownscaledWithAlphaKeptAndThumbnailBuilt(): void
    {
        $prepared = ImageStorage::prepareFromBytes($this->pngBytes(2000, 500));
        $this->assertSame('image/png', $prepared['content_type']);
        $this->assertSame('png', $prepared['ext']);
        $this->assertSame(1600, $prepared['width']);
        $this->assertSame(400, $prepared['height']);
        $this->assertSame(strlen($prepared['body']), $prepared['size']);

        $main = imagecreatefromstring($prepared['body']);
        $this->assertSame([1600, 400], [imagesx($main), imagesy($main)]);
        $red = imagecolorsforindex($main, imagecolorat($main, 100, 100));
        $this->assertSame(0, $red['alpha'], 'the painted square stays opaque');
        $this->assertGreaterThan(200, $red['red']);
        $clear = imagecolorsforindex($main, imagecolorat($main, 1500, 300));
        $this->assertSame(127, $clear['alpha'], 'PNG transparency survives the resize');

        $thumb = imagecreatefromstring($prepared['thumb_body']);
        $this->assertSame([240, 60], [imagesx($thumb), imagesy($thumb)], 'thumbnail long edge is 240');
        $this->assertSame(127, imagecolorsforindex($thumb, imagecolorat($thumb, 230, 50))['alpha']);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($prepared['thumb_body'])[2], 'thumbnail keeps the format');
    }

    public function testTallImageUsesTheLongEdge(): void
    {
        $prepared = ImageStorage::prepareFromBytes($this->pngBytes(300, 3200));
        $this->assertSame([150, 1600], [$prepared['width'], $prepared['height']]);
        $thumb = imagecreatefromstring($prepared['thumb_body']);
        $this->assertSame([23, 240], [imagesx($thumb), imagesy($thumb)]);
    }

    public function testSmallJpegIsNotUpscaledAndIsReencodedAsJpeg(): void
    {
        $prepared = ImageStorage::prepareFromBytes($this->jpegBytes(100, 100));
        $this->assertSame([100, 100], [$prepared['width'], $prepared['height']]);
        $this->assertSame('image/jpeg', $prepared['content_type']);
        $this->assertSame('jpg', $prepared['ext']);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($prepared['body'])[2]);
        $thumb = getimagesizefromstring($prepared['thumb_body']);
        $this->assertSame([100, 100, IMAGETYPE_JPEG], [$thumb[0], $thumb[1], $thumb[2]], 'a thumbnail is never larger than the image');
    }

    public function testGifBecomesPng(): void
    {
        $img = imagecreate(50, 40);
        imagecolorallocate($img, 255, 255, 255);
        ob_start();
        imagegif($img);
        $gif = (string)ob_get_clean();
        $prepared = ImageStorage::prepareFromBytes($gif);
        $this->assertSame(['image/png', 'png', 50, 40], [$prepared['content_type'], $prepared['ext'], $prepared['width'], $prepared['height']]);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($prepared['body'])[2]);
    }

    public function testGarbageAndUnsupportedTypesAreRefused(): void
    {
        foreach (['', 'not an image at all', "\x89PNG\r\n\x1a\ntruncated", '<svg xmlns="http://www.w3.org/2000/svg"/>', '%PDF-1.4'] as $bytes) {
            try {
                ImageStorage::prepareFromBytes($bytes);
                $this->fail('must refuse: ' . substr($bytes, 0, 12));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('JPEG, PNG, WebP or GIF', $e->getMessage());
            }
        }
        $this->assertSame([], $this->storage()->calls, 'preparing never touches storage');
    }

    public function testPrepareUploadMapsPhpUploadErrors(): void
    {
        try {
            ImageStorage::prepareUpload(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']);
            $this->fail('no file');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('No image was chosen.', $e->getMessage());
        }
        try {
            ImageStorage::prepareUpload(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '']);
            $this->fail('too big');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('upload_max_filesize = ' . ini_get('upload_max_filesize'), $e->getMessage(), 'PHP refused it: name PHP\'s limit, not ours');
        }
        try {
            ImageStorage::prepareUpload(['error' => UPLOAD_ERR_FORM_SIZE, 'tmp_name' => '']);
            $this->fail('too big');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('larger than ' . ImageStorage::humanBytes(ImageStorage::maxBytes()), $e->getMessage());
        }
        // Outside a web SAPI any readable file stands in for an upload.
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, $this->jpegBytes(20, 10));
        try {
            $prepared = ImageStorage::prepareUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
            $this->assertSame([20, 10, 'jpg'], [$prepared['width'], $prepared['height'], $prepared['ext']]);
        } finally {
            @unlink($tmp);
        }
    }

    public function testObjectKeysAreScopedAndUnguessable(): void
    {
        $a = ImageStorage::newObjectKeyFor(3, 17, 'jpg');
        $b = ImageStorage::newObjectKeyFor(3, 17, 'jpg');
        $this->assertMatchesRegularExpression('#^cards/3/17/[0-9a-f]{32}\.jpg$#', $a);
        $this->assertNotSame($a, $b, 'each upload gets a fresh key');
        $this->assertTrue(ImageStorage::keyBelongsToCard($a, 17));
        $this->assertFalse(ImageStorage::keyBelongsToCard($a, 18));
        $this->assertFalse(ImageStorage::keyBelongsToCard('cards/3/17/../x.jpg', 17));
        $this->assertSame('cards/3/17/abc_thumb.jpg', ImageStorage::thumbKeyFor('cards/3/17/abc.jpg'));
        $this->assertSame('cards/3/17/abc_thumb.webp', ImageStorage::thumbKeyFor('cards/3/17/abc.webp'));
        $this->assertSame('cards/3.x/17/abc_thumb', ImageStorage::thumbKeyFor('cards/3.x/17/abc'), 'a dot in a folder is not an extension');
        $this->expectException(InvalidArgumentException::class);
        ImageStorage::newObjectKeyFor(3, 17, 'gif');
    }

    public function testDisplayUrlIsPresignedAndStableWithinAWindow(): void
    {
        $key = 'cards/3/17/abc.jpg';
        $window = ImageStorage::urlWindowSeconds();
        $t0 = 1774526400 - (1774526400 % $window);
        $a = ImageStorage::displayUrlFor($key, $t0 + 10);
        $b = ImageStorage::displayUrlFor($key, $t0 + $window - 1);
        $c = ImageStorage::displayUrlFor($key, $t0 + $window);

        $this->assertStringStartsWith('https://r2-test.example/' . ImageStorage::bucket() . '/' . $key . '?', $a);
        $this->assertStringContainsString('X-Amz-Signature=', $a);
        $this->assertSame($a, $b, 'same window => byte-identical URL so the browser can cache the image');
        $this->assertNotSame($a, $c, 'a new window re-signs');
        $this->assertStringContainsString('X-Amz-Expires=' . ImageStorage::urlTtlSeconds(), $a);
        $this->assertGreaterThanOrEqual(2 * $window, ImageStorage::urlTtlSeconds(), 'a URL minted at the start of a window must outlive its end');
        $this->assertLessThanOrEqual(604800, ImageStorage::urlTtlSeconds());
        $this->assertSame($t0, ImageStorage::urlIssuedAt($t0 + $window - 1));

        $this->assertStringStartsWith('https://r2-test.example/' . ImageStorage::bucket() . '/cards/3/17/abc_thumb.jpg?', ImageStorage::thumbUrlFor($key, $t0));
        $this->assertSame($a, ImageStorage::displayUrlForCard(['image_object_key' => $key], $t0 + 10));
        $this->assertSame(ImageStorage::thumbUrlFor($key, $t0), ImageStorage::thumbUrlForCard(['image_object_key' => $key], $t0));
        $this->assertNull(ImageStorage::displayUrlForCard(['image_object_key' => null]));
        $this->assertNull(ImageStorage::thumbUrlForCard([]));
        $this->assertSame([], $this->storage()->calls, 'display URLs are pure computation');
    }

    public function testPutPreparedStoresMainAndThumbWithImmutableCaching(): void
    {
        $prepared = ImageStorage::prepareFromBytes($this->pngBytes(400, 200));
        $key = ImageStorage::newObjectKeyFor(1, 2, $prepared['ext']);
        ImageStorage::putPrepared($key, $prepared);
        $bucket = ImageStorage::bucket();
        $this->assertSame(['size' => $prepared['size'], 'content_type' => 'image/png'], $this->storage()->headObject($bucket, $key));
        $this->assertSame(strlen($prepared['thumb_body']), $this->storage()->headObject($bucket, ImageStorage::thumbKeyFor($key))['size']);
        $this->assertSame(2, $this->storage()->calls['put']);
    }

    public function testDeleteObjectRemovesMainAndThumb(): void
    {
        $bucket = ImageStorage::bucket();
        $prepared = ImageStorage::prepareFromBytes($this->pngBytes(40, 40));
        $k1 = ImageStorage::newObjectKeyFor(1, 2, 'png');
        $k2 = ImageStorage::newObjectKeyFor(1, 3, 'png');
        ImageStorage::putPrepared($k1, $prepared);
        ImageStorage::putPrepared($k2, $prepared);
        $this->assertCount(4, $this->storage()->listObjects($bucket));

        ImageStorage::deleteObject($k1);
        $this->assertFalse($this->storage()->objectExists($bucket, $k1));
        $this->assertFalse($this->storage()->objectExists($bucket, ImageStorage::thumbKeyFor($k1)));
        $this->assertTrue($this->storage()->objectExists($bucket, $k2));
        $this->assertSame(1, $this->storage()->calls['delete'], 'main and thumb go in one request');

        ImageStorage::deleteObjects([]);
        ImageStorage::deleteObjects(['']);
        $this->assertSame(1, $this->storage()->calls['delete'], 'nothing to delete means no request');
        ImageStorage::deleteObjects([$k2]);
        $this->assertSame([], $this->storage()->listObjects($bucket));
        ImageStorage::deleteObject('');
        $this->assertSame(2, $this->storage()->calls['delete']);
    }

    public function testHumanBytes(): void
    {
        $this->assertSame('512 B', ImageStorage::humanBytes(512));
        $this->assertSame('1.5 KB', ImageStorage::humanBytes(1536));
        $this->assertSame('10 MB', ImageStorage::humanBytes(10 * 1024 * 1024));
    }
}
