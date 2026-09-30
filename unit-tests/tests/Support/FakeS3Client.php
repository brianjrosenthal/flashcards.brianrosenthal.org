<?php
declare(strict_types=1);

/**
 * In-memory S3Client for tests: objects live in an array keyed by
 * "bucket\0key", and any attempt to reach the network fails the test.
 * Presigning is inherited unchanged (it is pure computation). One instance is
 * injected per storage provider (see tests/bootstrap.php), each with its own
 * endpoint so URLs reveal which provider they address.
 */
final class FakeS3Client extends S3Client {

    /** @var array<string,array{size:int,content_type:string,body:string}> */
    public array $objects = [];
    /** @var string[] */
    public array $buckets = [];
    /** @var array<string,string[]> bucket => origins */
    public array $cors = [];
    /** @var array<string,int> */
    public array $calls = [];
    public bool $failDeletes = false;

    public function __construct(string $endpoint = 'https://objects-test.dream.io', string $region = 'us-east-1') {
        parent::__construct($endpoint, $region, 'AKIATEST', 'secret-test');
    }

    public function reset(): void {
        $this->objects = [];
        $this->buckets = [];
        $this->cors = [];
        $this->calls = [];
        $this->failDeletes = false;
    }

    /** Simulate a browser upload having landed in the bucket. */
    public function seedObject(string $bucket, string $key, int $size, string $contentType): void {
        // Size is recorded, not materialized: tests seed multi-GB "objects".
        $this->objects[$bucket . "\0" . $key] = ['size' => $size, 'content_type' => $contentType, 'body' => ''];
    }

    private function count(string $op): void {
        $this->calls[$op] = ($this->calls[$op] ?? 0) + 1;
    }

    public function putObject(string $bucket, string $key, string $body, string $contentType, string $cacheControl): void {
        $this->count('put');
        $this->objects[$bucket . "\0" . $key] = ['size' => strlen($body), 'content_type' => $contentType, 'body' => $body];
    }

    public function getObject(string $bucket, string $key): ?string {
        $this->count('get');
        return $this->objects[$bucket . "\0" . $key]['body'] ?? null;
    }

    public function objectExists(string $bucket, string $key): bool {
        $this->count('head');
        return isset($this->objects[$bucket . "\0" . $key]);
    }

    public function headObject(string $bucket, string $key): ?array {
        $this->count('head');
        $o = $this->objects[$bucket . "\0" . $key] ?? null;
        return $o === null ? null : ['size' => $o['size'], 'content_type' => $o['content_type']];
    }

    public function deleteObjects(string $bucket, array $keys): void {
        $this->count('delete');
        if ($this->failDeletes) {
            throw new \RuntimeException('Could not delete objects from storage: simulated failure');
        }
        foreach ($keys as $k) {
            unset($this->objects[$bucket . "\0" . $k]);
        }
    }

    public function listObjects(string $bucket, string $prefix = ''): array {
        $this->count('list');
        $out = [];
        foreach ($this->objects as $composite => $o) {
            [$b, $k] = explode("\0", $composite, 2);
            if ($b === $bucket && ($prefix === '' || strpos($k, $prefix) === 0)) {
                $out[] = ['key' => $k, 'size' => $o['size']];
            }
        }
        return $out;
    }

    public function bucketExists(string $bucket): bool {
        return in_array($bucket, $this->buckets, true);
    }

    public function createBucketIfMissing(string $bucket): bool {
        if ($this->bucketExists($bucket)) {
            return false;
        }
        $this->buckets[] = $bucket;
        return true;
    }

    public function putBucketCors(string $bucket, array $origins): void {
        $this->cors[$bucket] = array_values($origins);
    }

    public function getBucketCorsOrigins(string $bucket): ?array {
        return $this->cors[$bucket] ?? null;
    }

    protected function send(string $method, string $url, array $headers, string $body): array {
        throw new \LogicException("FakeS3Client must not reach the network (tried $method $url)");
    }
}
