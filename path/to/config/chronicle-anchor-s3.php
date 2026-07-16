<?php

return [
    'providers' => [
        's3-object-lock' => [
            'provider' => Chronicle\AnchorS3\S3ObjectLockAnchor::class,
            'bucket' => env('CHRONICLE_S3_ANCHOR_BUCKET'),
            'prefix' => 'chronicle/anchors',
            'mode' => 'GOVERNANCE',
            'retain_days' => 3650,
        ],
    ],
];