<?php

use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\TokenUser;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

beforeEach(function () {
    config()->set('access-profiles.cache.store', 'array');
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->sites = (new EloquentCollection(array_map(fn () => Site::create(['team_id' => $this->team->id]), range(1, 5))))->load('team');
    $this->site = $this->sites->first();
    $this->user = User::create();
    $this->viewer = Grants::profile('viewer', ['billing.invoice.view']);
    $this->queries = 0;
    DB::listen(function () {
        $this->queries++;
    });
    $this->countQueries = function (Closure $run): int {
        $this->queries = 0;
        $run();

        return $this->queries;
    };
    $this->entryKey = fn (int $version) => 'access-profiles:'.$this->team->getMorphClass().':'.$this->team->id
        .':'.$this->user->getMorphClass().':'.$this->user->id.':v'.$version;
});

afterEach(fn () => Carbon::setTestNow());

function fiftyChecks(User $user, $sites): void
{
    for ($i = 0; $i < 10; $i++) {
        foreach ($sites as $site) {
            HolderAccess::for($user)->can('billing.invoice.view', $site);
        }
    }
}

it('loads one holder and tenant from the database once for fifty checks in a request', function () {
    config()->set('access-profiles.cache.enabled', false);
    Grants::assign($this->user, $this->viewer, $this->team, [$this->sites[0], $this->sites[1]]);

    $cold = ($this->countQueries)(fn () => fiftyChecks($this->user, $this->sites));
    $memo = ($this->countQueries)(fn () => fiftyChecks($this->user, $this->sites));

    expect($cold)->toBe(3)
        ->and($memo)->toBe(0);
});

it('uses two queries when no assignment is scope-limited', function () {
    config()->set('access-profiles.cache.enabled', false);
    Grants::assign($this->user, $this->viewer, $this->team);

    expect(($this->countQueries)(fn () => fiftyChecks($this->user, $this->sites)))->toBe(2);
});

it('uses one query for a holder without assignments', function () {
    config()->set('access-profiles.cache.enabled', false);

    expect(($this->countQueries)(fn () => fiftyChecks($this->user, $this->sites)))->toBe(1);
});

it('flushes the memo between two octane-style requests', function () {
    config()->set('access-profiles.cache.enabled', false);
    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse();
    Grants::assign($this->user, $this->viewer, $this->team);
    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse();

    app()->forgetScopedInstances();

    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeTrue();
});

it('keeps one holders decisions apart across tenants in the same request', function (bool $allowedFirst) {
    config()->set('access-profiles.cache.enabled', false);
    $other = Team::create();
    Grants::assign($this->user, $this->viewer, $this->team);
    $access = HolderAccess::for($this->user);
    $tenants = $allowedFirst ? [$this->team, $other] : [$other, $this->team];
    $expected = $allowedFirst ? [true, false] : [false, true];

    foreach ($tenants as $index => $tenant) {
        expect($access->can('billing.invoice.view', $tenant))->toBe($expected[$index]);
    }

    expect($access->can('billing.invoice.view', $this->team))->toBeTrue()
        ->and($access->can('billing.invoice.view', $other))->toBeFalse();
})->with([
    'allowed tenant first' => [true],
    'unassigned tenant first' => [false],
]);

it('keeps same class holders decisions apart in the same tenant and request', function (bool $allowedFirst) {
    config()->set('access-profiles.cache.enabled', false);
    $other = User::create();
    Grants::assign($this->user, $this->viewer, $this->team);
    $allowed = HolderAccess::for($this->user);
    $unassigned = HolderAccess::for($other);
    $holders = $allowedFirst ? [$allowed, $unassigned] : [$unassigned, $allowed];
    $expected = $allowedFirst ? [true, false] : [false, true];

    foreach ($holders as $index => $access) {
        expect($access->can('billing.invoice.view', $this->team))->toBe($expected[$index]);
    }

    expect($allowed->can('billing.invoice.view', $this->team))->toBeTrue()
        ->and($unassigned->can('billing.invoice.view', $this->team))->toBeFalse();
})->with([
    'allowed holder first' => [true],
    'unassigned holder first' => [false],
]);

it('keys the memo by holder type as well as id', function () {
    config()->set('access-profiles.cache.enabled', false);
    Grants::assign($this->user, $this->viewer, $this->team);

    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->team))->toBeTrue()
        ->and(HolderAccess::for(TokenUser::find($this->user->id))->can('billing.invoice.view', $this->team))->toBeFalse();
});

it('keeps morph alias and holder id boundaries distinct in the same request', function (bool $allowedFirst) {
    config()->set('access-profiles.cache.enabled', false);
    $morphMap = Relation::morphMap();
    Relation::morphMap(['person1' => User::class, 'person' => TokenUser::class], false);

    try {
        $user = User::create(['id' => 2]);
        $other = TokenUser::create(['id' => 12]);
        Grants::assign($user, $this->viewer, $this->team);
        $allowed = HolderAccess::for($user);
        $unassigned = HolderAccess::for($other);
        $holders = $allowedFirst ? [$allowed, $unassigned] : [$unassigned, $allowed];
        $expected = $allowedFirst ? [true, false] : [false, true];

        foreach ($holders as $index => $access) {
            expect($access->can('billing.invoice.view', $this->team))->toBe($expected[$index]);
        }

        expect($allowed->can('billing.invoice.view', $this->team))->toBeTrue()
            ->and($unassigned->can('billing.invoice.view', $this->team))->toBeFalse();
    } finally {
        Relation::morphMap($morphMap, false);
    }
})->with([
    'allowed alias first' => [true],
    'unassigned alias first' => [false],
]);

it('meets the query and cache budget cold, warm and on a memo hit', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->sites[0]]);
    // The counter already exists in a live store; its first-ever init is TenantVersion's cost.
    app(TenantVersion::class)->current($this->team);
    $cacheCalls = 0;
    Event::listen([RetrievingKey::class, WritingKey::class], function () use (&$cacheCalls) {
        $cacheCalls++;
    });

    $cold = ($this->countQueries)(fn () => fiftyChecks($this->user, $this->sites));
    $coldCache = $cacheCalls;
    $cacheCalls = 0;
    $memo = ($this->countQueries)(fn () => fiftyChecks($this->user, $this->sites));
    $memoCache = $cacheCalls;

    app()->forgetScopedInstances();
    $cacheCalls = 0;
    $warm = ($this->countQueries)(fn () => fiftyChecks($this->user, $this->sites));

    expect($cold)->toBeLessThanOrEqual(3)
        ->and($coldCache)->toBe(3)
        ->and($memo)->toBe(0)
        ->and($memoCache)->toBe(0)
        ->and($warm)->toBe(0)
        ->and($cacheCalls)->toBe(2);
});

it('keeps serving the cached grant set until the tenant version is bumped', function () {
    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse();
    Grants::assign($this->user, $this->viewer, $this->team);

    app()->forgetScopedInstances();
    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse();

    app(TenantVersion::class)->bump($this->team);
    app()->forgetScopedInstances();
    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeTrue();
});

it('sees a revoke in the same request once the version is bumped', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);
    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeTrue();

    $assignment->update(['revoked_at' => now()]);
    app(TenantVersion::class)->bump($this->team);

    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse();
});

it('keeps the memo of other tenants when one tenant is bumped', function () {
    $other = Team::create();
    Grants::assign($this->user, $this->viewer, $other);
    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $other))->toBeTrue();

    app(TenantVersion::class)->bump($this->team);

    expect(($this->countQueries)(fn () => HolderAccess::for($this->user)->can('billing.invoice.view', $other)))->toBe(0);
});

it('caches under the versioned key with the configured ttl', function () {
    config()->set('access-profiles.cache.ttl', 600);
    Grants::assign($this->user, $this->viewer, $this->team);
    HolderAccess::for($this->user)->can('billing.invoice.view', $this->site);

    expect(Cache::store('array')->get(($this->entryKey)(1)))->toBeArray();
    Carbon::setTestNow('2026-10-28 12:09:59');
    expect(Cache::store('array')->get(($this->entryKey)(1)))->toBeArray();
    Carbon::setTestNow('2026-10-28 12:10:00');
    expect(Cache::store('array')->get(($this->entryKey)(1)))->toBeNull();
});

it('caps the ttl at the next validity boundary', function (array $window) {
    config()->set('access-profiles.cache.ttl', 3600);
    Grants::assign($this->user, $this->viewer, $this->team, null, $window);
    Grants::assign($this->user, Grants::profile('other', ['billing.invoice.approve']), $this->team, null, ['valid_until' => '2026-10-28 12:30:00']);
    HolderAccess::for($this->user)->can('billing.invoice.view', $this->site);

    Carbon::setTestNow('2026-10-28 12:04:59');
    expect(Cache::store('array')->get(($this->entryKey)(1)))->toBeArray();
    Carbon::setTestNow('2026-10-28 12:05:00');
    expect(Cache::store('array')->get(($this->entryKey)(1)))->toBeNull();
})->with([
    'next valid_from' => [['valid_from' => '2026-10-28 12:05:00']],
    'next valid_until' => [['valid_until' => '2026-10-28 12:05:00']],
]);

it('ignores past boundaries when it caps the ttl', function () {
    config()->set('access-profiles.cache.ttl', 600);
    Grants::assign($this->user, $this->viewer, $this->team, null, ['valid_from' => '2026-10-28 11:00:00']);
    HolderAccess::for($this->user)->can('billing.invoice.view', $this->site);

    Carbon::setTestNow('2026-10-28 12:09:59');
    expect(Cache::store('array')->get(($this->entryKey)(1)))->toBeArray();
});

it('does not cap the ttl at a boundary that is exactly now', function () {
    config()->set('access-profiles.cache.ttl', 600);
    Grants::assign($this->user, $this->viewer, $this->team, null, ['valid_from' => '2026-10-28 12:00:00']);
    HolderAccess::for($this->user)->can('billing.invoice.view', $this->site);

    Carbon::setTestNow('2026-10-28 12:09:59');
    expect(Cache::store('array')->get(($this->entryKey)(1)))->toBeArray();
});

it('stays correct with the cache disabled and writes nothing to it', function () {
    config()->set('access-profiles.cache.enabled', false);
    $writes = 0;
    Event::listen([RetrievingKey::class, WritingKey::class], function () use (&$writes) {
        $writes++;
    });
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeTrue()
        ->and(HolderAccess::for($this->user)->can('billing.invoice.view', $this->sites[1]))->toBeFalse()
        ->and($writes)->toBe(0);
});

it('rebuilds a cached grant set with the same decisions', function () {
    $deny = Grants::profile('blocked', [], ['billing.invoice.view'], null, null);
    Grants::assign($this->user, $this->viewer, $this->team, [$this->sites[0], $this->sites[1]], ['valid_until' => '2026-10-28 18:00:00']);
    Grants::assign($this->user, $deny, $this->team, [$this->sites[1]]);
    $cold = $this->sites->map(fn ($site) => HolderAccess::for($this->user)->can('billing.invoice.view', $site))->all();

    app()->forgetScopedInstances();
    $warm = null;
    $queries = ($this->countQueries)(function () use (&$warm) {
        $warm = $this->sites->map(fn ($site) => HolderAccess::for($this->user)->can('billing.invoice.view', $site))->all();
    });
    // The memo now holds the set rebuilt from the cache; its window must still apply.
    Carbon::setTestNow('2026-10-28 18:00:00');

    expect($cold)->toBe([true, false, false, false, false])
        ->and($warm)->toBe($cold)
        ->and($queries)->toBe(0)
        ->and(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse();
});
