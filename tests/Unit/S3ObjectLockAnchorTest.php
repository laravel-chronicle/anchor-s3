<?php

declare(strict_types=1);

use Aws\Api\DateTimeResult;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Chronicle\Anchoring\AnchorReceipt;
use Chronicle\Anchoring\CheckpointDigest;
use Chronicle\AnchorS3\S3ObjectLockAnchor;
use Chronicle\Checkpoints\Checkpoint;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use Psr\Http\Message\RequestInterface;

it('name() returns s3-object-lock', function () {
    $anchor = new S3ObjectLockAnchor(['bucket' => 'b'], makeMockS3Client());

    expect($anchor->name())->toBe('s3-object-lock');
});

it('throws when bucket is missing from config', function () {
    new S3ObjectLockAnchor([], makeMockS3Client());
})->throws(InvalidArgumentException::class, 'bucket');

/**
 * Build an unsaved Checkpoint for digest-level assertions. Setting created_at
 * needs a DB connection resolver, which the testbench TestCase (bound in
 * tests/Pest.php) provides - that is why unit tests extend it.
 */
function fakeCheckpoint(): Checkpoint
{
    $cp = new Checkpoint;
    $cp->id = '01J0S3ANCHORTESTCHECKPOINT0';
    $cp->chain_hash = str_repeat('a', 64);
    $cp->created_at = Carbon::createFromTimestamp(1_700_000_000);

    return $cp;
}

it('anchor() PutObjects the digest under an object lock and returns a receipt', function () {
    $cp = fakeCheckpoint();
    $digest = CheckpointDigest::for($cp);

    $captured = null;
    $s3 = makeMockS3Client([
        function (CommandInterface $cmd, RequestInterface $req) use (&$captured): Result {
            $captured = $cmd->toArray();

            return new Result(['ETag' => '"etag-abc"', 'VersionId' => 'ver-123']);
        },
    ]);

    $anchor = new S3ObjectLockAnchor(
        ['bucket' => 'worm-bucket', 'prefix' => 'anchors', 'mode' => 'COMPLIANCE', 'retain_days' => 30],
        $s3,
    );

    $receipt = $anchor->anchor($cp);

    // The outgoing PutObject carried the digest under a COMPLIANCE lock.
    expect($captured['Bucket'])->toBe('worm-bucket')
        ->and($captured['Key'])->toBe('anchors/'.$cp->id.'.digest')
        ->and((string) $captured['Body'])->toBe($digest)
        ->and($captured['ObjectLockMode'])->toBe('COMPLIANCE')
        ->and($captured['ObjectLockRetainUntilDate'])->not->toBeNull()
        // The receipt locates the exact immutable version.
        ->and($receipt->provider)->toBe('s3-object-lock')
        ->and($receipt->reference)->toBe('worm-bucket/anchors/'.$cp->id.'.digest@ver-123')
        ->and($receipt->proof)->toBe('"etag-abc"');

});

it('defaults to GOVERNANCE when no mode is configured', function () {
    // Safety-critical default. GOVERNANCE retention can be lifted by a permitted
    // principal; COMPLIANCE cannot be removed by anyone until it expires. A user
    // who omits `mode` must land on the recoverable one, never the irreversible
    // one. This test pins that so a future change cannot silently flip it back.
    $cp = fakeCheckpoint();

    $captured = null;
    $s3 = makeMockS3Client([
        function (CommandInterface $cmd, RequestInterface $req) use (&$captured): Result {
            $captured = $cmd->toArray();

            return new Result(['ETag' => '"etag-abc"', 'VersionId' => 'ver-123']);
        },
    ]);

    // No 'mode' key at all - exercise the default path, not an explicit value.
    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket'], $s3);

    $anchor->anchor($cp);

    expect($captured['ObjectLockMode'])->toBe('GOVERNANCE');
});

it('falls back to GOVERNANCE when mode is set to an unrecognized value', function () {
    // Anything that is not exactly 'COMPLIANCE' must resolve to GOVERNANCE - a
    // typo or garbage value must fail-safe, never silently select COMPLIANCE.
    $cp = fakeCheckpoint();

    $captured = null;
    $s3 = makeMockS3Client([
        function (CommandInterface $cmd, RequestInterface $req) use (&$captured): Result {
            $captured = $cmd->toArray();

            return new Result(['ETag' => '"etag-abc"', 'VersionId' => 'ver-123']);
        },
    ]);

    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket', 'mode' => 'compliance'], $s3);

    $anchor->anchor($cp);

    // Lowercase 'compliance' is not the exact token 'COMPLIANCE', so it must not
    // be honored as COMPLIANCE - it falls through to the safe default.
    expect($captured['ObjectLockMode'])->toBe('GOVERNANCE');
});

it('honors an explicit COMPLIANCE mode', function () {
    // The opt-in path still works: an exact 'COMPLIANCE' must reach the wire, so
    // production deployments that deliberately choose it are not quietly downgraded.
    $cp = fakeCheckpoint();

    $captured = null;
    $s3 = makeMockS3Client([
        function (CommandInterface $cmd, RequestInterface $req) use (&$captured): Result {
            $captured = $cmd->toArray();

            return new Result(['ETag' => '"etag-abc"', 'VersionId' => 'ver-123']);
        },
    ]);

    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket', 'mode' => 'COMPLIANCE'], $s3);

    $anchor->anchor($cp);

    expect($captured['ObjectLockMode'])->toBe('COMPLIANCE');
});

/**
 * A GetObject Result for a locked object holding $body.
 *
 * @param  array<string, mixed>  $overrides
 */
function lockedGetResult(string $body, array $overrides = []): Result
{
    return new Result(array_merge([
        'Body' => Utils::streamFor($body),
        'ObjectLockMode' => 'COMPLIANCE',
        'ObjectLockRetainUntilDate' => new DateTimeResult('+10 years'),
        'ETag' => '"etag-abc"',
    ], $overrides));
}

function s3Receipt(Checkpoint $cp): AnchorReceipt
{
    return new AnchorReceipt(
        provider: 's3-object-lock',
        reference: 'worm-bucket/anchors/'.$cp->id.'.digest@ver-123',
        proof: '"etag-abc"',
        anchoredAt: now()->toImmutable(),
    );
}

it('verify() returns true when the locked object holds the matching digest', function () {
    $cp = fakeCheckpoint();
    $s3 = makeMockS3Client([lockedGetResult(CheckpointDigest::for($cp))]);

    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket', 'prefix' => 'anchors'], $s3);

    expect($anchor->verify($cp, s3Receipt($cp)))->toBeTrue();
});

it('verify() returns false when the stored bytes do not match the digest', function () {
    $cp = fakeCheckpoint();
    $s3 = makeMockS3Client([lockedGetResult(str_repeat('f', 64))]); // wrong digest

    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket', 'prefix' => 'anchors'], $s3);

    expect($anchor->verify($cp, s3Receipt($cp)))->toBeFalse();
});

it('verify() returns false when the object carries no lock metadata', function () {
    $cp = fakeCheckpoint();
    $s3 = makeMockS3Client([
        lockedGetResult(CheckpointDigest::for($cp), ['ObjectLockMode' => null, 'ObjectLockRetainUntilDate' => null]),
    ]);

    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket', 'prefix' => 'anchors'], $s3);

    expect($anchor->verify($cp, s3Receipt($cp)))->toBeFalse();
});

it('verify() returns false when GetObject throws (missing object)', function () {
    $cp = fakeCheckpoint();
    $s3 = makeMockS3Client([
        new S3Exception('not found', new Command('GetObject')),
    ]);

    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket', 'prefix' => 'anchors'], $s3);

    expect($anchor->verify($cp, s3Receipt($cp)))->toBeFalse();
});

it('anchor() throws when PutObject returns no ETag/VersionId (bucket not versioned/locked)', function () {
    $cp = fakeCheckpoint();
    $s3 = makeMockS3Client([new Result([])]); // neither ETag nor VersionId returned

    $bucket = 'worm-bucket';

    $anchor = new S3ObjectLockAnchor(['bucket' => $bucket, 'prefix' => 'anchors'], $s3);

    expect(fn () => $anchor->anchor($cp))
        ->toThrow(function (RuntimeException $e) use ($bucket) {
            expect($e->getMessage())
                ->toContain($bucket)
                ->toContain('Object Lock')
                ->toContain('versioning');
        });
});

it('verify() returns false when the receipt reference is null', function () {
    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket'], makeMockS3Client());

    $receipt = new AnchorReceipt(
        provider: 's3-object-lock',
        reference: null,
        proof: null,
        anchoredAt: now()->toImmutable(),
    );

    expect($anchor->verify(fakeCheckpoint(), $receipt))->toBeFalse();
});

it('verify() returns false when the reference has no @version separator', function () {
    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket'], makeMockS3Client());

    $receipt = new AnchorReceipt(
        provider: 's3-object-lock',
        reference: 'worm-bucket/anchors/x.digest', // no '@versionId'
        proof: null,
        anchoredAt: now()->toImmutable(),
    );

    expect($anchor->verify(fakeCheckpoint(), $receipt))->toBeFalse();
});

it('verify() returns false when the reference lacks a bucket/key slash or carries an empty version', function () {
    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket'], makeMockS3Client());

    $noSlash = new AnchorReceipt(
        provider: 's3-object-lock',
        reference: 'nobucketkey@ver-123', // '@' present but no '/' in the bucket/key half
        proof: null,
        anchoredAt: now()->toImmutable(),
    );
    $emptyVersion = new AnchorReceipt(
        provider: 's3-object-lock',
        reference: 'worm-bucket/anchors/x.digest@', // trailing '@' -> empty versionId
        proof: null,
        anchoredAt: now()->toImmutable(),
    );

    expect($anchor->verify(fakeCheckpoint(), $noSlash))->toBeFalse()
        ->and($anchor->verify(fakeCheckpoint(), $emptyVersion))->toBeFalse();
});

it('verify() returns false when the ETag proof no longer matches the stored object', function () {
    $cp = fakeCheckpoint();
    // Digest and lock metadata are correct, but the object's ETag differs from
    // the receipt proof -> the proof check must reject it.
    $s3 = makeMockS3Client([lockedGetResult(CheckpointDigest::for($cp), ['ETag' => '"tampered-etag"'])]);

    $anchor = new S3ObjectLockAnchor(['bucket' => 'worm-bucket', 'prefix' => 'anchors'], $s3);

    // s3Receipt()'s proof is '"etag-abc"', which no longer matches.
    expect($anchor->verify($cp, s3Receipt($cp)))->toBeFalse();
});
