<?php

namespace Chronicle\AnchorS3;

use Aws\S3\S3Client;
use Illuminate\Support\ServiceProvider;

/**
 * Registers a default S3Client singleton resolved from environment variables.
 * S3ObjectLockAnchor receives this client via the container (AnchorManager
 * resolves the provider with makeWith(..., ['config' => ...]) and autowires the
 * S3Client argument).
 *
 * Environment variables:
 *   AWS_DEFAULT_REGION    - S3 region (e.g. 'eu-west-1')
 *   AWS_ACCESS_KEY_ID     - AWS credentials (omit when using EC2/ECS IAM roles)
 *   AWS_SECRET_ACCESS_KEY
 *
 * To override (custom credentials/endpoint), re-bind Aws\S3\S3Client in your
 * application's AppServiceProvider.
 */
class AnchorS3ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chronicle-anchor-s3.php', 'chronicle-anchor-s3');

        $this->app->singleton(S3Client::class, function (): S3Client {
            /** @var string $region */
            $region = config('chronicle-anchor-s3.region', 'us-east-1');

            return new S3Client([
                'region' => $region,
                'version' => 'latest',
            ]);
        });
    }
}
