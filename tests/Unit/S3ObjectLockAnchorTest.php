<?php

use Aws\CommandInterface;
use Aws\Result;
use Chronicle\Anchoring\CheckpointDigest;
use Chronicle\AnchorS3\S3ObjectLockAnchor;
use Chronicle\Checkpoints\Checkpoint;
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
