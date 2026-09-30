<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/SubcategoryManagement.php';
require_once __DIR__ . '/CardManagement.php';
require_once __DIR__ . '/ImageStorage.php';

/**
 * Import a batch of pictures as cards: each image becomes one card whose
 * FRONT is the image and whose BACK is the file name (extension dropped,
 * underscores turned into spaces). The pictures arrive as a ZIP file and/or
 * individual image files.
 *
 * The flow is three steps, because resizing and uploading a hundred photos
 * to R2 takes far longer than one web request may run:
 *
 *   1. stashUpload()  — the upload request only unpacks the files into a
 *                       private temp folder and writes a manifest (what will
 *                       be created, what will be skipped and why).
 *   2. the review page shows that manifest; setOptions() records the choices.
 *   3. commitBatch()  — called repeatedly by the progress page, imports a few
 *                       entries per call until the manifest is done, then
 *                       removes the temp folder.
 *
 * Manifests are private to the user who uploaded them (an admin may also
 * act on them) and stale ones are purged after a day.
 */
final class CardImageImport {

    public const MAX_ENTRIES = 500;
    public const BATCH_SIZE = 4;
    public const STALE_SECONDS = 86400;
    /** Refuse to unpack more than this from one upload (zip-bomb guard). */
    public const MAX_TOTAL_BYTES = 2147483648;

    public const STATUS_OK = 'ok';              // will be imported
    public const STATUS_EXISTS = 'exists';      // a card with this back already exists in the deck; imported only on request
    public const STATUS_DUPLICATE = 'duplicate';// another file in this upload has the same back; skipped
    public const STATUS_SKIPPED = 'skipped';    // not an image, too large, empty name ...
    public const STATUS_DONE = 'done';          // imported
    public const STATUS_FAILED = 'failed';      // import attempted and failed (see 'reason')

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private static ?string $dirOverride = null;

    // ---- storage of pending imports ----------------------------------------

    /** The private folder that holds pending imports (one sub-folder per token). */
    public static function dir(): string {
        $dir = self::$dirOverride ?? (sys_get_temp_dir() . '/flashcards-imports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /** Tests point the import folder somewhere disposable. */
    public static function setDirForTesting(?string $dir): void {
        self::$dirOverride = $dir;
    }

    private static function log(string $action, array $meta): void {
        try {
            ActivityLog::log(UserContext::getLoggedInUserContext(), $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertToken(string $token): void {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new InvalidArgumentException('That import no longer exists.');
        }
    }

    private static function tokenDir(string $token): string {
        return self::dir() . '/' . $token;
    }

    private static function manifestPath(string $token): string {
        return self::tokenDir($token) . '/manifest.json';
    }

    private static function writeManifest(array $manifest): void {
        $path = self::manifestPath((string)$manifest['token']);
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not save the import.');
        }
    }

    /**
     * The manifest for $token, after checking that $ctx may act on it (the
     * uploader, or an admin). Throws when the token is unknown or expired.
     */
    public static function load(string $token, ?UserContext $ctx): array {
        self::assertToken($token);
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $path = self::manifestPath($token);
        if (!is_file($path)) {
            throw new InvalidArgumentException('That import no longer exists (imports expire after a day).');
        }
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest)) {
            throw new RuntimeException('That import could not be read.');
        }
        if (!$ctx->admin && $ctx->id !== (int)$manifest['user_id']) {
            throw new RuntimeException('That import belongs to someone else.');
        }
        return $manifest;
    }

    /** Remove imports older than STALE_SECONDS. Called on every new upload. */
    public static function purgeStale(?int $now = null): int {
        $now = $now ?? time();
        $removed = 0;
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $manifest = $dir . '/manifest.json';
            $age = $now - (is_file($manifest) ? (int)filemtime($manifest) : (int)filemtime($dir));
            if ($age > self::STALE_SECONDS) {
                self::removeDir($dir);
                $removed++;
            }
        }
        return $removed;
    }

    private static function removeDir(string $dir): void {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_dir($f)) {
                self::removeDir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    // ---- names --------------------------------------------------------------

    /**
     * "faces/Ada_Lovelace.JPG" -> "Ada Lovelace". The folder and the extension
     * go, underscores (and %20 from web downloads) become spaces, runs of
     * whitespace collapse.
     */
    public static function backTextFromFilename(string $name): string {
        $base = basename(str_replace('\\', '/', $name));
        $dot = strrpos($base, '.');
        if ($dot !== false && $dot > 0) {
            $base = substr($base, 0, $dot);
        }
        $base = str_replace(['%20', '_'], ' ', $base);
        $base = preg_replace('/\s+/u', ' ', $base) ?? $base;
        $base = trim($base);
        if (mb_strlen($base) > CardManagement::MAX_TEXT) {
            $base = mb_substr($base, 0, CardManagement::MAX_TEXT);
        }
        return $base;
    }

    /** Whether a file name looks like one of the image types we accept. */
    public static function hasImageExtension(string $name): bool {
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /** True when the bytes start like a ZIP archive. */
    public static function looksLikeZip(string $path): bool {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        $magic = fread($h, 4);
        fclose($h);
        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06";
    }

    /** Whether an uploaded entry (zip member or file) should be ignored outright: folders, macOS resource forks, hidden files. */
    public static function isJunkEntry(string $name): bool {
        $n = str_replace('\\', '/', $name);
        if ($n === '' || substr($n, -1) === '/') {
            return true;
        }
        if (strpos($n, '__MACOSX/') === 0 || strpos($n, '/__MACOSX/') !== false) {
            return true;
        }
        $base = basename($n);
        return $base === '' || $base[0] === '.' || $base === 'Thumbs.db' || $base === 'desktop.ini';
    }

    // ---- step 1: unpack the upload and describe it ----------------------------

    /**
     * Normalise a multi-file $_FILES entry (files[]) into a list of
     * ['name','tmp_name','error','size'] rows.
     */
    public static function normalizeFilesArray(array $files): array {
        if (!isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return [$files];
        }
        $rows = [];
        foreach ($files['name'] as $i => $name) {
            $rows[] = [
                'name' => (string)$name,
                'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
                'error' => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($files['size'][$i] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * Unpack the uploaded ZIPs and images into a private folder and write the
     * manifest. $uploads is normalizeFilesArray() output; each row's tmp_name
     * must be a readable file (a real upload, or any path from tests).
     * Returns the import token. Throws InvalidArgumentException with a
     * form-ready message when nothing usable was uploaded.
     */
    public static function stashUpload(?UserContext $ctx, int $subcategoryId, array $uploads): string {
        $ownerId = SubcategoryManagement::ownerUserIdOf($subcategoryId);
        if ($ownerId === null) {
            throw new RuntimeException('Deck not found.');
        }
        ContentAccess::assertCanEdit($ctx, $ownerId);
        if (!ImageStorage::isConfigured()) {
            throw new RuntimeException('Image uploads are disabled until an admin configures Image Storage.');
        }
        self::purgeStale();

        $token = bin2hex(random_bytes(16));
        $dir = self::tokenDir($token);
        if (!@mkdir($dir, 0700, true)) {
            throw new RuntimeException('Could not create a folder for the import.');
        }

        $entries = [];
        $totalBytes = 0;
        $uploadErrors = [];
        try {
            foreach ($uploads as $u) {
                $err = (int)($u['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    $uploadErrors[] = self::uploadErrorMessage((string)$u['name'], $err);
                    continue;
                }
                $tmp = (string)$u['tmp_name'];
                if ($tmp === '' || !is_readable($tmp)) {
                    $uploadErrors[] = '"' . $u['name'] . '" could not be read.';
                    continue;
                }
                if (self::looksLikeZip($tmp)) {
                    self::unpackZip($tmp, (string)$u['name'], $dir, $entries, $totalBytes);
                } else {
                    self::addFileEntry($tmp, (string)$u['name'], $dir, $entries, $totalBytes);
                }
                if (count($entries) > self::MAX_ENTRIES) {
                    throw new InvalidArgumentException('That is more than ' . self::MAX_ENTRIES . ' files. Please import in smaller batches.');
                }
            }
        } catch (\Throwable $e) {
            self::removeDir($dir);
            throw $e;
        }

        if ($entries === []) {
            self::removeDir($dir);
            throw new InvalidArgumentException(
                $uploadErrors !== [] ? implode(' ', $uploadErrors) : 'No images were found in the upload. Choose a ZIP of pictures or some image files.'
            );
        }

        self::markDuplicatesAndExisting($entries, $subcategoryId);

        $manifest = [
            'token' => $token,
            'user_id' => $ctx->id,
            'owner_user_id' => $ownerId,
            'subcategory_id' => $subcategoryId,
            'created_at' => date('c'),
            'options' => ['import_existing' => false],
            'upload_errors' => $uploadErrors,
            'entries' => $entries,
            'next' => 0,
            'created' => 0,
            'failed' => 0,
            'done' => false,
        ];
        self::writeManifest($manifest);
        self::log('card_import.stash', ['token' => $token, 'subcategory_id' => $subcategoryId, 'entries' => count($entries)]);
        return $token;
    }

    private static function uploadErrorMessage(string $name, int $err): string {
        switch ($err) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '"' . $name . '" is larger than the server accepts (' . ini_get('upload_max_filesize') . ' per file).';
            case UPLOAD_ERR_PARTIAL:
                return '"' . $name . '" was only partly uploaded; please try again.';
            default:
                return '"' . $name . '" could not be uploaded (error ' . $err . ').';
        }
    }

    private static function unpackZip(string $zipPath, string $zipName, string $dir, array &$entries, int &$totalBytes): void {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new InvalidArgumentException('"' . $zipName . '" is not a ZIP file that could be opened.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = (string)$stat['name'];
                if (self::isJunkEntry($name)) {
                    continue;
                }
                $size = (int)$stat['size'];
                $entry = self::newEntry($name, $size);
                if (!self::hasImageExtension($name)) {
                    $entry['status'] = self::STATUS_SKIPPED;
                    $entry['reason'] = 'Not an image file.';
                    $entries[] = $entry;
                    continue;
                }
                if ($size > ImageStorage::maxBytes()) {
                    $entry['status'] = self::STATUS_SKIPPED;
                    $entry['reason'] = 'Larger than ' . ImageStorage::humanBytes(ImageStorage::maxBytes()) . '.';
                    $entries[] = $entry;
                    continue;
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    throw new InvalidArgumentException('That upload unpacks to more than ' . ImageStorage::humanBytes(self::MAX_TOTAL_BYTES) . '. Please import in smaller batches.');
                }
                $bytes = $zip->getFromIndex($i);
                if ($bytes === false) {
                    $entry['status'] = self::STATUS_SKIPPED;
                    $entry['reason'] = 'Could not be read from the ZIP.';
                    $entries[] = $entry;
                    continue;
                }
                self::storeEntryBytes($dir, $entry, $bytes);
                $entries[] = $entry;
                if (count($entries) > self::MAX_ENTRIES) {
                    return;
                }
            }
        } finally {
            $zip->close();
        }
    }

    private static function addFileEntry(string $path, string $name, string $dir, array &$entries, int &$totalBytes): void {
        $size = (int)filesize($path);
        $entry = self::newEntry($name, $size);
        if (!self::hasImageExtension($name)) {
            $entry['status'] = self::STATUS_SKIPPED;
            $entry['reason'] = 'Not an image file (and not a ZIP).';
            $entries[] = $entry;
            return;
        }
        if ($size > ImageStorage::maxBytes()) {
            $entry['status'] = self::STATUS_SKIPPED;
            $entry['reason'] = 'Larger than ' . ImageStorage::humanBytes(ImageStorage::maxBytes()) . '.';
            $entries[] = $entry;
            return;
        }
        $totalBytes += $size;
        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            throw new InvalidArgumentException('That upload is more than ' . ImageStorage::humanBytes(self::MAX_TOTAL_BYTES) . '. Please import in smaller batches.');
        }
        self::storeEntryBytes($dir, $entry, (string)file_get_contents($path));
        $entries[] = $entry;
    }

    private static function newEntry(string $sourceName, int $size): array {
        return [
            'source' => $sourceName,
            'back' => self::backTextFromFilename($sourceName),
            'size' => $size,
            'status' => self::STATUS_OK,
            'reason' => '',
            'file' => null,
            'card_id' => null,
        ];
    }

    /** Sniff the real type, then keep the bytes under a safe local name. */
    private static function storeEntryBytes(string $dir, array &$entry, string $bytes): void {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array((int)($info[2] ?? 0), [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            $entry['status'] = self::STATUS_SKIPPED;
            $entry['reason'] = 'Not a readable JPEG, PNG, WebP or GIF.';
            return;
        }
        if ($entry['back'] === '') {
            $entry['status'] = self::STATUS_SKIPPED;
            $entry['reason'] = 'The file name is empty, so there is nothing for the back.';
            return;
        }
        $local = 'entry-' . count(glob($dir . '/entry-*') ?: []) . '.' . image_type_to_extension((int)$info[2], false);
        if (file_put_contents($dir . '/' . $local, $bytes) === false) {
            throw new RuntimeException('Could not store an uploaded image.');
        }
        $entry['file'] = $local;
        $entry['width'] = (int)$info[0];
        $entry['height'] = (int)$info[1];
    }

    /**
     * Second file with the same back in one upload -> duplicate (skipped);
     * back already on a card in the deck -> exists (imported only when the
     * review form says so).
     */
    private static function markDuplicatesAndExisting(array &$entries, int $subcategoryId): void {
        $existing = [];
        foreach (CardManagement::listForSubcategory($subcategoryId) as $card) {
            $existing[self::normalizeBack((string)$card['back_text'])] = true;
        }
        $seen = [];
        foreach ($entries as &$e) {
            if ($e['status'] !== self::STATUS_OK) {
                continue;
            }
            $k = self::normalizeBack((string)$e['back']);
            if (isset($seen[$k])) {
                $e['status'] = self::STATUS_DUPLICATE;
                $e['reason'] = 'Same name as "' . $seen[$k] . '" in this upload.';
                continue;
            }
            $seen[$k] = (string)$e['source'];
            if (isset($existing[$k])) {
                $e['status'] = self::STATUS_EXISTS;
                $e['reason'] = 'A card with this back is already in the deck.';
            }
        }
        unset($e);
    }

    private static function normalizeBack(string $s): string {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    // ---- step 2: options ----------------------------------------------------

    /** Record the review-page choices. Options: import_existing (bool). */
    public static function setOptions(string $token, ?UserContext $ctx, array $options): array {
        $manifest = self::load($token, $ctx);
        if (!empty($manifest['done'])) {
            throw new RuntimeException('That import has already finished.');
        }
        $manifest['options']['import_existing'] = !empty($options['import_existing']);
        self::writeManifest($manifest);
        return $manifest;
    }

    /** Counts by status, plus how many will be imported under the current options. */
    public static function summarize(array $manifest): array {
        $counts = [self::STATUS_OK => 0, self::STATUS_EXISTS => 0, self::STATUS_DUPLICATE => 0, self::STATUS_SKIPPED => 0, self::STATUS_DONE => 0, self::STATUS_FAILED => 0];
        foreach ($manifest['entries'] as $e) {
            $counts[$e['status']] = ($counts[$e['status']] ?? 0) + 1;
        }
        $willImport = $counts[self::STATUS_OK] + (!empty($manifest['options']['import_existing']) ? $counts[self::STATUS_EXISTS] : 0);
        return $counts + ['will_import' => $willImport, 'total' => count($manifest['entries'])];
    }

    /** Absolute path of a stored entry's image, for the preview endpoint; null when it has none. */
    public static function entryFilePath(array $manifest, int $index): ?string {
        $e = $manifest['entries'][$index] ?? null;
        if (!$e || empty($e['file']) || preg_match('/^entry-\d+\.[a-z]+$/', (string)$e['file']) !== 1) {
            return null;
        }
        $path = self::tokenDir((string)$manifest['token']) . '/' . $e['file'];
        return is_file($path) ? $path : null;
    }

    // ---- step 3: import in batches -------------------------------------------

    /**
     * Import up to $max pending entries, then save progress. Returns the
     * updated manifest with 'done' set once every entry has been handled;
     * the temp folder is removed at that point (the manifest stays so the
     * summary page can still be shown).
     */
    public static function commitBatch(string $token, ?UserContext $ctx, int $max = self::BATCH_SIZE): array {
        $manifest = self::load($token, $ctx);
        if (!empty($manifest['done'])) {
            return $manifest;
        }
        $subcategoryId = (int)$manifest['subcategory_id'];
        $importExisting = !empty($manifest['options']['import_existing']);
        $dir = self::tokenDir($token);
        $processed = 0;
        $count = count($manifest['entries']);

        while ($manifest['next'] < $count && $processed < $max) {
            $i = (int)$manifest['next'];
            $e = &$manifest['entries'][$i];
            $manifest['next'] = $i + 1;
            $eligible = $e['status'] === self::STATUS_OK || ($importExisting && $e['status'] === self::STATUS_EXISTS);
            if (!$eligible) {
                unset($e);
                continue;
            }
            $processed++;
            try {
                $path = self::entryFilePath($manifest, $i);
                if ($path === null) {
                    throw new RuntimeException('The uploaded file is no longer available.');
                }
                $image = ImageStorage::prepareFromBytes((string)file_get_contents($path));
                $e['card_id'] = CardManagement::create($ctx, $subcategoryId, ['front_text' => '', 'back_text' => (string)$e['back']], $image);
                $e['status'] = self::STATUS_DONE;
                $manifest['created']++;
                @unlink($path);
            } catch (\Throwable $ex) {
                $e['status'] = self::STATUS_FAILED;
                $e['reason'] = $ex->getMessage();
                $manifest['failed']++;
            }
            unset($e);
            self::writeManifest($manifest);   // progress survives a timeout mid-batch
        }

        if ($manifest['next'] >= $count) {
            $manifest['done'] = true;
            $manifest['finished_at'] = date('c');
            foreach (glob($dir . '/entry-*') ?: [] as $f) {
                @unlink($f);
            }
            self::log('card_import.commit', ['token' => $token, 'subcategory_id' => $subcategoryId, 'created' => $manifest['created'], 'failed' => $manifest['failed']]);
        }
        self::writeManifest($manifest);
        return $manifest;
    }

    /** Throw the pending import away. */
    public static function discard(string $token, ?UserContext $ctx): void {
        $manifest = self::load($token, $ctx);
        self::removeDir(self::tokenDir($token));
        self::log('card_import.discard', ['token' => $token, 'subcategory_id' => (int)$manifest['subcategory_id']]);
    }
}
