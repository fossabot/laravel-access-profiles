<?php

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\HasAccessTenant;
use Seisigma\AccessProfiles\Contracts\ScopeOwnership;
use Seisigma\AccessProfiles\Exceptions\UnknownAccessTenant;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

it('resolves a tenant to itself and a scope to its tenant', function () {
    $team = new Team(['id' => 1]);
    $site = new Site(['id' => 2]);
    $site->setRelation('team', $team);
    $ownership = app(ScopeOwnership::class);
    expect($ownership->tenantOf($team))->toBe($team)
        ->and($ownership->tenantOf($site))->toBe($team)
        ->and($team->is($team->accessTenant()))->toBeTrue()
        ->and($site->is($site->accessTenant()))->toBeFalse();
});

it('rejects a target with no tenant contract naming its class', function () {
    app(ScopeOwnership::class)->tenantOf(new User);
})->throws(UnknownAccessTenant::class, 'Cannot resolve access tenant for '.User::class.': implement '.HasAccessTenant::class);

it('honors the configured ownership implementation', function () {
    $custom = new class implements ScopeOwnership
    {
        public function tenantOf(Model $target): Model
        {
            return $target;
        }
    };
    config()->set('access-profiles.bindings', [ScopeOwnership::class => $custom::class]);
    $user = new User;
    expect(app(ScopeOwnership::class)->tenantOf($user))->toBe($user);
});
