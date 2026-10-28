<?php

use Illuminate\Support\Carbon;
use Seisigma\AccessProfiles\Contracts\AssignmentRule;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Guards\HostRules;
use Seisigma\AccessProfiles\Guards\NoEscalation;
use Seisigma\AccessProfiles\Guards\NoSelfAssignment;
use Seisigma\AccessProfiles\Guards\TenantConsistency;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\OpenRule;
use Seisigma\AccessProfiles\Tests\Fixtures\RecordingRule;
use Seisigma\AccessProfiles\Tests\Fixtures\Rejection;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(NoEscalation::class, NoSelfAssignment::class, TenantConsistency::class, HostRules::class);

beforeEach(function () {
    config()->set('access-profiles.cache.store', 'array');
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->site = Site::create(['team_id' => $this->team->id]);
    $this->otherSite = Site::create(['team_id' => $this->team->id]);
    $this->foreignSite = Site::create(['team_id' => Team::create()->id]);
    $this->grantor = User::create();
    $this->user = User::create();
    $this->full = Grants::profile('full', ['billing.invoice.view', 'billing.invoice.approve']);
    $this->viewer = Grants::profile('viewer', ['billing.invoice.view']);
    $this->grant = fn (string|Profile $profile = 'full', ?array $scopes = null) => tap(
        Access::grant($profile)->to($this->user)->in($this->team)->by($this->grantor),
        fn ($builder) => $scopes === null ? $builder : $builder->onlyScopes($scopes),
    );
    RecordingRule::$calls = [];
    RecordingRule::$allows = true;
});

afterEach(fn () => Carbon::setTestNow());

function guardsOff(): void
{
    config()->set('access-profiles.guards.no_escalation', false);
    config()->set('access-profiles.guards.no_self_assignment', false);
    config()->set('access-profiles.guards.protected_profiles', []);
}

// no_escalation

it('saves when the grantor holds every allow key in every target scope', function () {
    Grants::assign($this->grantor, $this->full, $this->team, [$this->site, $this->otherSite]);

    expect(Rejection::of(fn () => ($this->grant)('full', [$this->site, $this->otherSite])->save()))->toBeNull();
});

it('rejects when the grantor lacks one allow key in the target scope', function () {
    Grants::assign($this->grantor, $this->viewer, $this->team);

    expect(Rejection::of(fn () => ($this->grant)('full', [$this->site])->save()))->toBe(GrantRejected::ESCALATION);
});

it('rejects when the grantor holds the keys in one target scope but not another', function () {
    Grants::assign($this->grantor, $this->full, $this->team, [$this->site]);

    expect(Rejection::of(fn () => ($this->grant)('full', [$this->site, $this->otherSite])->save()))->toBe(GrantRejected::ESCALATION)
        ->and(Rejection::of(fn () => ($this->grant)('full', [$this->otherSite, $this->site])->save()))->toBe(GrantRejected::ESCALATION)
        ->and(Rejection::of(fn () => ($this->grant)('full', [$this->site])->save()))->toBeNull();
});

it('counts a deny of the grantor as lacking the key', function () {
    Grants::assign($this->grantor, $this->full, $this->team);
    Grants::assign($this->grantor, Grants::profile('no_approve', deny: ['billing.invoice.approve']), $this->team);

    expect(Rejection::of(fn () => ($this->grant)('full', [$this->site])->save()))->toBe(GrantRejected::ESCALATION)
        ->and(Rejection::of(fn () => ($this->grant)('viewer', [$this->site])->save()))->toBeNull();
});

it('needs the keys at tenant level for a covers-tenant grant', function () {
    Grants::assign($this->grantor, $this->full, $this->team, [$this->site, $this->otherSite]);

    expect(Rejection::of(fn () => ($this->grant)('full')->save()))->toBe(GrantRejected::ESCALATION);

    Grants::assign($this->grantor, $this->full, $this->team);
    app(TenantVersion::class)->bump($this->team);

    expect(Rejection::of(fn () => ($this->grant)('full')->save()))->toBeNull();
});

it('asks only for the allow keys of the profile', function () {
    $denyOnly = Grants::profile('deny_only', deny: ['billing.invoice.approve']);

    expect(Rejection::of(fn () => ($this->grant)($denyOnly)->save()))->toBeNull();
});

it('lets a grantor without the keys grant when no_escalation is off', function () {
    config()->set('access-profiles.guards.no_escalation', false);

    expect(Rejection::of(fn () => ($this->grant)('full')->save()))->toBeNull();
});

// no_self_assignment

it('rejects a grant to the grantor even when the grantor holds every key', function () {
    Grants::assign($this->grantor, $this->full, $this->team);

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->grantor)->in($this->team)->by($this->grantor)->save()))
        ->toBe(GrantRejected::SELF_ASSIGNMENT);
});

it('compares holder and grantor as records, not as objects', function () {
    Grants::assign($this->grantor, $this->full, $this->team);

    expect(Rejection::of(fn () => Access::grant('viewer')->to(User::find($this->grantor->id))->in($this->team)->by($this->grantor)->save()))
        ->toBe(GrantRejected::SELF_ASSIGNMENT);
});

it('lets the grantor grant to themself when no_self_assignment is off', function () {
    config()->set('access-profiles.guards.no_self_assignment', false);
    Grants::assign($this->grantor, $this->full, $this->team);

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->grantor)->in($this->team)->by($this->grantor)->save()))->toBeNull();
});

// grantor required

it('needs a grantor while a guard is on', function (bool $escalation, bool $self, ?string $reason) {
    config()->set('access-profiles.guards.no_escalation', $escalation);
    config()->set('access-profiles.guards.no_self_assignment', $self);

    $saved = Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->save());

    expect($saved)->toBe($reason);
})->with([
    'both on' => [true, true, GrantRejected::MISSING_GRANTOR],
    'escalation only' => [true, false, GrantRejected::MISSING_GRANTOR],
    'self only' => [false, true, GrantRejected::MISSING_GRANTOR],
    'both off' => [false, false, null],
]);

it('stores no grantor when both guards are off and none was given', function () {
    guardsOff();

    $assignment = Access::grant('viewer')->to($this->user)->in($this->team)->save();

    expect($assignment->fresh()->granted_by_id)->toBeNull();
});

// bySystem

it('skips escalation and self checks for a system grant and stores its reason', function () {
    $assignment = Access::grant('full')->to($this->user)->in($this->team)->bySystem('Backfill from roles')->save();

    expect($assignment->fresh()->reason)->toBe('Backfill from roles')
        ->and($assignment->fresh()->granted_by_id)->toBeNull();
});

it('drops the grantor when bySystem is called after by', function () {
    $assignment = Access::grant('full')->to($this->user)->in($this->team)->by($this->grantor)->bySystem('Seed')->save();

    expect($assignment->fresh()->granted_by_id)->toBeNull();
});

it('runs the guards again when by is called after bySystem', function () {
    expect(Rejection::of(fn () => Access::grant('full')->to($this->user)->in($this->team)->bySystem('Seed')->by($this->grantor)->save()))
        ->toBe(GrantRejected::ESCALATION);
});

it('keeps tenant consistency, duplicates and host rules for a system grant', function () {
    config()->set('access-profiles.guards.rules', [RecordingRule::class]);
    $system = fn (array $scopes = []) => tap(
        Access::grant('full')->to($this->user)->in($this->team)->bySystem('Seed'),
        fn ($builder) => $scopes === [] ? $builder : $builder->onlyScopes($scopes),
    );

    expect(Rejection::of(fn () => $system([$this->foreignSite])->save()))->toBe(GrantRejected::SCOPE_OUTSIDE_TENANT);

    RecordingRule::$allows = false;
    expect(Rejection::of(fn () => $system()->save()))->toBe('rule:board_seat');

    RecordingRule::$allows = true;
    $system()->save();
    expect(Rejection::of(fn () => $system()->save()))->toBe(GrantRejected::DUPLICATE_ASSIGNMENT);
});

// tenant consistency

it('rejects a scope of another tenant even with every guard off', function () {
    guardsOff();

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([$this->site, $this->foreignSite])->save()))
        ->toBe(GrantRejected::SCOPE_OUTSIDE_TENANT)
        ->and(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([$this->foreignSite, $this->site])->bySystem('Seed')->save()))
        ->toBe(GrantRejected::SCOPE_OUTSIDE_TENANT);
});

it('rejects a scope that has no tenant and the tenant itself as a scope', function () {
    guardsOff();

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([User::create()])->save()))
        ->toBe(GrantRejected::SCOPE_OUTSIDE_TENANT)
        ->and(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([$this->team])->save()))
        ->toBe(GrantRejected::SCOPE_IS_TENANT);
});

it('rejects a scope given as the tenant', function () {
    guardsOff();

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->site)->save()))
        ->toBe(GrantRejected::NOT_A_TENANT);
});

it('rejects a second active assignment of one profile to one holder in one tenant', function () {
    guardsOff();
    Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([$this->site])->save();

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([$this->otherSite])->save()))
        ->toBe(GrantRejected::DUPLICATE_ASSIGNMENT);
});

it('counts an assignment that starts later as a duplicate', function () {
    guardsOff();
    Grants::assign($this->user, $this->viewer, $this->team, attributes: ['valid_from' => now()->addWeek()]);

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->save()))
        ->toBe(GrantRejected::DUPLICATE_ASSIGNMENT);
});

it('does not count revoked or ended rows as duplicates', function () {
    guardsOff();
    Grants::assign($this->user, $this->viewer, $this->team, attributes: ['revoked_at' => now()->subDay()]);
    Grants::assign($this->user, $this->viewer, $this->team, attributes: ['valid_until' => now()]);

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->save()))->toBeNull()
        ->and(Assignment::count())->toBe(3);
});

it('does not count other holders, profiles or tenants as duplicates', function () {
    guardsOff();
    $otherTeam = Team::create();
    Grants::assign(User::create(), $this->viewer, $this->team);
    Grants::assign($this->user, $this->full, $this->team);
    Grants::assign($this->user, $this->viewer, $otherTeam);

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->save()))->toBeNull();
});

// host rules

it('rejects with the rule name when a host rule says no', function () {
    guardsOff();
    config()->set('access-profiles.guards.rules', [OpenRule::class, RecordingRule::class]);
    RecordingRule::$allows = false;

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->onlyScopes([$this->site])->save()))
        ->toBe('rule:board_seat')
        ->and(RecordingRule::$calls)->toHaveCount(1)
        ->and(RecordingRule::$calls[0]['holder']->is($this->user))->toBeTrue()
        ->and(RecordingRule::$calls[0]['profile'])->toBe('viewer')
        ->and(RecordingRule::$calls[0]['tenant']->is($this->team))->toBeTrue()
        ->and(RecordingRule::$calls[0]['scopes'][0]->is($this->site))->toBeTrue();
});

it('saves when every host rule says yes', function () {
    guardsOff();
    config()->set('access-profiles.guards.rules', [RecordingRule::class, OpenRule::class]);

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->user)->in($this->team)->save()))->toBeNull()
        ->and(RecordingRule::$calls[0]['scopes'])->toBe([]);
});

it('refuses a configured rule class that is not an assignment rule', function () {
    guardsOff();
    config()->set('access-profiles.guards.rules', [User::class]);

    Access::grant('viewer')->to($this->user)->in($this->team)->save();
})->throws(InvalidArgumentException::class, 'Assignment rules must implement '.AssignmentRule::class);

// guard order

it('runs every later guard when an earlier one passes', function () {
    Grants::assign($this->grantor, $this->full, $this->team);
    config()->set('access-profiles.guards.rules', [RecordingRule::class]);

    expect(Rejection::of(fn () => ($this->grant)('viewer', [$this->foreignSite])->save()))->toBe(GrantRejected::SCOPE_OUTSIDE_TENANT);

    RecordingRule::$allows = false;
    expect(Rejection::of(fn () => ($this->grant)('viewer')->save()))->toBe('rule:board_seat');

    RecordingRule::$allows = true;
    ($this->grant)('viewer')->save();
    expect(Rejection::of(fn () => ($this->grant)('viewer')->save()))->toBe(GrantRejected::DUPLICATE_ASSIGNMENT);
});

it('runs the self check when escalation passes', function () {
    Grants::assign($this->grantor, $this->full, $this->team);

    expect(Rejection::of(fn () => Access::grant('viewer')->to($this->grantor)->in($this->team)->by($this->grantor)->save()))
        ->toBe(GrantRejected::SELF_ASSIGNMENT);
});
