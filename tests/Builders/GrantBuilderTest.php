<?php

use Illuminate\Support\Carbon;
use Seisigma\AccessProfiles\AccessManager;
use Seisigma\AccessProfiles\Builders\AssignmentBuilder;
use Seisigma\AccessProfiles\Builders\GrantBuilder;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\AssignmentScope;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Rejection;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(AccessManager::class, AssignmentBuilder::class, GrantBuilder::class, GrantRejected::class);

beforeEach(function () {
    config()->set('access-profiles.cache.store', 'array');
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->site = Site::create(['team_id' => $this->team->id]);
    $this->otherSite = Site::create(['team_id' => $this->team->id]);
    $this->admin = User::create();
    $this->user = User::create();
    $this->full = Grants::profile('full', ['billing.invoice.view', 'billing.invoice.approve']);
    $this->viewer = Grants::profile('viewer', ['billing.invoice.view']);
    Grants::assign($this->admin, $this->full, $this->team);
});

afterEach(fn () => Carbon::setTestNow());

it('saves an assignment with every field the builder was given', function () {
    $assignment = Access::grant('viewer')
        ->to($this->user)
        ->in($this->team)
        ->onlyScopes([$this->site, $this->otherSite])
        ->titled('Board treasurer')
        ->validFrom(now()->subDay())
        ->validUntil(now()->addMonth())
        ->by($this->admin)
        ->because('Elected')
        ->save();

    $row = Assignment::findOrFail($assignment->id);
    expect($row->holder->is($this->user))->toBeTrue()
        ->and($row->tenant->is($this->team))->toBeTrue()
        ->and($row->grantedBy->is($this->admin))->toBeTrue()
        ->and($row->profile_id)->toBe($this->viewer->id)
        ->and($row->covers_tenant)->toBeFalse()
        ->and($row->title)->toBe('Board treasurer')
        ->and($row->reason)->toBe('Elected')
        ->and($row->valid_from->toDateTimeString())->toBe('2026-10-27 12:00:00')
        ->and($row->valid_until->toDateTimeString())->toBe('2026-11-28 12:00:00')
        ->and($row->revoked_at)->toBeNull()
        ->and(AssignmentScope::where('assignment_id', $row->id)->orderBy('id')->get()
            ->map(fn (AssignmentScope $scope) => [$scope->scope_type, $scope->scope_id])->all())
        ->toBe([[$this->site->getMorphClass(), $this->site->id], [$this->otherSite->getMorphClass(), $this->otherSite->id]]);
});

it('covers the whole tenant when no scopes are given', function () {
    $assignment = Access::grant('viewer')->to($this->user)->in($this->team)->by($this->admin)->save();

    expect($assignment->fresh()->covers_tenant)->toBeTrue()
        ->and(AssignmentScope::count())->toBe(0)
        ->and(Access::for($this->user)->can('billing.invoice.view', $this->team))->toBeTrue();
});

it('gives access the resolver sees at once, only in the listed scopes', function () {
    expect(Access::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse();

    Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([$this->site])->by($this->admin)->save();

    expect(Access::for($this->user)->can('billing.invoice.view', $this->site))->toBeTrue()
        ->and(Access::for($this->user)->can('billing.invoice.view', $this->otherSite))->toBeFalse();
});

it('accepts a profile model as well as a key', function () {
    $assignment = Access::grant($this->viewer)->to($this->user)->in($this->team)->by($this->admin)->save();

    expect($assignment->profile_id)->toBe($this->viewer->id);
});

it('resolves the tenant copy of a key before the template', function () {
    $copy = Grants::profile('viewer', ['billing.invoice.view'], tenant: $this->team);

    $assignment = Access::grant('viewer')->to($this->user)->in($this->team)->by($this->admin)->save();

    expect($assignment->profile_id)->toBe($copy->id);
});

it('falls back to the template when this tenant has no copy', function () {
    Grants::profile('viewer', ['billing.invoice.view'], tenant: Team::create());

    $assignment = Access::grant('viewer')->to($this->user)->in($this->team)->by($this->admin)->save();

    expect($assignment->profile_id)->toBe($this->viewer->id);
});

it('never uses the tenant copy of another tenant', function () {
    Grants::profile('private', ['billing.invoice.view'], tenant: Team::create());

    expect(Rejection::of(fn () => Access::grant('private')->to($this->user)->in($this->team)->by($this->admin)->save()))
        ->toBe(GrantRejected::UNKNOWN_PROFILE);
});

it('rejects a profile model that belongs to another tenant', function () {
    $foreign = Grants::profile('foreign', ['billing.invoice.view'], tenant: Team::create());

    expect(Rejection::of(fn () => Access::grant($foreign)->to($this->user)->in($this->team)->by($this->admin)->save()))
        ->toBe(GrantRejected::PROFILE_OUTSIDE_TENANT);
});

it('does not grant a soft-deleted profile by key', function () {
    $this->viewer->delete();

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->by($this->admin)->save()))
        ->toBe(GrantRejected::UNKNOWN_PROFILE);
});

it('stores the version of the profile it grants', function () {
    $this->viewer->update(['version' => 3]);

    $assignment = Access::grant('viewer')->to($this->user)->in($this->team)->by($this->admin)->save();

    expect($assignment->fresh()->profile_version)->toBe(3);
});

it('bumps the tenant version on save', function () {
    $versions = app(TenantVersion::class);
    $before = $versions->current($this->team);

    Access::grant('viewer')->to($this->user)->in($this->team)->by($this->admin)->save();

    expect($versions->current($this->team))->toBe($before + 1);
});

it('writes nothing and keeps the version when it rejects', function () {
    $versions = app(TenantVersion::class);
    $before = $versions->current($this->team);
    $count = Assignment::count();

    $reason = Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)
        ->onlyScopes([$this->site, Site::create(['team_id' => Team::create()->id])])->by($this->admin)->save());

    expect($reason)->toBe(GrantRejected::SCOPE_OUTSIDE_TENANT)
        ->and(Assignment::count())->toBe($count)
        ->and(AssignmentScope::count())->toBe(0)
        ->and($versions->current($this->team))->toBe($before);
});

it('needs a holder, a tenant and a non-empty scope list', function () {
    expect(Rejection::of(fn () => Access::grant('viewer')->in($this->team)->by($this->admin)->save()))
        ->toBe(GrantRejected::MISSING_HOLDER)
        ->and(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->by($this->admin)->save()))
        ->toBe(GrantRejected::MISSING_TENANT)
        ->and(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([])->by($this->admin)->save()))
        ->toBe(GrantRejected::NO_SCOPES);
});

it('rejects a window that ends at or before it starts', function (string $until) {
    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)
        ->validFrom(now())->validUntil(Carbon::parse($until))->by($this->admin)->save()))
        ->toBe(GrantRejected::INVALID_WINDOW);
})->with(['2026-10-28 12:00:00', '2026-10-28 11:59:59']);

it('accepts a window of one second and open windows', function () {
    $short = Access::grant('viewer')->to($this->user)->in($this->team)
        ->validFrom(now())->validUntil(now()->addSecond())->by($this->admin)->save();
    $short->delete();
    $untilOnly = Access::grant('viewer')->to($this->user)->in($this->team)->validUntil(now()->addDay())->by($this->admin)->save();
    $untilOnly->delete();
    $fromOnly = Access::grant('viewer')->to($this->user)->in($this->team)->validFrom(now()->addDay())->by($this->admin)->save();

    expect($fromOnly->fresh()->valid_from->toDateTimeString())->toBe('2026-10-29 12:00:00')
        ->and($fromOnly->fresh()->valid_until)->toBeNull();
});

it('names the reason in the exception message', function () {
    try {
        Access::grant('viewer')->to($this->user)->in($this->team)->save();
    } catch (GrantRejected $rejected) {
        expect($rejected->getMessage())->toBe('Grant rejected: missing_grantor');

        return;
    }

    $this->fail('No rejection');
});

it('builds a rule reason code from the rule name', function () {
    expect(GrantRejected::rule('board_seat')->reason)->toBe('rule:board_seat');
});

it('answers checks through the facade', function () {
    expect(Access::for($this->admin)->can('billing.invoice.approve', $this->site))->toBeTrue()
        ->and(Access::for($this->user)->can('billing.invoice.approve', $this->site))->toBeFalse();
});
