<?php

use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\Resolver\Decision;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\PersonalToken;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\TokenUser;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

beforeEach(function () {
    config()->set('access-profiles.cache.enabled', false);
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    $this->team = Team::create();
    $this->site = Site::create(['team_id' => $this->team->id]);
    $this->user = TokenUser::create();
    Grants::assign($this->user, Grants::profile('viewer', ['billing.invoice.view', 'billing.invoice.approve']), $this->team);
});

it('requires the stored token to allow the key as well', function () {
    $this->user->accessToken = PersonalToken::allowing(['billing.invoice.view']);
    $access = HolderAccess::for($this->user);

    expect($access->can('billing.invoice.view', $this->site))->toBeTrue()
        ->and($access->can('billing.invoice.approve', $this->site))->toBeFalse()
        ->and($access->explain('billing.invoice.approve', $this->site)->reason)->toBe(Decision::TOKEN)
        ->and($access->permissionsIn($this->site))->toBe(['billing.invoice.view']);
});

it('never widens access through the token', function () {
    $this->user->accessToken = PersonalToken::allowing(['billingx.invoice.view']);

    expect(HolderAccess::for($this->user)->can('billingx.invoice.view', $this->site))->toBeFalse()
        ->and(HolderAccess::for($this->user)->explain('billingx.invoice.view', $this->site)->reason)->toBe(Decision::NO_MATCH);
});

it('leaves a transient token or no token unaffected', function (?object $token) {
    $this->user->accessToken = $token;

    expect(HolderAccess::for($this->user)->can('billing.invoice.approve', $this->site))->toBeTrue();
})->with([
    'no token' => [null],
    'transient token' => [new class
    {
        public function can(string $ability): bool
        {
            return false;
        }
    }],
]);

it('leaves a holder without token methods unaffected', function () {
    $plain = User::create();
    Grants::assign($plain, Grants::profile('reader', ['billing.invoice.view']), $this->team);

    expect(HolderAccess::for($plain)->can('billing.invoice.view', $this->site))->toBeTrue();
});

it('skips the token check when token intersection is off', function () {
    config()->set('access-profiles.token_intersection', false);
    $this->user->accessToken = PersonalToken::allowing([]);

    expect(HolderAccess::for($this->user)->can('billing.invoice.view', $this->site))->toBeTrue();
});
