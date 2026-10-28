<?php

namespace Seisigma\AccessProfiles;

use Illuminate\Support\ServiceProvider;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Commands\CensusCommand;
use Seisigma\AccessProfiles\Contracts\ProfileConditions;
use Seisigma\AccessProfiles\Contracts\Resolver;
use Seisigma\AccessProfiles\Contracts\ScopeOwnership;
use Seisigma\AccessProfiles\Resolver\DatabaseResolver;
use Seisigma\AccessProfiles\Resolver\RequestMemo;
use Seisigma\AccessProfiles\Support\DefaultScopeOwnership;
use Seisigma\AccessProfiles\Support\NoConditions;

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
        $this->app->singleton(Catalogue::class, fn () => new Catalogue(config('access-profiles.catalogues')));
        $this->app->bind(ScopeOwnership::class, fn ($app) => $app->make(
            config('access-profiles.bindings.'.ScopeOwnership::class, DefaultScopeOwnership::class)
        ));
        $this->app->bind(ProfileConditions::class, fn ($app) => $app->make(
            config('access-profiles.bindings.'.ProfileConditions::class, NoConditions::class)
        ));
        $this->app->bind(Resolver::class, fn ($app) => $app->make(
            config('access-profiles.bindings.'.Resolver::class, DatabaseResolver::class)
        ));
        $this->app->scoped(RequestMemo::class);
    }

    public function boot(): void
    {
        $this->app->make(Catalogue::class);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([CensusCommand::class]);

        $this->publishes([
            __DIR__.'/../config/access-profiles.php' => config_path('access-profiles.php'),
        ], 'access-profiles-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_access_profiles_tables.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_access_profiles_tables.php'),
        ], 'access-profiles-migrations');
    }
}
