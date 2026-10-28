<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Seisigma\AccessProfiles\Contracts\ProfileConditions;
use Seisigma\AccessProfiles\Facades\Access;
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
    Carbon::setTestNow('2026-10-28 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('matches the resolver across the S5 decision matrix', function (string $scenario) {
    $team = Team::create();
    $site = Site::create(['team_id' => $team->id]);
    $otherSite = Site::create(['team_id' => $team->id]);
    $otherTeam = Team::create();
    $user = TokenUser::create();
    $view = 'billing.invoice.view';
    $approve = 'billing.invoice.approve';
    $attributes = match ($scenario) {
        'revoked' => ['revoked_at' => now()],
        'future' => ['valid_from' => now()->addSecond()],
        'starts now' => ['valid_from' => now()],
        'ends now' => ['valid_until' => now()],
        'ends later' => ['valid_until' => now()->addSecond()],
        default => [],
    };
    $profile = Grants::profile('viewer', [$view], [$approve], null,
        str_starts_with($scenario, 'condition') ? 'review_enabled' : null);

    if ($scenario !== 'no assignments') {
        Grants::assign($user, $profile, $scenario === 'other tenant' ? $otherTeam : $team,
            $scenario === 'scoped' ? [$site] : null, $attributes);
    }
    if ($scenario === 'deny wins') {
        Grants::assign($user, Grants::profile('blocked', [], [$view]), $team, [$site]);
    }
    if ($scenario === 'condition on') {
        app()->instance(ProfileConditions::class, new class implements ProfileConditions
        {
            public function isOn(string $condition, Model $tenant): bool
            {
                return true;
            }
        });
    }
    if ($scenario === 'token limited') {
        $user->accessToken = PersonalToken::allowing([]);
    }
    if ($scenario === 'token allowed') {
        $user->accessToken = PersonalToken::allowing([$view]);
    }

    foreach ([$view, $approve, 'billing.report.view'] as $key) {
        foreach ([$team, $site, $otherSite, $otherTeam] as $target) {
            expect($user->canAccess($key, $target))->toBe(Access::for($user)->can($key, $target));
        }
    }
})->with([
    'no assignments', 'wide', 'scoped', 'deny wins', 'revoked', 'future',
    'starts now', 'ends now', 'ends later', 'condition off', 'condition on',
    'other tenant', 'token limited', 'token allowed',
]);

it('keeps Laravel Gate independent of access profiles', function () {
    $user = User::create();
    $team = Team::create();
    $site = Site::create(['team_id' => $team->id]);
    $key = 'billing.invoice.approve';
    Grants::assign($user, Grants::profile('approver', [$key]), $team);

    expect(Gate::has($key))->toBeFalse()
        ->and($user->canAccess($key, $site))->toBeTrue()
        ->and($user->can($key, $site))->toBeFalse();
});

it('returns only the holders assignments including history across tenants', function () {
    Relation::morphMap(['person' => User::class, 'token_person' => TokenUser::class]);
    $user = User::create();
    $other = User::create();
    $sameIdDifferentType = TokenUser::findOrFail($user->id);
    $team = Team::create();
    $profile = Grants::profile('viewer', ['billing.invoice.view']);
    $active = Grants::assign($user, $profile, $team);
    $revoked = Grants::assign($user, $profile, Team::create(), null, ['revoked_at' => now()]);
    $expired = Grants::assign($user, $profile, $team, null, ['valid_until' => now()->subSecond()]);
    Grants::assign($other, $profile, $team);
    Grants::assign($sameIdDifferentType, $profile, $team);

    expect($user->accessAssignments()->orderBy('id')->get()->modelKeys())
        ->toBe([$active->id, $revoked->id, $expired->id]);
});
