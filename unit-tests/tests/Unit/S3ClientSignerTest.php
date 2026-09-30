<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for S3Client's AWS Signature V4 implementation.
 *
 * The headline test checks the whole signing chain against the signature AWS
 * publishes for its presigned-URL example ("Example: Signature calculation for
 * presigned URL"), which pins the HMAC key derivation, the string-to-sign format,
 * and query canonicalization all at once. Getting any of them subtly wrong
 * produces a signature that R2 or DreamObjects rejects with an opaque 403, so it is
 * worth locking down precisely.
 *
 * Everything here is pure computation — see NonNetworkS3Client, which fails
 * the test if presigning ever reaches the network.
 */
final class S3ClientSignerTest extends TestCase {

    /** Credentials from AWS's published SigV4 examples. */
    private const EXAMPLE_ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    private const EXAMPLE_SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    /** An S3Client pointed at DreamHost's real endpoint shape. */
    private function client(): S3Client {
        return new NonNetworkS3Client(
            'https://s3.us-east-005.dream.io',
            'us-east-005',
            'AKIAEXAMPLEKEY000000',
            'secret0000000000000000000000000000000000'
        );
    }

    /** Call a private/protected method. */
    private function invoke(S3Client $client, string $method, array $args = []): mixed {
        $ref = new \ReflectionMethod($client, $method);
        return $ref->invokeArgs($client, $args);
    }

    // ── The published AWS test vector ───────────────────────────────────────

    public function testMatchesAwsPublishedPresignedSignature(): void {
        // AWS's example is virtual-host style (host carries the bucket), so the
        // canonical request is built here to match the documented one exactly;
        // what is under test is the signing chain, which is style-independent.
        $client = new NonNetworkS3Client(
            'https://examplebucket.s3.amazonaws.com',
            'us-east-1',
            self::EXAMPLE_ACCESS_KEY,
            self::EXAMPLE_SECRET_KEY
        );

        $amzDate = '20130524T000000Z';
        $date    = '20130524';
        $scope   = '20130524/us-east-1/s3/aws4_request';

        $canonicalQuery = $this->invoke($client, 'canonicalQuery', [[
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => self::EXAMPLE_ACCESS_KEY . '/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => '86400',
            'X-Amz-SignedHeaders' => 'host',
        ]]);

        // Sorted by name, with the credential's slashes percent-encoded.
        $this->assertSame(
            'X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host',
            $canonicalQuery
        );

        $canonicalRequest = implode("\n", [
            'GET',
            '/test.txt',
            $canonicalQuery,
            "host:examplebucket.s3.amazonaws.com\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = $this->invoke($client, 'stringToSign', [$amzDate, $scope, $canonicalRequest]);
        $this->assertSame(
            "AWS4-HMAC-SHA256\n20130524T000000Z\n20130524/us-east-1/s3/aws4_request\n"
            . '3bfa292879f6447bbcda7001decf97f4a54dc650c8942174ae0a9121cf58ad04',
            $stringToSign
        );

        $signature = hash_hmac('sha256', $stringToSign, $this->invoke($client, 'signingKey', [$date]));
        $this->assertSame(
            'aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $signature,
            'signature must match the value AWS publishes for this example'
        );
    }

    // ── Presigned URL shape ─────────────────────────────────────────────────

    public function testPresignedUrlHasEveryRequiredParameterAndPathStyleHost(): void {
        $url = $this->client()->presignedGetUrl(
            'mastery-videos',
            'videos/3/17/4821a3f9c1b2d4e6f8a0b2c4d6e8fa0b.mp4',
            1774526400,
            14400
        );

        // Path-style: the bucket is in the path, not the hostname. Virtual-host
        // style would need wildcard DNS that DreamObjects doesn't guarantee.
        $this->assertStringStartsWith(
            'https://s3.us-east-005.dream.io/mastery-videos/videos/3/17/',
            $url
        );

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame('AWS4-HMAC-SHA256', $query['X-Amz-Algorithm']);
        $this->assertSame('14400', $query['X-Amz-Expires']);
        $this->assertSame('host', $query['X-Amz-SignedHeaders']);
        $this->assertSame('20260326T120000Z', $query['X-Amz-Date']);
        $this->assertStringContainsString('/us-east-005/s3/aws4_request', $query['X-Amz-Credential']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['X-Amz-Signature']);
    }

    public function testSignatureAppearsLastSoTheSignedQueryIsIntact(): void {
        // X-Amz-Signature must not be part of the signed canonical query, which
        // means it has to be appended after it.
        $url = $this->client()->presignedGetUrl('b', 'k.jpg', 1774526400, 3600);
        $this->assertStringContainsString('&X-Amz-Signature=', $url);
        $this->assertSame(
            1,
            preg_match('/&X-Amz-Signature=[0-9a-f]{64}$/', $url),
            'the signature must be the final query parameter'
        );
    }

    // ── Quantization: the property that makes images cacheable ──────────────

    public function testIdenticalIssuedAtProducesIdenticalUrl(): void {
        $client = $this->client();
        $a = $client->presignedGetUrl('bucket', 'club-1/9-abc.jpg', 1774526400, 14400);
        $b = $client->presignedGetUrl('bucket', 'club-1/9-abc.jpg', 1774526400, 14400);

        // Byte-identical URLs are what let the browser serve the image from cache
        // instead of re-downloading every thumbnail on every page view.
        $this->assertSame($a, $b);
    }

    public function testDifferentWindowProducesDifferentSignature(): void {
        $client = $this->client();
        $a = $client->presignedGetUrl('bucket', 'club-1/9-abc.jpg', 1774526400, 14400);
        $b = $client->presignedGetUrl('bucket', 'club-1/9-abc.jpg', 1774526400 + 7200, 14400);

        $this->assertNotSame($a, $b, 'a new window must re-sign');
    }

    public function testDifferentKeyOrBucketProducesDifferentSignature(): void {
        $client = $this->client();
        $base  = $client->presignedGetUrl('bucket', 'club-1/9-abc.jpg', 1774526400, 14400);
        $byKey = $client->presignedGetUrl('bucket', 'club-1/9-abd.jpg', 1774526400, 14400);
        $byBkt = $client->presignedGetUrl('other',  'club-1/9-abc.jpg', 1774526400, 14400);

        $this->assertNotSame($base, $byKey);
        $this->assertNotSame($base, $byBkt);
    }

    public function testTtlIsValidatedRatherThanSilentlyClamped(): void {
        // Clamping would hand out URLs that die sooner than the caller believes,
        // which surfaces as intermittently broken images.
        $client = $this->client();

        try {
            $client->presignedGetUrl('bucket', 'k.jpg', 1774526400, 604801);
            $this->fail('expected a rejection above the 7-day maximum');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('7 days', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $client->presignedGetUrl('bucket', 'k.jpg', 1774526400, 0);
    }

    // ── Presigned PUT (browser uploads) ────────────────────────────────────

    public function testPresignedPutUrlSignsTheAclHeaderAndUsesPutMethod(): void {
        $client = $this->client();
        $url = $client->presignedPutUrl('mastery-videos', 'videos/3/17/abc.mp4', 1774526400, 900, ['x-amz-acl' => 'public-read']);

        $this->assertStringStartsWith('https://s3.us-east-005.dream.io/mastery-videos/videos/3/17/abc.mp4?', $url);
        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame('host;x-amz-acl', $query['X-Amz-SignedHeaders']);
        $this->assertSame('900', $query['X-Amz-Expires']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['X-Amz-Signature']);
        $this->assertSame(1, preg_match('/&X-Amz-Signature=[0-9a-f]{64}$/', $url));

        // The signature must differ from a GET of the same key at the same time
        // (the method is part of the canonical request) and from a PUT without
        // the ACL header (the signed headers are too).
        $get = $client->presignedGetUrl('mastery-videos', 'videos/3/17/abc.mp4', 1774526400, 900);
        $plainPut = $client->presignedPutUrl('mastery-videos', 'videos/3/17/abc.mp4', 1774526400, 900);
        $this->assertNotSame($this->signatureOf($get), $this->signatureOf($url));
        $this->assertNotSame($this->signatureOf($plainPut), $this->signatureOf($url));
        $this->assertSame(0, NonNetworkS3Client::$sendCalls);
    }

    public function testPresignedPutMatchesManualCanonicalRequest(): void {
        // Recompute the signature by hand from the documented SigV4 steps, so
        // the header canonicalization (lowercase, sorted, trailing newline) is
        // pinned independently of presignedPutUrl()'s own code path.
        $client = $this->client();
        $issuedAt = 1774526400;
        $amzDate = gmdate('Ymd\THis\Z', $issuedAt);
        $scope = substr($amzDate, 0, 8) . '/us-east-005/s3/aws4_request';
        $canonicalQuery = $this->invoke($client, 'canonicalQuery', [[
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => 'AKIAEXAMPLEKEY000000/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => '900',
            'X-Amz-SignedHeaders' => 'host;x-amz-acl',
        ]]);
        $canonicalRequest = implode("\n", [
            'PUT',
            '/b/k.mp4',
            $canonicalQuery,
            "host:s3.us-east-005.dream.io\nx-amz-acl:public-read\n",
            'host;x-amz-acl',
            'UNSIGNED-PAYLOAD',
        ]);
        $expected = hash_hmac('sha256',
            $this->invoke($client, 'stringToSign', [$amzDate, $scope, $canonicalRequest]),
            $this->invoke($client, 'signingKey', [substr($amzDate, 0, 8)]));

        $url = $client->presignedPutUrl('b', 'k.mp4', $issuedAt, 900, ['X-Amz-Acl' => ' public-read ']);
        $this->assertSame($expected, $this->signatureOf($url), 'header names lowercase and values trimmed before signing');
    }

    public function testCorsConfigurationXml(): void {
        $xml = S3Client::corsConfigurationXml(['https://mastery.brianrosenthal.org', 'http://localhost:8080']);
        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);
        $rule = $parsed->CORSRule;
        $this->assertSame(['https://mastery.brianrosenthal.org', 'http://localhost:8080'], array_map('strval', iterator_to_array($rule->AllowedOrigin, false)));
        $this->assertSame(['PUT', 'GET', 'HEAD'], array_map('strval', iterator_to_array($rule->AllowedMethod, false)));
        $this->assertSame('*', (string)$rule->AllowedHeader);
        $this->assertSame('ETag', (string)$rule->ExposeHeader);
    }

    private function signatureOf(string $url): string {
        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        return (string)$query['X-Amz-Signature'];
    }

    // ── No network I/O ──────────────────────────────────────────────────────

    public function testPresigningNeverTouchesTheNetwork(): void {
        // NonNetworkS3Client::send() fails the test if it is ever reached.
        // Presigning is called once per gallery tile, so a stray HEAD/GET in here
        // would turn a 60-photo page into 60 round trips.
        $client = $this->client();
        for ($i = 0; $i < 25; $i++) {
            $client->presignedGetUrl('bucket', "videos/1/$i/abc.mp4", 1774526400, 14400);
        }
        $this->assertSame(0, NonNetworkS3Client::$sendCalls);
    }

    // ── Canonicalization details ────────────────────────────────────────────

    public function testObjectKeysAreSingleEncodedWithSlashesPreserved(): void {
        $client = $this->client();

        // S3 (and Ceph) single-encode the canonical path, unlike other AWS
        // services. Double-encoding here yields a 403 on any key with a space.
        $this->assertSame(
            'club-12/4821-a3f9.jpg',
            $this->invoke($client, 'encodePath', ['club-12/4821-a3f9.jpg'])
        );
        $this->assertSame(
            'club-1/my%20photo.jpg',
            $this->invoke($client, 'encodePath', ['club-1/my photo.jpg'])
        );
        // RFC 3986 unreserved characters must stay literal.
        $this->assertSame(
            'club-1/a-b_c.d~e.jpg',
            $this->invoke($client, 'encodePath', ['club-1/a-b_c.d~e.jpg'])
        );
    }

    public function testCanonicalQuerySortsByNameAndRendersEmptyValues(): void {
        $client = $this->client();

        $this->assertSame(
            'a=1&b=2&c=3',
            $this->invoke($client, 'canonicalQuery', [['c' => '3', 'a' => '1', 'b' => '2']])
        );
        // The multi-object delete endpoint is '?delete', which canonicalizes to
        // 'delete=' — omitting the '=' breaks that request's signature.
        $this->assertSame('delete=', $this->invoke($client, 'canonicalQuery', [['delete' => '']]));
        $this->assertSame('', $this->invoke($client, 'canonicalQuery', [[]]));
    }

    public function testCanonicalPathHandlesBucketOnlyOperations(): void {
        $client = $this->client();
        $this->assertSame('/my-bucket', $this->invoke($client, 'canonicalPath', ['my-bucket', '']));
        $this->assertSame('/my-bucket/a/b.jpg', $this->invoke($client, 'canonicalPath', ['my-bucket', 'a/b.jpg']));
    }

    public function testHostIncludesNonDefaultPort(): void {
        $withPort = new NonNetworkS3Client('https://storage.example:8443', 'r', 'a', 's');
        $this->assertSame('storage.example:8443', $this->invoke($withPort, 'host'));

        $plain = new NonNetworkS3Client('https://storage.example', 'r', 'a', 's');
        $this->assertSame('storage.example', $this->invoke($plain, 'host'));
    }

    public function testSigningKeyIsMemoizedPerDate(): void {
        $client = $this->client();
        $first  = $this->invoke($client, 'signingKey', ['20260326']);
        $second = $this->invoke($client, 'signingKey', ['20260326']);
        $other  = $this->invoke($client, 'signingKey', ['20260327']);

        $this->assertSame($first, $second, 'same date reuses the derived key');
        $this->assertNotSame($first, $other, 'a new date derives a new key');
        $this->assertSame(32, strlen($first), 'raw HMAC-SHA256 output');
    }

    // ── Configuration gate ──────────────────────────────────────────────────

    public function testUnconfiguredClientRefusesRequestsWithAClearMessage(): void {
        $client = new NonNetworkS3Client('', '', '', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not configured');
        $client->getObject('bucket', 'key.mp4');
    }
}

/**
 * An S3Client whose HTTP layer is disabled: any attempt to reach the network
 * throws. Used to prove presignedGetUrl() is pure local computation.
 */
final class NonNetworkS3Client extends S3Client {

    public static int $sendCalls = 0;

    protected function send(string $method, string $url, array $headers, string $body): array {
        self::$sendCalls++;
        throw new \LogicException("send() must not be reached in signer tests (tried $method $url)");
    }
}
