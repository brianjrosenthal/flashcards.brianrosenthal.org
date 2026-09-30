<?php
declare(strict_types=1);

/**
 * Minimal S3-compatible object storage client: the one class that talks to
 * the bucket holding card images (Cloudflare R2, endpoint
 * https://{account}.r2.cloudflarestorage.com, region "auto"). Any
 * S3-compatible store (DreamHost DreamObjects, AWS S3) works the same way.
 *
 * Hand-rolled AWS Signature Version 4 over curl: no SDK, no Composer.
 */
class S3Client {

    /** SigV4 service name. */
    private const SERVICE = 's3';

    /** Payload hash used for presigned URLs, which sign no body. */
    private const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /** Hard ceiling AWS/Ceph place on presigned URL lifetime: 7 days. */
    private const MAX_PRESIGN_TTL = 604800;

    /** Connect timeout for every request, in seconds. */
    private const CONNECT_TIMEOUT = 5;

    /** Overall timeout for API calls and bucket listings, in seconds. Only small image bodies pass through here. */
    private const TRANSFER_TIMEOUT = 60;

    /** S3 DeleteObjects accepts at most 1000 keys per request. */
    private const DELETE_BATCH_SIZE = 1000;

    /**
     * Derived signing keys, by 'YYYYMMDD'. The key depends only on
     * (secret, date, region, service) — never on the object — so deriving it once
     * per request instead of once per URL is a ~4x saving when signing a whole
     * gallery page. See signingKey().
     *
     * @var array<string,string>
     */
    private static array $signingKeys = [];

    private string $endpoint;
    private string $region;
    private string $accessKey;
    private string $secretKey;

    public function __construct(string $endpoint, string $region, string $accessKey, string $secretKey) {
        $this->endpoint  = rtrim($endpoint, '/');
        $this->region    = $region;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
    }

    /** The endpoint this client talks to, e.g. 'https://objects-us-east-1.dream.io'. */
    public function endpoint(): string {
        return $this->endpoint;
    }

    /** Plain (unsigned) URL of an object — only useful for objects that are readable without auth. */
    public function publicUrl(string $bucket, string $key): string {
        return $this->endpoint . $this->canonicalPath($bucket, $key);
    }

    // -------------------------------------------------------------------------
    // Objects
    // -------------------------------------------------------------------------

    public function putObject(
        string $bucket,
        string $key,
        string $body,
        string $contentType,
        string $cacheControl
    ): void {
        [$status, $responseBody] = $this->request('PUT', $bucket, $key, [], $body, [
            'content-type'  => $contentType,
            'cache-control' => $cacheControl,
        ]);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'Could not save the object to storage: ' . $this->describeError($status, $responseBody)
            );
        }
    }

    public function getObject(string $bucket, string $key): ?string {
        [$status, $body] = $this->request('GET', $bucket, $key);

        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'Could not read the object from storage: ' . $this->describeError($status, $body)
            );
        }
        return $body;
    }

    public function objectExists(string $bucket, string $key): bool {
        try {
            [$status] = $this->request('HEAD', $bucket, $key);
        } catch (\RuntimeException $e) {
            return false;
        }
        return $status >= 200 && $status < 300;
    }

    /**
     * Size and content type of an object, or null when it does not exist.
     * Used to verify a browser upload before the key is recorded.
     *
     * @return ?array{size:int,content_type:string}
     * @throws \RuntimeException on transport failure or a non-404 error.
     */
    public function headObject(string $bucket, string $key): ?array {
        [$status, , $headers] = $this->requestWithHeaders('HEAD', $bucket, $key);
        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Could not inspect the object in storage (HTTP ' . $status . ').');
        }
        return [
            'size'         => (int)($headers['content-length'] ?? 0),
            'content_type' => (string)($headers['content-type'] ?? ''),
        ];
    }

    public function deleteObjects(string $bucket, array $keys): void {
        $keys = array_values(array_unique(array_filter($keys, static fn($k) => is_string($k) && $k !== '')));
        if ($keys === []) {
            return;
        }

        foreach (array_chunk($keys, self::DELETE_BATCH_SIZE) as $batch) {
            // The multi-object delete API is a POST to /{bucket}?delete with an
            // XML body. Ceph, like S3, requires Content-MD5 on this request (R2 accepts it).
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
            foreach ($batch as $k) {
                $xml .= '<Object><Key>' . htmlspecialchars($k, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Key></Object>';
            }
            $xml .= '</Delete>';

            [$status, $body] = $this->request('POST', $bucket, '', ['delete' => ''], $xml, [
                'content-type' => 'application/xml',
                'content-md5'  => base64_encode(md5($xml, true)),
            ]);

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(
                    'Could not delete objects from storage: ' . $this->describeError($status, $body)
                );
            }
        }
    }

    public function listKeys(string $bucket, string $prefix): array {
        $keys = [];
        foreach ($this->listObjects($bucket, $prefix) as $object) {
            $keys[] = $object['key'];
        }
        return $keys;
    }

    /**
     * Every object under a prefix as ['key' => string, 'size' => int], following
     * continuation tokens to the end. Pass an empty prefix for the whole bucket.
     *
     * @return array<int,array{key:string,size:int}>
     * @throws \RuntimeException on transport or API failure.
     */
    public function listObjects(string $bucket, string $prefix = ''): array {
        $out   = [];
        $token = null;

        do {
            $query = ['list-type' => '2'];
            if ($prefix !== '')   $query['prefix']            = $prefix;
            if ($token !== null)  $query['continuation-token'] = $token;

            [$status, $body] = $this->request('GET', $bucket, '', $query);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(
                    'Could not list object storage: ' . $this->describeError($status, $body)
                );
            }

            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                throw new \RuntimeException('Object storage returned an unreadable listing.');
            }

            foreach ($xml->Contents ?? [] as $item) {
                $out[] = [
                    'key'  => (string)$item->Key,
                    'size' => (int)$item->Size,
                ];
            }

            $truncated = ((string)($xml->IsTruncated ?? 'false')) === 'true';
            $token     = $truncated ? (string)($xml->NextContinuationToken ?? '') : null;
            if ($token === '') {
                $token = null;
            }
        } while ($token !== null);

        return $out;
    }

    /**
     * Delete every object under a prefix (e.g. a user's cards/{user_id}/).
     *
     * @return int Number of keys deleted.
     * @throws \RuntimeException on transport or API failure.
     */
    public function deleteByPrefix(string $bucket, string $prefix): int {
        $keys = $this->listKeys($bucket, $prefix);
        $this->deleteObjects($bucket, $keys);
        return count($keys);
    }

    // -------------------------------------------------------------------------
    // Presigned URLs (no network I/O)
    // -------------------------------------------------------------------------

    /**
     * Build a presigned GET URL. Pure local computation (no network I/O).
     *
     * Used to display images. Callers pass a QUANTIZED $issuedAt (see
     * ImageStorage::urlIssuedAt) so every viewer in a window gets a
     * byte-identical URL and the browser can cache the response.
     */
    public function presignedGetUrl(string $bucket, string $key, int $issuedAt, int $ttlSeconds): string {
        if ($ttlSeconds < 1) {
            throw new \RuntimeException('Presigned URL lifetime must be positive.');
        }
        if ($ttlSeconds > self::MAX_PRESIGN_TTL) {
            // Silently clamping would produce URLs that expire sooner than the
            // caller believes, which is exactly the sort of bug that shows up as
            // intermittently broken images.
            throw new \RuntimeException('Presigned URL lifetime may not exceed 7 days.');
        }

        $amzDate = gmdate('Ymd\THis\Z', $issuedAt);
        $date    = substr($amzDate, 0, 8);
        $scope   = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
        $path    = $this->canonicalPath($bucket, $key);

        $query = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->accessKey . '/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string)$ttlSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalQuery = $this->canonicalQuery($query);

        $canonicalRequest = implode("\n", [
            'GET',
            $path,
            $canonicalQuery,
            'host:' . $this->host() . "\n",
            'host',
            self::UNSIGNED_PAYLOAD,
        ]);

        $signature = hash_hmac(
            'sha256',
            $this->stringToSign($amzDate, $scope, $canonicalRequest),
            $this->signingKey($date)
        );

        return $this->endpoint . $path . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    /**
     * Build a presigned PUT URL so a browser can upload one object directly.
     * Pure local computation, like presignedGetUrl().
     *
     * $signedHeaders (lowercase name => value) are folded into the signature,
     * so the browser MUST send them exactly; S3 requires every x-amz-* header
     * (e.g. x-amz-acl: public-read) to be signed. Content-Type is deliberately
     * left unsigned so the caller can pass whatever the browser reports.
     *
     * @param array<string,string> $signedHeaders
     */
    public function presignedPutUrl(string $bucket, string $key, int $issuedAt, int $ttlSeconds, array $signedHeaders = []): string {
        if ($ttlSeconds < 1) {
            throw new \RuntimeException('Presigned URL lifetime must be positive.');
        }
        if ($ttlSeconds > self::MAX_PRESIGN_TTL) {
            throw new \RuntimeException('Presigned URL lifetime may not exceed 7 days.');
        }

        $amzDate = gmdate('Ymd\THis\Z', $issuedAt);
        $date    = substr($amzDate, 0, 8);
        $scope   = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
        $path    = $this->canonicalPath($bucket, $key);

        $headers = ['host' => $this->host()];
        foreach ($signedHeaders as $name => $value) {
            $headers[strtolower(trim((string)$name))] = trim((string)$value);
        }
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaderNames = implode(';', array_keys($headers));

        $query = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->accessKey . '/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string)$ttlSeconds,
            'X-Amz-SignedHeaders' => $signedHeaderNames,
        ];
        $canonicalQuery = $this->canonicalQuery($query);

        $canonicalRequest = implode("\n", [
            'PUT',
            $path,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaderNames,
            self::UNSIGNED_PAYLOAD,
        ]);

        $signature = hash_hmac(
            'sha256',
            $this->stringToSign($amzDate, $scope, $canonicalRequest),
            $this->signingKey($date)
        );

        return $this->endpoint . $path . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    // -------------------------------------------------------------------------
    // Buckets (setup / diagnostics only)
    // -------------------------------------------------------------------------

    /** Whether a bucket exists and our credentials can see it. */
    public function bucketExists(string $bucket): bool {
        try {
            [$status] = $this->request('HEAD', $bucket, '');
        } catch (\RuntimeException $e) {
            return false;
        }
        return $status >= 200 && $status < 300;
    }

    /**
     * Create a bucket unless it already exists. The bucket and its objects stay
     * private: pages show images through presigned GET URLs
     * (DreamObjects rejects canned ACLs such as public-read; R2 has none).
     *
     * @return bool True if a bucket was created, false if it already existed.
     * @throws \RuntimeException on failure (including, on DreamObjects, a name
     *                           taken by another account — names are global there).
     */
    public function createBucketIfMissing(string $bucket): bool {
        if ($this->bucketExists($bucket)) {
            return false;
        }

        [$status, $body] = $this->request('PUT', $bucket, '');

        // Ceph and R2 return this when the bucket is already ours; treat as success.
        if ($status === 409 && str_contains($body, 'BucketAlreadyOwnedByYou')) {
            return false;
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'Could not create the bucket "' . $bucket . '": ' . $this->describeError($status, $body)
            );
        }
        return true;
    }

    /**
     * Object count and total bytes for a bucket, for the admin diagnostics page.
     *
     * @return array{count:int,bytes:int}
     */
    public function bucketStats(string $bucket): array {
        $count = 0;
        $bytes = 0;
        foreach ($this->listObjects($bucket) as $object) {
            $count++;
            $bytes += $object['size'];
        }
        return ['count' => $count, 'bytes' => $bytes];
    }

    /**
     * The XML body for putBucketCors(): one rule allowing the given origins to
     * PUT/GET/HEAD with any request header. Separate from the request so tests
     * can pin the document shape.
     *
     * @param string[] $origins e.g. ['https://flashcards.brianrosenthal.org']
     */
    public static function corsConfigurationXml(array $origins): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><CORSRule>';
        foreach ($origins as $origin) {
            $xml .= '<AllowedOrigin>' . htmlspecialchars($origin, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</AllowedOrigin>';
        }
        $xml .= '<AllowedMethod>PUT</AllowedMethod><AllowedMethod>GET</AllowedMethod><AllowedMethod>HEAD</AllowedMethod>'
              . '<AllowedHeader>*</AllowedHeader>'
              . '<ExposeHeader>ETag</ExposeHeader>'
              . '<MaxAgeSeconds>3000</MaxAgeSeconds>'
              . '</CORSRule></CORSConfiguration>';
        return $xml;
    }

    /**
     * Replace the bucket's CORS configuration so browsers on $origins may
     * upload directly. Ceph, like S3, requires Content-MD5 on this request
     * (R2 accepts it).
     *
     * @param string[] $origins
     * @throws \RuntimeException on failure.
     */
    public function putBucketCors(string $bucket, array $origins): void {
        $xml = self::corsConfigurationXml($origins);
        [$status, $body] = $this->request('PUT', $bucket, '', ['cors' => ''], $xml, [
            'content-type' => 'application/xml',
            'content-md5'  => base64_encode(md5($xml, true)),
        ]);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'Could not set the bucket CORS rule: ' . $this->describeError($status, $body)
            );
        }
    }

    /**
     * Origins currently allowed by the bucket's CORS configuration, or null
     * when no configuration is set.
     *
     * @return ?string[]
     */
    public function getBucketCorsOrigins(string $bucket): ?array {
        [$status, $body] = $this->request('GET', $bucket, '', ['cors' => '']);
        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'Could not read the bucket CORS rule: ' . $this->describeError($status, $body)
            );
        }
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            throw new \RuntimeException('Object storage returned an unreadable CORS configuration.');
        }
        $origins = [];
        foreach ($xml->CORSRule ?? [] as $rule) {
            foreach ($rule->AllowedOrigin ?? [] as $origin) {
                $origins[] = (string)$origin;
            }
        }
        return $origins;
    }

    // -------------------------------------------------------------------------
    // Signing
    // -------------------------------------------------------------------------

    /**
     * The SigV4 signing key for a date, memoized. Depends only on
     * (secret, date, region, service) — never on the request — so it is derived
     * once per request rather than once per URL.
     */
    private function signingKey(string $yyyymmdd): string {
        $cacheKey = $yyyymmdd . '|' . $this->region . '|' . sha1($this->secretKey);
        if (isset(self::$signingKeys[$cacheKey])) {
            return self::$signingKeys[$cacheKey];
        }

        $k = hash_hmac('sha256', $yyyymmdd,      'AWS4' . $this->secretKey, true);
        $k = hash_hmac('sha256', $this->region,  $k, true);
        $k = hash_hmac('sha256', self::SERVICE,  $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);

        return self::$signingKeys[$cacheKey] = $k;
    }

    /** The SigV4 "string to sign". */
    private function stringToSign(string $amzDate, string $scope, string $canonicalRequest): string {
        return implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    /**
     * Perform a signed request against the endpoint.
     *
     * @param array<string,string> $query      Query parameters (unencoded).
     * @param array<string,string> $extraHeaders Lowercase header name => value.
     *                                           All are signed.
     * @return array{0:int,1:string} [httpStatus, responseBody]
     * @throws \RuntimeException when unconfigured or the transport fails.
     */
    private function request(
        string $method,
        string $bucket,
        string $key,
        array  $query        = [],
        string $body         = '',
        array  $extraHeaders = []
    ): array {
        [$status, $responseBody] = $this->requestWithHeaders($method, $bucket, $key, $query, $body, $extraHeaders);
        return [$status, $responseBody];
    }

    /**
     * Like request(), but also returns the response headers (lowercase names)
     * — needed by headObject().
     *
     * @return array{0:int,1:string,2:array<string,string>}
     */
    private function requestWithHeaders(
        string $method,
        string $bucket,
        string $key,
        array  $query        = [],
        string $body         = '',
        array  $extraHeaders = []
    ): array {
        if ($this->accessKey === '' || $this->secretKey === '' || $this->endpoint === '') {
            throw new \RuntimeException('Image storage is not configured.');
        }

        $now         = time();
        $amzDate     = gmdate('Ymd\THis\Z', $now);
        $date        = substr($amzDate, 0, 8);
        $scope       = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
        $path        = $this->canonicalPath($bucket, $key);
        $payloadHash = hash('sha256', $body);

        // Headers that take part in the signature. 'host' is mandatory; S3 also
        // requires x-amz-content-sha256 on every signed request.
        $headers = $extraHeaders + [
            'host'                 => $this->host(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        ];
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalQuery   = $this->canonicalQuery($query);
        $canonicalRequest = implode("\n", [
            $method,
            $path,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $signature = hash_hmac(
            'sha256',
            $this->stringToSign($amzDate, $scope, $canonicalRequest),
            $this->signingKey($date)
        );

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            if ($name === 'host') continue;   // curl sets Host itself
            $curlHeaders[] = $name . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: AWS4-HMAC-SHA256 '
            . 'Credential=' . $this->accessKey . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $url = $this->endpoint . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');

        $result = $this->send($method, $url, $curlHeaders, $body);
        return [$result[0], $result[1], $result[2] ?? []];
    }

    /**
     * Execute the HTTP request. Isolated from request() so tests can subclass and
     * assert that presigning never reaches the network.
     *
     * @param string[] $headers
     * @return array{0:int,1:string,2?:array<string,string>} [status, body, responseHeaders]
     */
    protected function send(string $method, string $url, array $headers, string $body): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not reach image storage (curl unavailable).');
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TRANSFER_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException(
                'Could not reach image storage' . ($error !== '' ? ': ' . $error : '.')
            );
        }

        return [$status, (string)$response, $responseHeaders];
    }

    // -------------------------------------------------------------------------
    // Canonicalization helpers
    // -------------------------------------------------------------------------

    /** Host (with port when non-default) for the Host header and signature. */
    private function host(): string {
        $parts = parse_url($this->endpoint);
        $host  = $parts['host'] ?? '';
        if ($host === '') {
            throw new \RuntimeException('Image storage endpoint is malformed.');
        }
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        return $host;
    }

    /**
     * Canonical (path-style) URI for a bucket + key, e.g.
     * '/flashcards-images/cards/3/17/0f9c....jpg'. Pass an empty key for a
     * bucket-level operation.
     */
    private function canonicalPath(string $bucket, string $key): string {
        $path = '/' . rawurlencode($bucket);
        if ($key !== '') {
            $path .= '/' . $this->encodePath($key);
        }
        return $path;
    }

    /**
     * Encode an object key for the canonical URI: each segment encoded once,
     * with '/' separators preserved. S3 (and Ceph) single-encode the path, unlike
     * most other AWS services.
     */
    private function encodePath(string $key): string {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /**
     * Canonical query string: parameters percent-encoded and sorted by name in
     * byte order, with empty values rendered as 'name='.
     *
     * @param array<string,string> $query
     */
    private function canonicalQuery(array $query): string {
        if ($query === []) {
            return '';
        }
        $encoded = [];
        foreach ($query as $name => $value) {
            $encoded[rawurlencode((string)$name)] = rawurlencode((string)$value);
        }
        ksort($encoded);

        $pairs = [];
        foreach ($encoded as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        return implode('&', $pairs);
    }

    /**
     * Turn an error response into something worth showing a user: the S3 <Message>
     * when present, otherwise the status code. Never echoes the whole XML body.
     */
    private function describeError(int $status, string $body): string {
        if ($body !== '') {
            $xml = @simplexml_load_string($body);
            if ($xml !== false && isset($xml->Message) && (string)$xml->Message !== '') {
                return (string)$xml->Message . ' (HTTP ' . $status . ')';
            }
        }
        return 'storage returned HTTP ' . $status . '.';
    }
}
