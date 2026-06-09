<?php

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
 * tests/Pest.php) provides — that is why unit tests extend it.
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
