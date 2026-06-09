<?php

return [
    // Region used by the default S3Client binding. Per-provider config in
    // chronicle.anchoring.providers overrides bucket/mode/retention, not this.
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
];
