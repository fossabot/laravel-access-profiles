<?php

namespace Seisigma\AccessProfiles\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Seisigma\AccessProfiles\AccessProfilesServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            AccessProfilesServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }

    protected function runPackageMigration(): void
    {
        (include __DIR__.'/../database/migrations/create_access_profiles_tables.php.stub')->up();
    }
}
