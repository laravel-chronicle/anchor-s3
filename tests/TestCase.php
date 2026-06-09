<?php

namespace Chronicle\AnchorS3\Tests;

use Chronicle\AnchorS3\AnchorS3ServiceProvider;
use Chronicle\ChronicleServiceProvider;
use Chronicle\Storage\DatabaseDriver;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ChronicleServiceProvider::class, AnchorS3ServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        // Core ships the chronicle_entries / chronicle_checkpoints /
        // chronicle_checkpoint_anchors migrations; load them from the symlinked
        // path dependency. Verify the path: `ls vendor/laravel-chronicle/core/database/migrations`.
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel-chronicle/core/database/migrations');
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'testing');
        config()->set('chronicle.connection', 'testing');

        // Core dev signing keypair (same vectors as core's TestCase) so
        // checkpoints can be signed/verified.
        config()->set('chronicle.signing.keys.chronicle-dev-key.private_key', 'RcSfC2MuYTPnkrL/MIA4/l/sAjirGXXIFXZEPokdwh1Lcz+SvNE7bjvgCsDotjnlHfJyZ4XW/kUXemtoyaa92Q==');
        config()->set('chronicle.signing.keys.chronicle-dev-key.public_key', 'S3M/krzRO2474ArA6LY55R3ycmeF1v5FF3praMmmvdk=');
    }

    protected function useEloquentDriver(): void
    {
        config(['chronicle.driver' => 'eloquent']);
        app('chronicle')->swapDriver(new DatabaseDriver);
    }
}
