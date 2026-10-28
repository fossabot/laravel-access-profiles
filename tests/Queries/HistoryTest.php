<?php

use Illuminate\Support\Carbon;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Queries\History;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(History::class);

beforeEach(function () {
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->site = Site::create(['team_id' => $this->team->id]);
    $this->user = User::create();
    $this->viewer = Grants::profile('viewer', ['billing.invoice.view']);
});

afterEach(fn () => Carbon::setTestNow());

it('returns every row of the holder in the tenant, revoked and expired included, oldest first', function () {
    $active = Grants::assign($this->user, $this->viewer, $this->team);
    $revoked = Grants::assign($this->user, $this->viewer, $this->team, [$this->site], ['revoked_at' => '2026-10-01 09:00:00']);
    $expired = Grants::assign($this->user, $this->viewer, $this->team, null, ['valid_until' => '2026-10-28 11:00:00']);
    $future = Grants::assign($this->user, $this->viewer, $this->team, null, ['valid_from' => '2026-11-01 00:00:00']);

    $history = Access::history($this->user, $this->team);

    expect($history->modelKeys())->toBe([$active->id, $revoked->id, $expired->id, $future->id])
        ->and($history->first())->toBeInstanceOf(Assignment::class)
        ->and($history[1]->revoked_at?->toDateTimeString())->toBe('2026-10-01 09:00:00');
});

it('orders by grant time, then by id', function () {
    $later = Grants::assign($this->user, $this->viewer, $this->team, null, ['created_at' => '2026-10-20 00:00:00']);
    $earlier = Grants::assign($this->user, $this->viewer, $this->team, null, ['created_at' => '2026-10-10 00:00:00']);
    $sameTimeA = Grants::assign($this->user, $this->viewer, $this->team, null, ['created_at' => '2026-10-15 00:00:00']);
    $sameTimeB = Grants::assign($this->user, $this->viewer, $this->team, null, ['created_at' => '2026-10-15 00:00:00']);

    expect(Access::history($this->user, $this->team)->modelKeys())
        ->toBe([$earlier->id, $sameTimeA->id, $sameTimeB->id, $later->id]);
});

it('leaves out other holders and other tenants', function () {
    $own = Grants::assign($this->user, $this->viewer, $this->team);
    Grants::assign(User::create(), $this->viewer, $this->team);
    Grants::assign($this->user, $this->viewer, Team::create());
    Grants::assign($this->site, $this->viewer, $this->team);

    expect(Access::history($this->user, $this->team)->modelKeys())->toBe([$own->id]);
});

it('loads the profile, even a soft-deleted one, and the scopes', function () {
    $gone = Grants::profile('gone', ['billing.invoice.view']);
    Grants::assign($this->user, $gone, $this->team, [$this->site]);
    $gone->delete();

    $row = Access::history($this->user, $this->team)->first();

    expect($row->relationLoaded('profile'))->toBeTrue()
        ->and($row->profile?->key)->toBe('gone')
        ->and($row->relationLoaded('scopes'))->toBeTrue()
        ->and($row->scopes->pluck('scope_id')->all())->toBe([$this->site->id]);
});

it('takes the tenant of a scope', function () {
    $own = Grants::assign($this->user, $this->viewer, $this->team);

    expect(Access::history($this->user, $this->site)->modelKeys())->toBe([$own->id]);
});
