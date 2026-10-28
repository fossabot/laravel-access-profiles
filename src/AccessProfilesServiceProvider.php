<?php

namespace Seisigma\AccessProfiles;

use Illuminate\Support\ServiceProvider;

/**
 * Plain service provider on purpose: the package keeps its runtime
 * dependencies to php + illuminate/*, so it does not use
 * spatie/laravel-package-tools like the skeleton it is modelled on.
 */
class AccessProfilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/access-profiles.php', 'access-profiles');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/access-profiles.php' => config_path('access-profiles.php'),
        ], 'access-profiles-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_access_profiles_tables.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_access_profiles_tables.php'),
        ], 'access-profiles-migrations');
    }
}
