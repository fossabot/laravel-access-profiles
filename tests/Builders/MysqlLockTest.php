<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Rejection;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

/*
 * Database lane (plan §2): runs only when ACCESS_PROFILES_MYSQL_DATABASE names
 * an empty MySQL 8 database it may drop and create tables in. Other settings:
 * ACCESS_PROFILES_MYSQL_HOST (127.0.0.1), _PORT (3306), _USERNAME (root), _PASSWORD ('').
 */
beforeEach(function () {
    $database = getenv('ACCESS_PROFILES_MYSQL_DATABASE');

    if (! is_string($database) || $database === '') {
        $this->markTestSkipped('No MySQL lane: set ACCESS_PROFILES_MYSQL_DATABASE.');
    }

    $connection = [
        'driver' => 'mysql',
        'host' => getenv('ACCESS_PROFILES_MYSQL_HOST') ?: '127.0.0.1',
        'port' => getenv('ACCESS_PROFILES_MYSQL_PORT') ?: '3306',
        'database' => $database,
        'username' => getenv('ACCESS_PROFILES_MYSQL_USERNAME') ?: 'root',
        'password' => getenv('ACCESS_PROFILES_MYSQL_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];
    config()->set('database.connections.lane_a', $connection);
    config()->set('database.connections.lane_b', $connection);
    config()->set('database.default', 'lane_a');
    config()->set('access-profiles.cache.store', 'array');
    config()->set('access-profiles.guards.protected_profiles', ['owner']);

    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->down();
    (include __DIR__.'/../../database/migrations/create_access_profiles_tables.php.stub')->down();
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
})->group('mysql');

afterEach(function () {
    Carbon::setTestNow();

    if (config('database.connections.lane_b') !== null && DB::connection('lane_b')->transactionLevel() > 0) {
        DB::connection('lane_b')->rollBack();
    }
});

it('makes a second revoke wait for the first and then see the last holder', function () {
    $team = Team::create();
    $admin = User::create();
    $owner = Grants::profile('owner', ['billing.invoice.view']);
    Grants::assign($admin, Grants::profile('full', ['billing.invoice.view']), $team);
    $first = Grants::assign(User::create(), $owner, $team);
    $second = Grants::assign(User::create(), $owner, $team);

    // Connection B plays the first revoke: it holds the row locks and has not committed.
    $other = DB::connection('lane_b');
    $other->beginTransaction();
    $other->table('access_assignments')->where('profile_id', $owner->id)->whereNull('revoked_at')->lockForUpdate()->pluck('id');
    $other->table('access_assignments')->where('id', $first->id)->update(['revoked_at' => now()]);

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    $waited = false;

    try {
        Access::revoke($second)->by($admin)->save();
    } catch (QueryException) {
        $waited = true;
    }

    $other->commit();

    expect($waited)->toBeTrue()
        ->and(Rejection::of(fn () => Access::revoke($second)->by($admin)->save()))->toBe(GrantRejected::LAST_HOLDER);
})->group('mysql');
