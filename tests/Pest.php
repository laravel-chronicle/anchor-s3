<?php

use Aws\MockHandler;
use Aws\S3\S3Client;
use Chronicle\AnchorS3\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extends(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__);

/**
 * Plain object reference for use as an actor / subject (core's
 * DefaultReferenceResolver resolves any object with a public $id).
 */
function ref(string $id): object
{
    $obj = new stdClass;
    $obj->id = $id;

    return $obj;
}

/**
 * S3Client backed by a MockHandler. Each queue item is an Aws\Result, an
 * exception, or a callable(CommandInterface $cmd, RequestInterface $req): Result
 * (the callable form lets a test assert on the outgoing command).
 *
 * @param  list<mixed>  $queue
 */
function makeMockS3Client(array $queue = []): S3Client
{
    $mock = new MockHandler;
    foreach ($queue as $item) {
        $mock->append($item);
    }

    return new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'],
        'handler' => $mock,
    ]);
}
