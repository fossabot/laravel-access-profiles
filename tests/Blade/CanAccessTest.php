<?php

use Illuminate\Support\Facades\Blade;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

beforeEach(function () {
    config()->set('access-profiles.cache.enabled', false);
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
});

it('renders the block only for an authenticated holder allowed on the target', function (string $state) {
    $team = Team::create();
    $site = Site::create(['team_id' => $team->id]);
    $otherSite = Site::create(['team_id' => $team->id]);
    $user = User::create();
    $key = 'billing.invoice.approve';
    Grants::assign($user, Grants::profile('approver', [$key]), $team, [$site]);

    if ($state !== 'guest') {
        $this->actingAs($user);
    }

    $rendered = Blade::render('before @canAccess($key, $target)allowed @endcanAccess after', [
        'key' => $key,
        'target' => $state === 'denied' ? $otherSite : $site,
    ]);

    expect($rendered)->toBe($state === 'allowed' ? 'before allowed  after' : 'before  after');
})->with(['allowed', 'denied', 'guest']);
