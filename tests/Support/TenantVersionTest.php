<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;

it('starts at one and bumps without resetting an existing version', function () {
    $versions = app(TenantVersion::class);
    $team = new Team(['id' => 1]);
    expect($versions->current($team))->toBe(1)
        ->and($versions->bump($team))->toBe(2)
        ->and($versions->current($team))->toBe(2)
        ->and($versions->bump($team))->toBe(3)
        ->and($versions->current($team))->toBe(3);
});

it('initializes a bump on a cold store and isolates morph identities', function () {
    $versions = app(TenantVersion::class);
    expect($versions->bump(new Team(['id' => 1])))->toBe(2)
        ->and($versions->current(new Team(['id' => 2])))->toBe(1)
        ->and($versions->current(new Site(['id' => 1])))->toBe(1);
});

it('uses the configured store and prefix with an atomic increment', function () {
    config()->set('access-profiles.cache.store', 'array');
    config()->set('access-profiles.cache.prefix', 'custom');
    $team = new Team(['id' => 1]);
    $key = 'custom:version:'.$team->getMorphClass().':1';
    $store = Mockery::mock(Repository::class);
    Cache::shouldReceive('store')->with('array')->once()->andReturn($store);
    $store->shouldReceive('add')->with($key, 1)->once()->andReturn(false);
    $store->shouldReceive('increment')->with($key)->once()->andReturn(9);
    expect(app(TenantVersion::class)->bump($team))->toBe(9);
});

it('restarts after a store flush with no stale grant entry available', function () {
    config()->set('access-profiles.cache.store', 'array');
    $versions = app(TenantVersion::class);
    $team = new Team(['id' => 1]);
    $old = $versions->bump($team);
    $entry = 'access-profiles:'.$team->getMorphClass().':1:user:7:v'.$old;
    Cache::store('array')->put($entry, ['stale'], 60);
    Cache::store('array')->flush();
    expect($versions->current($team))->toBe(1)
        ->and(Cache::store('array')->get($entry))->toBeNull()
        ->and($versions->bump($team))->toBe(2);
});

it('reads the configured counter and keeps it beyond the grants ttl', function () {
    config()->set('access-profiles.cache.store', 'array');
    config()->set('access-profiles.cache.prefix', 'custom');
    $team = new Team(['id' => 1]);
    Cache::store('array')->forever('custom:version:'.$team->getMorphClass().':1', 8);
    expect(app(TenantVersion::class)->current($team))->toBe(8);
    $this->travel(2)->days();
    expect(app(TenantVersion::class)->current($team))->toBe(8);
});

it('reads the winning counter when another writer initializes first', function () {
    $team = new Team(['id' => 1]);
    $key = 'access-profiles:version:'.$team->getMorphClass().':1';
    $store = Mockery::mock(Repository::class);
    Cache::shouldReceive('store')->with(null)->once()->andReturn($store);
    $store->shouldReceive('get')->with($key)->twice()->andReturn(null, 7);
    $store->shouldReceive('add')->with($key, 1)->once()->andReturn(false);
    expect(app(TenantVersion::class)->current($team))->toBe(7);
});
