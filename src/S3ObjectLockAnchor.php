<?php

namespace Chronicle\AnchorS3;

use Aws\S3\S3Client;
use Chronicle\Anchoring\AnchorReceipt;
use Chronicle\Anchoring\CheckpointDigest;
use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Contracts\AnchorProvider;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Anchors a checkpoint by writing its digest to an S3 Object Lock (WORM) object
 * in an independent trust domain. anchor() PubObjects the digest under a
 * COMPLIANCE/GOVERNANCE retention; verify() re-reads that exact object version
 * and confirms the stored bytes equal the recomputed digest AND that lock
 * metadata is present - so a rewritten + re-signed checkpoint (which passes
 * offline verification) still FAILS --anchors, because its recomputed digest no
 * longer matches the immutable object.
 *
 * Config keys (in chronicle.anchoring.providers.<name>):
 *    bucket       - Object-Lock-enabled bucket (required)
 *    prefix       - key prefix (optional, default 'chronicle/anchors')
 *    mode         - 'COMPLIANCE' (default) or 'GOVERNANCE'
 *    retain_days  - retention in days (optional, default 3650)
 */
class S3ObjectLockAnchor implements AnchorProvider
{
    protected string $bucket;

    protected string $prefix;

    /** @var 'COMPLIANCE'|'GOVERNANCE' */
    protected string $mode;

    protected int $retainDays;

    protected S3Client $s3;

    /**
     * @param  array{bucket?: string|null, prefix?: string|null, mode?: string|null, retain_days?: int|null}  $config
     */
    public function __construct(array $config, S3Client $client)
    {
        $bucket = $config['bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            throw new InvalidArgumentException(
                'Missing bucket for S3ObjectLockAnchor - provide an Object-Lock-enabled bucket name.'
            );
        }

        $prefix = $config['prefix'] ?? null;
        $mode = $config['mode'] ?? null;
        $retain = $config['retain_days'] ?? null;

        $this->bucket = $bucket;
        $this->prefix = is_string($prefix) && $prefix !== '' ? trim($prefix, '/') : 'chronicle/anchors';
        $this->mode = $mode === 'GOVERNANCE' ? 'GOVERNANCE' : 'COMPLIANCE';
        $this->retainDays = is_int($retain) && $retain > 0 ? $retain : 3650;
        $this->s3 = $client;
    }

    public function name(): string
    {
        return 's3-object-lock';
    }

    public function anchor(Checkpoint $checkpoint): AnchorReceipt
    {
        $digest = CheckpointDigest::for($checkpoint);
        $key = $this->objectKey($checkpoint);
        $retainUntil = now()->addDays($this->retainDays)->toImmutable();

        $result = $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $digest,
            'ContentType' => 'text/plain',
            'ObjectLockMode' => $this->mode,
            'ObjectLockRetainUntilDate' => $retainUntil->format(DATE_ATOM),
        ]);

        $etag = $result['ETag'] ?? null;
        $versionId = $result['VersionId'] ?? null;

        if (! is_string($etag) || ! is_string($versionId)) {
            throw new RuntimeException('S3 PutObject did not return an ETag and VersionId (is Object Lock + versioning enabled on the bucket?).');
        }

        return new AnchorReceipt(
            provider: $this->name(),
            reference: "$this->bucket/$key@$versionId",
            proof: $etag,
            anchoredAt: now()->toImmutable(),
        );
    }

    public function verify(Checkpoint $checkpoint, AnchorReceipt $receipt): bool
    {
        throw new LogicException('verify() implemented in Task 4.');
    }

    protected function objectKey(Checkpoint $checkpoint): string
    {
        return $this->prefix.'/'.$checkpoint->id.'.digest';
    }
}
