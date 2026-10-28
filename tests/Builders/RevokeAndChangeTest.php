<?php

use Illuminate\Support\Carbon;
use Seisigma\AccessProfiles\Builders\ChangeBuilder;
use Seisigma\AccessProfiles\Builders\RevokeBuilder;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Guards\ProtectedProfiles;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\AssignmentScope;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\RecordingRule;
use Seisigma\AccessProfiles\Tests\Fixtures\Rejection;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(RevokeBuilder::class, ChangeBuilder::class, ProtectedProfiles::class);

beforeEach(function () {
    config()->set('access-profiles.cache.store', 'array');
    config()->set('access-profiles.guards.protected_profiles', ['owner']);
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
    $this->owner = Grants::profile('owner', ['billing.invoice.view']);
    Grants::assign($this->admin, $this->full, $this->team);
    $this->revoke = fn (Assignment $assignment) => Access::revoke($assignment)->by($this->admin)->because('Term ended');
});

afterEach(fn () => Carbon::setTestNow());

// revoke

it('revokes with time, actor and reason, and access stops at once', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);
    expect(Access::for($this->user)->can('billing.invoice.view', $this->site))->toBeTrue();

    $revoked = ($this->revoke)($assignment)->save();

    $row = $revoked->fresh();
    expect($row->revoked_at->toDateTimeString())->toBe('2026-10-28 12:00:00')
        ->and($row->revokedBy->is($this->admin))->toBeTrue()
        ->and($row->revoke_reason)->toBe('Term ended')
        ->and(Access::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse();
});

it('keeps the revoked row as history', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);

    ($this->revoke)($assignment)->save();

    expect(Assignment::whereKey($assignment->id)->exists())->toBeTrue();
});

it('rejects revoking a row that is already revoked', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, attributes: ['revoked_at' => now()->subDay()]);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBe(GrantRejected::ALREADY_REVOKED);
});

it('reads the revoked state from the database, not from the given model', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);
    Assignment::whereKey($assignment->id)->update(['revoked_at' => now()->subHour()]);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBe(GrantRejected::ALREADY_REVOKED)
        ->and($assignment->fresh()->revoked_at->toDateTimeString())->toBe('2026-10-28 11:00:00');
});

it('rejects revoking own assignment', function () {
    $assignment = Grants::assign($this->admin, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::revoke($assignment)->by($this->admin)->save()))->toBe(GrantRejected::SELF_ASSIGNMENT);
});

it('lets the holder revoke their own assignment when no_self_assignment is off', function () {
    config()->set('access-profiles.guards.no_self_assignment', false);
    $assignment = Grants::assign($this->admin, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::revoke($assignment)->by($this->admin)->save()))->toBeNull();
});

it('needs a grantor to revoke while a guard is on, and not for a system revoke', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::revoke($assignment)->save()))->toBe(GrantRejected::MISSING_GRANTOR)
        ->and(Rejection::of(fn () => Access::revoke($assignment)->bySystem('Cleanup')->save()))->toBeNull()
        ->and($assignment->fresh()->revoke_reason)->toBe('Cleanup')
        ->and($assignment->fresh()->revoked_by_id)->toBeNull();
});

it('revokes without a grantor when both guards are off', function () {
    config()->set('access-profiles.guards.no_escalation', false);
    config()->set('access-profiles.guards.no_self_assignment', false);
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::revoke($assignment)->save()))->toBeNull();
});

it('lets a system revoke remove own access', function () {
    $assignment = Grants::assign($this->admin, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::revoke($assignment)->by($this->admin)->bySystem('Offboarding')->save()))->toBeNull();
});

it('lets a grantor without the keys revoke, because a revoke takes reach away', function () {
    $grantor = User::create();
    Grants::assign($grantor, $this->viewer, $this->team);
    $assignment = Grants::assign($this->user, $this->full, $this->team);

    expect(Rejection::of(fn () => Access::revoke($assignment)->by($grantor)->save()))->toBeNull();
});

it('revokes a row whose holder record is gone', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);
    $this->user->delete();

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBeNull()
        ->and($assignment->fresh()->revoked_at)->not->toBeNull();
});

it('rejects a revoke or change of a row whose tenant record is gone', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);
    $this->team->delete();

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBe(GrantRejected::MISSING_TENANT)
        ->and(Rejection::of(fn () => Access::change($assignment)->titled('X')->by($this->admin)->save()))->toBe(GrantRejected::MISSING_TENANT)
        ->and($assignment->fresh()->revoked_at)->toBeNull();
});

it('bumps the tenant version on revoke', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);
    $versions = app(TenantVersion::class);
    $before = $versions->current($this->team);

    ($this->revoke)($assignment)->save();

    expect($versions->current($this->team))->toBe($before + 1);
});

// protected_profiles

it('rejects revoking the last active holder of a protected profile', function () {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBe(GrantRejected::LAST_HOLDER)
        ->and($assignment->fresh()->revoked_at)->toBeNull();
});

it('revokes the first of two holders and rejects the second', function () {
    $first = Grants::assign($this->user, $this->owner, $this->team);
    $second = Grants::assign(User::create(), $this->owner, $this->team);

    expect(Rejection::of(fn () => ($this->revoke)($first)->save()))->toBeNull()
        ->and(Rejection::of(fn () => ($this->revoke)($second)->save()))->toBe(GrantRejected::LAST_HOLDER);
});

it('rejects the second of two revokes prepared before either saved', function () {
    $first = ($this->revoke)(Grants::assign($this->user, $this->owner, $this->team));
    $second = ($this->revoke)(Grants::assign(User::create(), $this->owner, $this->team));

    expect(Rejection::of(fn () => $first->save()))->toBeNull()
        ->and(Rejection::of(fn () => $second->save()))->toBe(GrantRejected::LAST_HOLDER)
        ->and(Assignment::whereNull('revoked_at')->where('profile_id', $this->owner->id)->count())->toBe(1);
});

it('counts only active rows as other holders', function (array $attributes) {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);
    Grants::assign(User::create(), $this->owner, $this->team, attributes: $attributes);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBe(GrantRejected::LAST_HOLDER);
})->with([
    'expired' => [['valid_until' => '2026-10-28 12:00:00']],
    'not started' => [['valid_from' => '2026-10-28 12:00:01']],
    'revoked' => [['revoked_at' => '2026-10-27 12:00:00']],
]);

it('counts a holder whose window started this second', function () {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);
    Grants::assign(User::create(), $this->owner, $this->team, attributes: ['valid_from' => now(), 'valid_until' => now()->addSecond()]);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBeNull();
});

it('counts holders of the protected key in this tenant only', function () {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);
    Grants::assign(User::create(), $this->owner, Team::create());
    Grants::assign(User::create(), $this->viewer, $this->team);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBe(GrantRejected::LAST_HOLDER);
});

it('counts the template and the tenant copy of a protected key together', function () {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);
    Grants::assign(User::create(), Grants::profile('owner', ['billing.invoice.view'], tenant: $this->team), $this->team);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBeNull();
});

it('ignores a tenant copy of the protected key that belongs to another tenant', function () {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);
    Grants::assign(User::create(), Grants::profile('owner', ['billing.invoice.view'], tenant: Team::create()), $this->team);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBe(GrantRejected::LAST_HOLDER);
});

it('lets the last holder of an unprotected profile go', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBeNull();
});

it('lets an inactive row of a protected profile go', function (array $attributes) {
    $assignment = Grants::assign($this->user, $this->owner, $this->team, attributes: $attributes);

    expect(Rejection::of(fn () => ($this->revoke)($assignment)->save()))->toBeNull();
})->with([
    'expired' => [['valid_until' => '2026-10-28 12:00:00']],
    'not started' => [['valid_from' => '2026-10-29 12:00:00']],
]);

it('protects the last holder for a system revoke too', function () {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);

    expect(Rejection::of(fn () => Access::revoke($assignment)->bySystem('Cleanup')->save()))->toBe(GrantRejected::LAST_HOLDER);
});

// change

it('changes the end date, scopes and title', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    $changed = Access::change($assignment)
        ->validUntil(now()->addWeek())
        ->onlyScopes([$this->otherSite])
        ->titled('Treasurer')
        ->by($this->admin)
        ->because('New term')
        ->save();

    $row = $changed->fresh();
    expect($row->valid_until->toDateTimeString())->toBe('2026-11-04 12:00:00')
        ->and($row->title)->toBe('Treasurer')
        ->and($row->covers_tenant)->toBeFalse()
        ->and(AssignmentScope::where('assignment_id', $row->id)->pluck('scope_id')->all())->toBe([$this->otherSite->id])
        ->and(Access::for($this->user)->can('billing.invoice.view', $this->site))->toBeFalse()
        ->and(Access::for($this->user)->can('billing.invoice.view', $this->otherSite))->toBeTrue();
});

it('narrows a covers-tenant assignment to scopes', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);

    Access::change($assignment)->onlyScopes([$this->site])->by($this->admin)->save();

    expect($assignment->fresh()->covers_tenant)->toBeFalse()
        ->and(Access::for($this->user)->can('billing.invoice.view', $this->team))->toBeFalse()
        ->and(Access::for($this->user)->can('billing.invoice.view', $this->site))->toBeTrue();
});

it('leaves what was not changed as it was', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, [$this->site], ['title' => 'Old', 'valid_until' => now()->addDay()]);

    Access::change($assignment)->titled('New')->by($this->admin)->save();

    $row = $assignment->fresh();
    expect($row->valid_until->toDateTimeString())->toBe('2026-10-29 12:00:00')
        ->and(AssignmentScope::where('assignment_id', $row->id)->pluck('scope_id')->all())->toBe([$this->site->id]);
});

it('clears the end date with validUntil(null)', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, attributes: ['valid_until' => now()->addDay()]);

    Access::change($assignment)->validUntil(null)->by($this->admin)->save();

    expect($assignment->fresh()->valid_until)->toBeNull();
});

it('keeps the reason of a change for the caller', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);

    $builder = Access::change($assignment)->titled('X')->by($this->admin)->because('Typo');

    expect($builder->reason())->toBe('Typo');
});

it('rejects an end date at or before the start', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, attributes: ['valid_from' => now()]);

    expect(Rejection::of(fn () => Access::change($assignment)->validUntil(now())->by($this->admin)->save()))
        ->toBe(GrantRejected::INVALID_WINDOW);
});

it('rejects changing a revoked assignment', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, attributes: ['revoked_at' => now()->subDay()]);

    expect(Rejection::of(fn () => Access::change($assignment)->titled('X')->by($this->admin)->save()))->toBe(GrantRejected::ALREADY_REVOKED);
});

it('rejects changing own assignment', function () {
    $assignment = Grants::assign($this->admin, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::change($assignment)->titled('X')->by($this->admin)->save()))->toBe(GrantRejected::SELF_ASSIGNMENT);
});

it('needs a grantor to change while a guard is on', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::change($assignment)->titled('X')->save()))->toBe(GrantRejected::MISSING_GRANTOR);
});

it('rejects a changed scope from another tenant even with every guard off', function () {
    config()->set('access-profiles.guards.no_escalation', false);
    config()->set('access-profiles.guards.no_self_assignment', false);
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    expect(Rejection::of(fn () => Access::change($assignment)->onlyScopes([Site::create(['team_id' => Team::create()->id])])->save()))
        ->toBe(GrantRejected::SCOPE_OUTSIDE_TENANT)
        ->and(Rejection::of(fn () => Access::change($assignment)->onlyScopes([])->save()))
        ->toBe(GrantRejected::NO_SCOPES)
        ->and(AssignmentScope::where('assignment_id', $assignment->id)->pluck('scope_id')->all())->toBe([$this->site->id]);
});

it('rejects a change that gives a grantor without the keys a wider reach', function () {
    $grantor = User::create();
    Grants::assign($grantor, $this->viewer, $this->team, [$this->site]);
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    expect(Rejection::of(fn () => Access::change($assignment)->onlyScopes([$this->otherSite])->by($grantor)->save()))
        ->toBe(GrantRejected::ESCALATION)
        ->and(Rejection::of(fn () => Access::change($assignment)->titled('Kept scope')->by($grantor)->save()))
        ->toBeNull();
});

it('checks the existing reach of a covers-tenant assignment at tenant level on change', function () {
    $grantor = User::create();
    Grants::assign($grantor, $this->viewer, $this->team, [$this->site, $this->otherSite]);
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::change($assignment)->titled('X')->by($grantor)->save()))->toBe(GrantRejected::ESCALATION);
});

it('rejects a change of a row whose holder record is gone', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);
    $this->user->delete();

    expect(Rejection::of(fn () => Access::change($assignment)->titled('X')->by($this->admin)->save()))->toBe(GrantRejected::MISSING_HOLDER);
});

it('leaves a deleted scope record out of the reach it checks, and keeps the row scoped', function () {
    $grantor = User::create();
    Grants::assign($grantor, $this->viewer, $this->team, [$this->site]);
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, [$this->site, $this->otherSite]);
    $this->otherSite->delete();

    expect(Rejection::of(fn () => Access::change($assignment)->titled('Kept')->by($grantor)->save()))->toBeNull()
        ->and($assignment->fresh()->covers_tenant)->toBeFalse()
        ->and($assignment->fresh()->title)->toBe('Kept');
});

it('runs host rules on change with the new scopes', function () {
    config()->set('access-profiles.guards.rules', [RecordingRule::class]);
    RecordingRule::$calls = [];
    RecordingRule::$allows = false;
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    expect(Rejection::of(fn () => Access::change($assignment)->onlyScopes([$this->otherSite])->by($this->admin)->save()))
        ->toBe('rule:board_seat')
        ->and(RecordingRule::$calls[0]['scopes'][0]->is($this->otherSite))->toBeTrue();
    RecordingRule::$allows = true;
});

it('bumps the tenant version on change', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team);
    $versions = app(TenantVersion::class);
    $before = $versions->current($this->team);

    Access::change($assignment)->titled('X')->by($this->admin)->save();

    expect($versions->current($this->team))->toBe($before + 1);
});

it('rejects a change that ends or limits the validity of the last protected holder', function (string $until) {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);

    expect(Rejection::of(fn () => Access::change($assignment)->validUntil(Carbon::parse($until))->by($this->admin)->save()))
        ->toBe(GrantRejected::LAST_HOLDER)
        ->and($assignment->fresh()->valid_until)->toBeNull();
})->with(['2026-10-28 12:00:00', '2026-10-27 12:00:00', '2026-12-31 00:00:00']);

it('allows an end date on a protected holder when another holder stays', function () {
    $assignment = Grants::assign($this->user, $this->owner, $this->team);
    Grants::assign(User::create(), $this->owner, $this->team);

    expect(Rejection::of(fn () => Access::change($assignment)->validUntil(now())->by($this->admin)->save()))->toBeNull();
});

it('rejects a change that brings an ended row back while a live duplicate exists', function (?string $until) {
    $ended = Grants::assign($this->user, $this->viewer, $this->team, attributes: ['valid_from' => now()->subMonth(), 'valid_until' => now()->subDay()]);
    Grants::assign($this->user, $this->viewer, $this->team);
    $versions = app(TenantVersion::class);
    $before = $versions->current($this->team);

    expect(Rejection::of(fn () => Access::change($ended)->validUntil($until === null ? null : Carbon::parse($until))->titled('Back')->by($this->admin)->save()))
        ->toBe(GrantRejected::DUPLICATE_ASSIGNMENT)
        ->and($ended->fresh()->valid_until->toDateTimeString())->toBe('2026-10-27 12:00:00')
        ->and($ended->fresh()->title)->toBeNull()
        ->and($versions->current($this->team))->toBe($before);
})->with(['future end' => ['2026-12-31 00:00:00'], 'open end' => [null]]);

it('brings an ended row back when no other live row exists', function () {
    $ended = Grants::assign($this->user, $this->viewer, $this->team, attributes: ['valid_from' => now()->subMonth(), 'valid_until' => now()->subDay()]);
    Grants::assign($this->user, $this->viewer, $this->team, attributes: ['revoked_at' => now()->subHour()]);
    Grants::assign($this->user, $this->viewer, $this->team, attributes: ['valid_until' => now()]);
    Grants::assign($this->user, $this->full, $this->team);
    Grants::assign(User::create(), $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::change($ended)->validUntil(null)->by($this->admin)->save()))->toBeNull()
        ->and($ended->fresh()->valid_until)->toBeNull();
});

it('changes a single live row without counting it as its own duplicate', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    expect(Rejection::of(fn () => Access::change($assignment)->validUntil(now()->addWeek())->onlyScopes([$this->otherSite])->titled('T')->by($this->admin)->save()))
        ->toBeNull()
        ->and(Rejection::of(fn () => Access::change($assignment)->validUntil(null)->by($this->admin)->save()))->toBeNull()
        ->and($assignment->fresh()->title)->toBe('T');
});

it('changes an ended row that stays ended even while a live duplicate exists', function () {
    $ended = Grants::assign($this->user, $this->viewer, $this->team, attributes: ['valid_from' => now()->subMonth(), 'valid_until' => now()->subDay()]);
    Grants::assign($this->user, $this->viewer, $this->team);

    expect(Rejection::of(fn () => Access::change($ended)->titled('History')->by($this->admin)->save()))->toBeNull()
        ->and(Rejection::of(fn () => Access::change($ended)->validUntil(now())->by($this->admin)->save()))->toBeNull()
        ->and($ended->fresh()->title)->toBe('History')
        ->and($ended->fresh()->valid_until->toDateTimeString())->toBe('2026-10-28 12:00:00');
});

it('allows other changes and an open end on the last protected holder', function () {
    $assignment = Grants::assign($this->user, $this->owner, $this->team, attributes: ['valid_until' => now()->addDay()]);

    expect(Rejection::of(fn () => Access::change($assignment)->titled('Owner')->by($this->admin)->save()))->toBeNull()
        ->and(Rejection::of(fn () => Access::change($assignment)->validUntil(null)->by($this->admin)->save()))->toBeNull();
});
