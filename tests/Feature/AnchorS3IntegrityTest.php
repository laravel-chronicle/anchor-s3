<?php

use Aws\Api\DateTimeResult;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use Chronicle\AnchorS3\S3ObjectLockAnchor;
use Chronicle\Checkpoints\CheckpointCreator;
use Chronicle\Facades\Chronicle;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    $this->useEloquentDriver();
    config([
        'chronicle.anchoring.enabled' => true,
        'chronicle.anchoring.providers' => [
            's3-object-lock' => [
                'provider' => S3ObjectLockAnchor::class,
                'bucket' => 'chronicle-worm-test',
                'prefix' => 'anchors',
                'mode' => 'COMPLIANCE',
                'retain_days' => 3650,
            ],
        ],
    ]);
});

it('anchors a checkpoint to S3 Object Lock and passes chronicle:verify --anchors', function () {
    // A self-consistent mock: PutObject captures whatever digest anchor() sends;
    // GetObject returns those same bytes under a valid lock. So the round-trip
    // succeeds without the test pre-computing the digest. makeMockS3Client() and
    // ref() come from tests/Pest.php (Task 1).
    $capturedDigest = null;
    $client = makeMockS3Client([
        // 1) PutObject from the post-commit AnchorCheckpointJob (sync queue).
        function (CommandInterface $cmd, RequestInterface $req) use (&$capturedDigest): Result {
            $capturedDigest = (string) $cmd['Body'];

            return new Result(['ETag' => '"etag-e2e"', 'VersionId' => 'ver-e2e']);
        },
        // 2) GetObject from chronicle:verify --anchors.
        function (CommandInterface $cmd, RequestInterface $req) use (&$capturedDigest): Result {
            return new Result([
                'Body' => Utils::streamFor((string) $capturedDigest),
                'ObjectLockMode' => 'COMPLIANCE',
                'ObjectLockRetainUntilDate' => new DateTimeResult('+10 years'),
                'ETag' => '"etag-e2e"',
            ]);
        },
    ]);

    // Bind the mock client so AnchorManager autowires it into S3ObjectLockAnchor.
    $this->app->instance(S3Client::class, $client);

    // Record + create a checkpoint -> the sync queue runs AnchorCheckpointJob ->
    // S3ObjectLockAnchor::anchor() PutObjects the digest (mock response 1).
    Chronicle::record()->actor(ref('a'))->action('a.one')->subject(ref('s'))->commit();
    app(CheckpointCreator::class)->create();

    // --anchors re-reads the locked object (mock response 2) and matches digest.
    $this->artisan('chronicle:verify', ['--checkpoints-only' => true, '--anchors' => true])
        ->assertSuccessful();
});
