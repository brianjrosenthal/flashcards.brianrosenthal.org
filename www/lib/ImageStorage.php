<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/S3Client.php';

/**
 * App-level policy for card images in object storage (one private Cloudflare
 * R2 bucket): where objects live, what may be uploaded, how an upload is
 * turned into the stored image, and how pages show it.
 *
 * Images pass THROUGH this server: the card form posts the file, PHP sniffs
 * the real type, fixes the EXIF orientation, downscales to MAX_LONG_EDGE,
 * re-encodes (which also strips metadata) and builds a THUMB_LONG_EDGE
 * thumbnail, then putPrepared() stores both objects. prepareUpload() /
 * prepareFromBytes() do all of that work without touching storage or the
 * database, so a bad file fails before any write happens.
 *
 * The bucket stays private and pages show images through presigned GET URLs.
 * The signature timestamp is quantized to a window so every viewer in that
 * window gets a byte-identical URL and the browser can cache the image; the
 * TTL is always at least twice the window so a URL minted at the start of a
 * window outlives its end. Object keys are random, so replacing an image
 * never reuses a URL a browser may still have cached.
 */
final class ImageStorage {

    /** Stored images are downscaled (never upscaled) to this long edge. */
    public const MAX_LONG_EDGE = 1600;

    /** Long edge of the list-page thumbnail stored next to every image. */
    public const THUMB_LONG_EDGE = 240;

    /** Uploads with more pixels than this are refused before decoding (memory). */
    public const MAX_PIXELS = 30000000;

    /** Default cap when IMAGE_MAX_BYTES is not configured: 10 MB. */
    private const DEFAULT_MAX_BYTES = 10485760;

    /** Display URL quantization window (6 h) and lifetime (24 h) defaults;
     *  override with IMAGE_URL_WINDOW_SECONDS / IMAGE_URL_TTL_SECONDS. */
    private const DEFAULT_URL_WINDOW = 21600;
    private const DEFAULT_URL_TTL = 86400;
    private const MAX_PRESIGN_TTL = 604800;

    /** Objects never change (keys are random), so browsers may cache them for a year. */
    private const CACHE_CONTROL = 'private, max-age=31536000, immutable';

    private const JPEG_QUALITY = 85;
    private const WEBP_QUALITY = 85;
    private const PNG_COMPRESSION = 6;

    /** Memory needed to decode a MAX_PIXELS image with GD (~5 bytes/pixel plus copies). */
    private const MEMORY_LIMIT = '256M';

    /**
     * Accepted image types (as sniffed by getimagesizefromstring) and what they
     * are stored as. GIFs become PNGs: animation is not kept and PNG holds the
     * transparency losslessly.
     * @var array<int,array{content_type:string,ext:string}>
     */
    private const TYPES = [
        IMAGETYPE_JPEG => ['content_type' => 'image/jpeg', 'ext' => 'jpg'],
        IMAGETYPE_PNG  => ['content_type' => 'image/png',  'ext' => 'png'],
        IMAGETYPE_WEBP => ['content_type' => 'image/webp', 'ext' => 'webp'],
        IMAGETYPE_GIF  => ['content_type' => 'image/png',  'ext' => 'png'],
    ];

    private const UNSUPPORTED_MESSAGE = 'Please choose a JPEG, PNG, WebP or GIF image.';

    private static ?S3Client $client = null;

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    private static function config(string $name, string $default = ''): string {
        return defined($name) ? trim((string)constant($name)) : $default;
    }

    /**
     * The S3 endpoint with no bucket in it. Cloudflare's bucket settings page
     * shows the "S3 API" value with the bucket appended
     * (https://{account}.r2.cloudflarestorage.com/flashcards-images); pasting
     * that as R2_ENDPOINT is fine — the bucket suffix is stripped here.
     */
    public static function endpoint(): string {
        $endpoint = rtrim(self::config('R2_ENDPOINT'), '/');
        $bucket = self::bucket();
        if ($bucket !== '' && str_ends_with($endpoint, '/' . $bucket)) {
            $endpoint = substr($endpoint, 0, -strlen('/' . $bucket));
        }
        return $endpoint;
    }

    public static function region(): string {
        return self::config('R2_REGION', 'auto') ?: 'auto';
    }

    public static function bucket(): string {
        return self::config('R2_IMAGE_BUCKET');
    }

    /**
     * Whether uploads can work: endpoint, keys and bucket are all set. The
     * card forms hide the file input when this is false, so an environment
     * without storage credentials still handles text cards.
     */
    public static function isConfigured(): bool {
        return self::config('R2_ACCESS_KEY') !== ''
            && self::config('R2_SECRET_KEY') !== ''
            && self::endpoint() !== ''
            && self::bucket() !== '';
    }

    /** Largest upload accepted, in bytes (IMAGE_MAX_BYTES, at least 256 KB). */
    public static function maxBytes(): int {
        $v = defined('IMAGE_MAX_BYTES') ? (int)IMAGE_MAX_BYTES : self::DEFAULT_MAX_BYTES;
        return max(256 * 1024, $v);
    }

    /**
     * The storage client, with an injection seam for tests: pass $inject to
     * replace it (tests/bootstrap.php injects a FakeS3Client).
     */
    public static function storage(?S3Client $inject = null): S3Client {
        if ($inject !== null) {
            self::$client = $inject;
        }
        if (self::$client === null) {
            self::$client = new S3Client(
                self::endpoint(),
                self::region(),
                self::config('R2_ACCESS_KEY'),
                self::config('R2_SECRET_KEY')
            );
        }
        return self::$client;
    }

    public static function resetStorage(): void {
        self::$client = null;
    }

    // -------------------------------------------------------------------------
    // Preparing an upload (pure: no storage, no database)
    // -------------------------------------------------------------------------

    /**
     * Turn one $_FILES entry into the stored form of the image (see
     * prepareFromBytes). PHP's upload error codes become messages fit for the
     * form. Outside a web SAPI (tests, CLI tools) any readable path is
     * accepted in place of a real upload.
     *
     * @param array{error?:int,tmp_name?:string,size?:int} $file
     * @return array{body:string,thumb_body:string,content_type:string,ext:string,width:int,height:int,size:int}
     * @throws InvalidArgumentException with a form-ready message
     */
    public static function prepareUpload(array $file): array {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        switch ($error) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new InvalidArgumentException('That image is larger than ' . self::humanBytes(self::maxBytes()) . '.');
            case UPLOAD_ERR_NO_FILE:
                throw new InvalidArgumentException('No image was chosen.');
            case UPLOAD_ERR_PARTIAL:
                throw new InvalidArgumentException('The image only partly uploaded. Please try again.');
            default:
                throw new InvalidArgumentException('The image could not be uploaded (error ' . $error . '). Please try again.');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) || !is_readable($tmp)) {
            throw new InvalidArgumentException('The image could not be uploaded. Please try again.');
        }
        $size = (int)(@filesize($tmp) ?: 0);
        if ($size <= 0) {
            throw new InvalidArgumentException('The chosen image is empty.');
        }
        if ($size > self::maxBytes()) {
            throw new InvalidArgumentException('That image is larger than ' . self::humanBytes(self::maxBytes()) . '.');
        }
        $bytes = @file_get_contents($tmp);
        if ($bytes === false) {
            throw new InvalidArgumentException('The image could not be read. Please try again.');
        }
        return self::prepareFromBytes($bytes);
    }

    /**
     * The stored form of an image, from its raw bytes. Pure computation: sniffs
     * the real type (never the client's MIME claim), refuses anything but
     * JPEG/PNG/WebP/GIF or more than MAX_PIXELS, applies a JPEG's EXIF
     * orientation, downscales (never upscales) to MAX_LONG_EDGE, re-encodes
     * (JPEG q85, PNG with alpha, WebP q85; GIF becomes PNG) and builds the
     * THUMB_LONG_EDGE thumbnail in the same format.
     *
     * @return array{body:string,thumb_body:string,content_type:string,ext:string,width:int,height:int,size:int}
     * @throws InvalidArgumentException with a form-ready message
     */
    public static function prepareFromBytes(string $bytes): array {
        if ($bytes === '') {
            throw new InvalidArgumentException(self::UNSUPPORTED_MESSAGE);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset($info[2]) || !isset(self::TYPES[(int)$info[2]])) {
            throw new InvalidArgumentException(self::UNSUPPORTED_MESSAGE);
        }
        $type = (int)$info[2];
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException(self::UNSUPPORTED_MESSAGE);
        }
        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException(
                'That image is ' . $width . 'x' . $height . ' pixels, which is too large. Please use one under '
                . (int)round(self::MAX_PIXELS / 1000000) . ' megapixels.'
            );
        }

        self::raiseMemoryLimit();
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new InvalidArgumentException('That image could not be read. ' . self::UNSUPPORTED_MESSAGE);
        }
        if ($type === IMAGETYPE_JPEG) {
            $src = self::applyExifOrientation($src, self::exifOrientation($bytes));
        }
        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);
        $keepAlpha = $type !== IMAGETYPE_JPEG;

        // Always resample onto a fresh true-colour canvas: it downscales when
        // needed, converts palette images, and drops any metadata on re-encode.
        [$mainWidth, $mainHeight] = self::fitWithin($srcWidth, $srcHeight, self::MAX_LONG_EDGE);
        $main = self::resample($src, $srcWidth, $srcHeight, $mainWidth, $mainHeight, $keepAlpha);

        [$thumbWidth, $thumbHeight] = self::fitWithin($mainWidth, $mainHeight, self::THUMB_LONG_EDGE);
        $thumb = self::resample($main, $mainWidth, $mainHeight, $thumbWidth, $thumbHeight, $keepAlpha);

        $body = self::encode($main, $type);
        $thumbBody = self::encode($thumb, $type);

        return [
            'body'         => $body,
            'thumb_body'   => $thumbBody,
            'content_type' => self::TYPES[$type]['content_type'],
            'ext'          => self::TYPES[$type]['ext'],
            'width'        => $mainWidth,
            'height'       => $mainHeight,
            'size'         => strlen($body),
        ];
    }

    /** Dimensions after scaling DOWN (never up) so the long edge is at most $longEdge. */
    private static function fitWithin(int $width, int $height, int $longEdge): array {
        $long = max($width, $height);
        if ($long <= $longEdge) {
            return [$width, $height];
        }
        $scale = $longEdge / $long;
        return [max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale))];
    }

    private static function resample(\GdImage $src, int $srcWidth, int $srcHeight, int $dstWidth, int $dstHeight, bool $keepAlpha): \GdImage {
        $dst = imagecreatetruecolor($dstWidth, $dstHeight);
        if ($dst === false) {
            throw new RuntimeException('Could not allocate memory to resize the image.');
        }
        if ($keepAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
        } else {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);
        return $dst;
    }

    private static function encode(\GdImage $img, int $type): string {
        ob_start();
        try {
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $ok = imagejpeg($img, null, self::JPEG_QUALITY);
                    break;
                case IMAGETYPE_WEBP:
                    $ok = imagewebp($img, null, self::WEBP_QUALITY);
                    break;
                default: // PNG, and GIF which is stored as PNG
                    $ok = imagepng($img, null, self::PNG_COMPRESSION);
                    break;
            }
        } finally {
            $out = (string)ob_get_clean();
        }
        if (!$ok || $out === '') {
            throw new RuntimeException('The image could not be re-encoded.');
        }
        return $out;
    }

    /** The EXIF Orientation tag of a JPEG (1 when absent or unreadable). */
    private static function exifOrientation(string $jpegBytes): int {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($jpegBytes), 'IFD0', true);
        $orientation = (int)($exif['IFD0']['Orientation'] ?? 1);
        return ($orientation >= 1 && $orientation <= 8) ? $orientation : 1;
    }

    /** Rotate/flip a decoded JPEG so it displays upright without its EXIF tag. */
    private static function applyExifOrientation(\GdImage $img, int $orientation): \GdImage {
        switch ($orientation) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 3:
                return self::rotated($img, 180);
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                return $img;
            case 5:
                $img = self::rotated($img, -90);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 6:
                return self::rotated($img, -90);
            case 7:
                $img = self::rotated($img, 90);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 8:
                return self::rotated($img, 90);
            default:
                return $img;
        }
    }

    private static function rotated(\GdImage $img, int $degreesCounterClockwise): \GdImage {
        $out = imagerotate($img, $degreesCounterClockwise, 0);
        if ($out === false) {
            return $img;
        }
        return $out;
    }

    /** Make sure a MAX_PIXELS image can be decoded and resampled. */
    private static function raiseMemoryLimit(): void {
        $current = (string)ini_get('memory_limit');
        if ($current === '-1') {
            return;
        }
        if (self::bytesFromIni($current) < self::bytesFromIni(self::MEMORY_LIMIT)) {
            @ini_set('memory_limit', self::MEMORY_LIMIT);
        }
    }

    private static function bytesFromIni(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int)$value;
        switch ($unit) {
            case 'g': return $number * 1024 * 1024 * 1024;
            case 'm': return $number * 1024 * 1024;
            case 'k': return $number * 1024;
            default:  return $number;
        }
    }

    // -------------------------------------------------------------------------
    // Object keys
    // -------------------------------------------------------------------------

    /**
     * The object key a new image for this card will use. Random so the URL
     * cannot be guessed and so replacing an image never overwrites in place
     * (browsers may still be caching the old URL).
     */
    public static function newObjectKeyFor(int $userId, int $cardId, string $ext): string {
        if (!in_array($ext, ['jpg', 'png', 'webp'], true)) {
            throw new InvalidArgumentException('Unsupported image extension "' . $ext . '".');
        }
        return 'cards/' . $userId . '/' . $cardId . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /** The thumbnail object stored next to an image: …/abc.jpg -> …/abc_thumb.jpg */
    public static function thumbKeyFor(string $key): string {
        $dot = strrpos($key, '.');
        $slash = strrpos($key, '/');
        if ($dot === false || ($slash !== false && $dot < $slash)) {
            return $key . '_thumb';
        }
        return substr($key, 0, $dot) . '_thumb' . substr($key, $dot);
    }

    /** Whether a key has the shape newObjectKeyFor() produces for this card. */
    public static function keyBelongsToCard(string $key, int $cardId): bool {
        return preg_match('#^cards/\d+/' . $cardId . '/[0-9a-f]{32}\.(jpg|png|webp)$#', $key) === 1;
    }

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------

    /**
     * Store a prepared image under $key and its thumbnail under
     * thumbKeyFor($key). If the thumbnail fails to store the main object is
     * removed again (best effort) so nothing is left half-stored.
     *
     * @param array{body:string,thumb_body:string,content_type:string} $prepared
     * @throws RuntimeException when storage refuses or cannot be reached
     */
    public static function putPrepared(string $key, array $prepared): void {
        if (self::$client === null && !self::isConfigured()) {
            throw new RuntimeException('Image uploads are disabled until an admin configures Image Storage (see Admin -> Image Storage).');
        }
        $bucket = self::bucket();
        $client = self::storage();
        $client->putObject($bucket, $key, (string)$prepared['body'], (string)$prepared['content_type'], self::CACHE_CONTROL);
        try {
            $client->putObject($bucket, self::thumbKeyFor($key), (string)$prepared['thumb_body'], (string)$prepared['content_type'], self::CACHE_CONTROL);
        } catch (\Throwable $e) {
            try {
                $client->deleteObjects($bucket, [$key]);
            } catch (\Throwable $cleanup) {
                // The caller gets the original failure; the orphan shows up in Admin -> Image Storage.
            }
            throw $e;
        }
    }

    /** Delete an image and its thumbnail (one request; a missing thumbnail is not an error). */
    public static function deleteObject(string $key): void {
        if ($key === '') {
            return;
        }
        self::storage()->deleteObjects(self::bucket(), [$key, self::thumbKeyFor($key)]);
    }

    /**
     * Delete many images and their thumbnails in one bulk request (a whole
     * deck's worth). Tolerates an empty list.
     * @param string[] $keys
     */
    public static function deleteObjects(array $keys): void {
        $all = [];
        foreach ($keys as $key) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            $all[] = $key;
            $all[] = self::thumbKeyFor($key);
        }
        if ($all === []) {
            return;
        }
        self::storage()->deleteObjects(self::bucket(), $all);
    }

    // -------------------------------------------------------------------------
    // Display URLs
    // -------------------------------------------------------------------------

    public static function urlWindowSeconds(): int {
        $w = defined('IMAGE_URL_WINDOW_SECONDS') ? (int)IMAGE_URL_WINDOW_SECONDS : self::DEFAULT_URL_WINDOW;
        return min(max(60, $w), intdiv(self::MAX_PRESIGN_TTL, 2));
    }

    public static function urlTtlSeconds(): int {
        $ttl = defined('IMAGE_URL_TTL_SECONDS') ? (int)IMAGE_URL_TTL_SECONDS : self::DEFAULT_URL_TTL;
        return min(max($ttl, self::urlWindowSeconds() * 2), self::MAX_PRESIGN_TTL);
    }

    /** The signature timestamp for display URLs, rounded down to the window. */
    public static function urlIssuedAt(?int $now = null): int {
        $window = self::urlWindowSeconds();
        return intdiv($now ?? time(), $window) * $window;
    }

    /**
     * The URL an <img> tag shows the object from: a presigned GET, identical
     * for every viewer within the current window (cacheable), valid for the
     * TTL. Pure local computation — no storage round trip per page view.
     */
    public static function displayUrlFor(string $key, ?int $now = null): string {
        return self::storage()->presignedGetUrl(self::bucket(), $key, self::urlIssuedAt($now), self::urlTtlSeconds());
    }

    public static function thumbUrlFor(string $key, ?int $now = null): string {
        return self::displayUrlFor(self::thumbKeyFor($key), $now);
    }

    /** displayUrlFor() for a cards row, or null when the card has no image. */
    public static function displayUrlForCard(array $card, ?int $now = null): ?string {
        $key = (string)($card['image_object_key'] ?? '');
        return $key === '' ? null : self::displayUrlFor($key, $now);
    }

    /** thumbUrlFor() for a cards row, or null when the card has no image. */
    public static function thumbUrlForCard(array $card, ?int $now = null): ?string {
        $key = (string)($card['image_object_key'] ?? '');
        return $key === '' ? null : self::thumbUrlFor($key, $now);
    }

    // -------------------------------------------------------------------------
    // Diagnostics
    // -------------------------------------------------------------------------

    /**
     * Diagnostic for Admin -> Image Storage: store a tiny generated PNG exactly
     * as a card upload would (main object + thumbnail), HEAD it, fetch it
     * through a display URL as a browser would, then delete it. Returns a
     * one-paragraph human-readable result including the raw storage error
     * when a step fails. Never throws.
     */
    public static function describeTestUpload(): string {
        try {
            $img = imagecreatetruecolor(1, 1);
            ob_start();
            imagepng($img);
            $png = (string)ob_get_clean();
            $prepared = self::prepareFromBytes($png);
        } catch (\Throwable $e) {
            return 'Could not generate the test image on this server: ' . $e->getMessage();
        }

        $key = 'cards/0/0/' . bin2hex(random_bytes(16)) . '.png';
        try {
            self::putPrepared($key, $prepared);
        } catch (\Throwable $e) {
            return 'Upload failed: ' . $e->getMessage()
                 . ' Check R2_ENDPOINT, R2_ACCESS_KEY, R2_SECRET_KEY and R2_IMAGE_BUCKET in config.local.php, and that the bucket exists.';
        }

        try {
            $head = self::storage()->headObject(self::bucket(), $key);
        } catch (\Throwable $e) {
            self::deleteTestObjectQuietly($key);
            return 'Upload succeeded but verifying the object failed: ' . $e->getMessage();
        }
        if ($head === null) {
            return 'Upload returned success but the object "' . $key . '" cannot be found afterwards.';
        }

        // Display: an unauthenticated GET of the presigned URL, as a browser does.
        $fetched = false;
        $fetchStatus = 0;
        $fetchError = '';
        try {
            $ch = curl_init(self::displayUrlFor($key));
            if ($ch !== false) {
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
                $fetched = curl_exec($ch);
                $fetchStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $fetchError = curl_error($ch);
                unset($ch);
            }
        } catch (\Throwable $e) {
            $fetchError = $e->getMessage();
        }

        try {
            self::deleteObject($key);
        } catch (\Throwable $e) {
            return 'Upload and display worked but deleting the test object failed: ' . $e->getMessage();
        }

        if ($fetched === false) {
            return 'Upload worked (' . (int)$head['size'] . ' bytes, type "' . (string)$head['content_type']
                 . '") but fetching it through a display URL failed before a response'
                 . ($fetchError !== '' ? ': ' . $fetchError : '.') . ' Images will not show on pages until this works.';
        }
        if ($fetchStatus !== 200 || $fetched !== $prepared['body']) {
            return 'Upload worked (' . (int)$head['size'] . ' bytes) but fetching it through a display URL returned HTTP ' . $fetchStatus
                 . ($fetchStatus === 200 ? ' with different bytes' : '')
                 . ($fetched === '' ? '' : ': ' . substr(trim(strip_tags((string)$fetched)), 0, 200)) . '. Images will not show on pages until this works.';
        }
        return 'Test upload succeeded: stored a ' . (int)$head['size'] . ' byte "' . (string)$head['content_type']
             . '" image and its thumbnail in bucket "' . self::bucket() . '", fetched it through a display URL (HTTP 200, bytes match), then deleted both objects.'
             . ' Card image uploads should work.';
    }

    private static function deleteTestObjectQuietly(string $key): void {
        try {
            self::deleteObject($key);
        } catch (\Throwable $e) {
            // Diagnostics only; the orphan shows up in Admin -> Image Storage.
        }
    }

    public static function humanBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float)$bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)(int)$value : rtrim(rtrim(number_format($value, 1), '0'), '.')) . ' ' . $units[$i];
    }
}
