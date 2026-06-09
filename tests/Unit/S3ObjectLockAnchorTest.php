<?php

use Chronicle\AnchorS3\S3ObjectLockAnchor;

it('name() returns s3-object-lock', function () {
    $anchor = new S3ObjectLockAnchor(['bucket' => 'b'], makeMockS3Client());

    expect($anchor->name())->toBe('s3-object-lock');
});

it('throws when bucket is missing from config', function () {
    new S3ObjectLockAnchor([], makeMockS3Client());
})->throws(InvalidArgumentException::class, 'bucket');
