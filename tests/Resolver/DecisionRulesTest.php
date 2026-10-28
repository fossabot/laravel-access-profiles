<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\Contracts\ProfileConditions;
use Seisigma\AccessProfiles\Enums\Effect;
use Seisigma\AccessProfiles\Exceptions\UnknownAccessTenant;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Resolver\DatabaseResolver;
use Seisigma\AccessProfiles\Resolver\Decision;
use Seisigma\AccessProfiles\Resolver\GrantSet;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

const VIEW = 'billing.invoice.view';
const APPROVE = 'billing.invoice.approve';

beforeEach(function () {
    config()->set('access-profiles.cache.enabled', false);
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->site = Site::create(['team_id' => $this->team->id]);
    $this->otherSite = Site::create(['team_id' => $this->team->id]);
    $this->user = User::create();
    $this->viewer = Grants::profile('viewer', [VIEW]);
});

afterEach(fn () => Carbon::setTestNow());

function access(User $user): HolderAccess
{
    return HolderAccess::for($user);
}

function conditions(array $on): void
{
    app()->instance(ProfileConditions::class, new class($on) implements ProfileConditions
    {
        public function __construct(public array $on) {}

        public function isOn(string $condition, Model $tenant): bool
        {
            return in_array($condition, $this->on, true);
        }
    });
}

it('is false by default', function () {
    expect(access($this->user)->can(VIEW, $this->site))->toBeFalse()
        ->and(access($this->user)->can(VIEW, $this->team))->toBeFalse()
        ->and(access($this->user)->explain(VIEW, $this->site)->reason)->toBe(Decision::NO_MATCH);
});

it('resolves the tenant of a scope target', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    $elsewhere = Site::create(['team_id' => Team::create()->id]);

    expect(access($this->user)->can(VIEW, $this->site))->toBeTrue()
        ->and(access($this->user)->can(VIEW, $elsewhere))->toBeFalse();
});

it('throws for a target that is neither a tenant nor a scope', function () {
    access($this->user)->can(VIEW, User::create());
})->throws(UnknownAccessTenant::class);

it('covers a tenant-level check only with a covers-tenant assignment', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);
    expect(access($this->user)->can(VIEW, $this->team))->toBeFalse();

    Grants::assign($this->user, Grants::profile('wide', [VIEW]), $this->team);
    app()->forgetScopedInstances();
    expect(access($this->user)->can(VIEW, $this->team))->toBeTrue();
});

it('covers every scope of the tenant with a covers-tenant assignment', function () {
    Grants::assign($this->user, $this->viewer, $this->team);

    expect(access($this->user)->can(VIEW, $this->site))->toBeTrue()
        ->and(access($this->user)->can(VIEW, $this->otherSite))->toBeTrue()
        ->and(access($this->user)->can(VIEW, $this->team))->toBeTrue()
        ->and(access($this->user)->can(APPROVE, $this->site))->toBeFalse();
});

it('limits a scope-limited assignment to its listed scopes', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    expect(access($this->user)->can(VIEW, $this->site))->toBeTrue()
        ->and(access($this->user)->can(VIEW, $this->otherSite))->toBeFalse();
});

it('matches a listed scope by morph type as well as id', function () {
    $assignment = Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);
    $assignment->scopes()->update(['scope_type' => Team::class]);

    expect(access($this->user)->can(VIEW, $this->site))->toBeFalse();
});

it('lets a deny in one profile win over an allow in another', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    $deny = Grants::assign($this->user, Grants::profile('blocked', [], [VIEW]), $this->team, [$this->site]);

    $decision = access($this->user)->explain(VIEW, $this->site);
    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe(Decision::DENIED)
        ->and($decision->deniedBy)->toBe($deny->id)
        ->and(access($this->user)->can(VIEW, $this->otherSite))->toBeTrue();
});

it('lets the deny win whatever order the assignments load in', function () {
    $deny = Grants::assign($this->user, Grants::profile('blocked', [], [VIEW]), $this->team);
    Grants::assign($this->user, $this->viewer, $this->team);

    $decision = access($this->user)->explain(VIEW, $this->site);
    expect($decision->allowed)->toBeFalse()
        ->and($decision->deniedBy)->toBe($deny->id);
});

it('applies a deny inside one profile only to its own key', function () {
    Grants::assign($this->user, Grants::profile('mixed', [VIEW], [APPROVE]), $this->team);

    expect(access($this->user)->can(VIEW, $this->site))->toBeTrue()
        ->and(access($this->user)->can(APPROVE, $this->site))->toBeFalse()
        ->and(access($this->user)->permissionsIn($this->site))->toBe([VIEW]);
});

it('lets a deny win inside one grant entry that lists the key twice', function () {
    $set = GrantSet::fromArray([[
        'assignment' => 1, 'profile' => 'p', 'covers_tenant' => true, 'scopes' => [],
        'allow' => [VIEW], 'deny' => [VIEW], 'valid_from' => null, 'valid_until' => null, 'condition' => null,
    ]]);

    $decision = app(DatabaseResolver::class)->evaluate($set, $this->user, VIEW, $this->site, $this->team);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->deniedBy)->toBe(1);
});

it('is false for a revoked assignment', function () {
    Grants::assign($this->user, $this->viewer, $this->team, null, ['revoked_at' => now()->subMinute()]);

    expect(access($this->user)->can(VIEW, $this->site))->toBeFalse();
});

it('ignores the deny of a revoked assignment', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    Grants::assign($this->user, Grants::profile('blocked', [], [VIEW]), $this->team, null, ['revoked_at' => now()->subMinute()]);

    expect(access($this->user)->can(VIEW, $this->site))->toBeTrue();
});

it('becomes true when the clock passes valid_from without any cache clear', function () {
    Grants::assign($this->user, $this->viewer, $this->team, null, ['valid_from' => '2026-10-28 13:00:00']);
    $access = access($this->user);

    expect($access->can(VIEW, $this->site))->toBeFalse();
    Carbon::setTestNow('2026-10-28 12:59:59');
    expect($access->can(VIEW, $this->site))->toBeFalse();
    Carbon::setTestNow('2026-10-28 13:00:00');
    expect($access->can(VIEW, $this->site))->toBeTrue();
});

it('becomes false when valid_until passes without any write', function () {
    Grants::assign($this->user, $this->viewer, $this->team, null, ['valid_until' => '2026-10-28 13:00:00']);
    $access = access($this->user);

    expect($access->can(VIEW, $this->site))->toBeTrue();
    Carbon::setTestNow('2026-10-28 12:59:59');
    expect($access->can(VIEW, $this->site))->toBeTrue();
    Carbon::setTestNow('2026-10-28 13:00:00');
    expect($access->can(VIEW, $this->site))->toBeFalse();
});

it('uses inclusive starts and exclusive ends at load time', function (array $window, bool $allowed) {
    Grants::assign($this->user, $this->viewer, $this->team, null, $window);

    expect(access($this->user)->can(VIEW, $this->site))->toBe($allowed);
})->with([
    'starts now' => [['valid_from' => '2026-10-28 12:00:00'], true],
    'starts in a second' => [['valid_from' => '2026-10-28 12:00:01'], false],
    'ends now' => [['valid_until' => '2026-10-28 12:00:00'], false],
    'ends in a second' => [['valid_until' => '2026-10-28 12:00:01'], true],
    'ended' => [['valid_until' => '2026-10-28 11:59:59'], false],
]);

it('ignores the whole profile when its condition is off, deny included', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    Grants::assign($this->user, Grants::profile('board', [APPROVE], [VIEW], null, 'board_approvals'), $this->team);

    expect(access($this->user)->can(APPROVE, $this->site))->toBeFalse()
        ->and(access($this->user)->can(VIEW, $this->site))->toBeTrue();
});

it('reads the condition at check time without a version bump', function () {
    config()->set('access-profiles.cache.enabled', true);
    Grants::assign($this->user, Grants::profile('board', [APPROVE], [], null, 'board_approvals'), $this->team);
    conditions([]);
    expect(access($this->user)->can(APPROVE, $this->site))->toBeFalse();

    conditions(['board_approvals']);
    expect(access($this->user)->can(APPROVE, $this->site))->toBeTrue();
    app()->forgetScopedInstances();
    expect(access($this->user)->can(APPROVE, $this->site))->toBeTrue();
});

it('applies the deny of a profile whose condition is on', function () {
    conditions(['board_approvals']);
    Grants::assign($this->user, $this->viewer, $this->team);
    Grants::assign($this->user, Grants::profile('board', [], [VIEW], null, 'board_approvals'), $this->team);

    expect(access($this->user)->can(VIEW, $this->site))->toBeFalse();
});

it('asks the host about the tenant of the target', function () {
    $other = Team::create();
    $seen = new ArrayObject;
    app()->instance(ProfileConditions::class, new class($seen) implements ProfileConditions
    {
        public function __construct(public ArrayObject $seen) {}

        public function isOn(string $condition, Model $tenant): bool
        {
            $this->seen[] = [$condition, $tenant->getKey()];

            return true;
        }
    });
    Grants::assign($this->user, Grants::profile('board', [APPROVE], [], null, 'board_approvals'), $other);

    expect(access($this->user)->can(APPROVE, $other))->toBeTrue()
        ->and($seen->getArrayCopy())->toBe([['board_approvals', $other->id]]);
});

it('never counts an assignment of another tenant', function () {
    $other = Team::create();
    Grants::assign($this->user, $this->viewer, $other);

    expect(access($this->user)->can(VIEW, $this->site))->toBeFalse()
        ->and(access($this->user)->can(VIEW, $this->team))->toBeFalse()
        ->and(access($this->user)->can(VIEW, $other))->toBeTrue();
});

it('never counts an assignment of another holder', function () {
    Grants::assign(User::create(), $this->viewer, $this->team);

    expect(access($this->user)->can(VIEW, $this->site))->toBeFalse();
});

it('never uses a tenant copy that belongs to another tenant', function () {
    $foreign = Grants::profile('copy', [VIEW], [], Team::create());
    Grants::assign($this->user, $foreign, $this->team);
    Grants::assign($this->user, Grants::profile('own', [APPROVE], [], $this->team), $this->team);

    expect(access($this->user)->can(VIEW, $this->site))->toBeFalse()
        ->and(access($this->user)->can(APPROVE, $this->site))->toBeTrue();
});

it('ignores a soft-deleted profile', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    Profile::find($this->viewer->id)->delete();

    expect(access($this->user)->can(VIEW, $this->site))->toBeFalse();
});

it('throws an authorization exception only when denied', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    access($this->user)->authorize(VIEW, $this->site);
    expect(fn () => access($this->user)->authorize(VIEW, $this->otherSite))->toThrow(AuthorizationException::class);
});

it('explains the matching assignments and the deny that won', function () {
    $allow = Grants::assign($this->user, $this->viewer, $this->team);
    $deny = Grants::assign($this->user, Grants::profile('blocked', [], [VIEW]), $this->team, [$this->site]);
    Grants::assign($this->user, Grants::profile('unrelated', [APPROVE]), $this->team);

    $denied = access($this->user)->explain(VIEW, $this->site);
    $allowed = access($this->user)->explain(VIEW, $this->otherSite);

    expect($denied)->toBeInstanceOf(Decision::class)
        ->and($denied->permission)->toBe(VIEW)
        ->and($denied->matches)->toBe([
            ['assignment' => $allow->id, 'profile' => 'viewer', 'effect' => Effect::Allow],
            ['assignment' => $deny->id, 'profile' => 'blocked', 'effect' => Effect::Deny],
        ])
        ->and($allowed->allowed)->toBeTrue()
        ->and($allowed->reason)->toBe(Decision::ALLOWED)
        ->and($allowed->deniedBy)->toBeNull()
        ->and($allowed->matches)->toBe([['assignment' => $allow->id, 'profile' => 'viewer', 'effect' => Effect::Allow]]);
});

it('lists the granted keys in a target without denied ones', function () {
    Grants::assign($this->user, Grants::profile('all', [VIEW, APPROVE]), $this->team);
    Grants::assign($this->user, Grants::profile('blocked', [], [APPROVE]), $this->team, [$this->site]);
    Grants::assign($this->user, Grants::profile('board', ['billingx.invoice.view'], [], null, 'board_approvals'), $this->team);

    expect(access($this->user)->permissionsIn($this->site))->toBe([VIEW])
        ->and(access($this->user)->permissionsIn($this->otherSite))->toBe([APPROVE, VIEW])
        ->and(access($this->user)->permissionsIn($this->team))->toBe([APPROVE, VIEW]);
});

it('lists each key once, sorted, without the denies of any profile', function () {
    Grants::assign($this->user, Grants::profile('blocked', [], [APPROVE]), $this->team);
    Grants::assign($this->user, Grants::profile('reports', ['billing.report.view', APPROVE]), $this->team);
    Grants::assign($this->user, $this->viewer, $this->team);
    Grants::assign($this->user, Grants::profile('second-viewer', [VIEW]), $this->team);

    expect(access($this->user)->permissionsIn($this->site))->toBe([VIEW, 'billing.report.view']);
});

it('names the first deny in load order as the one that won', function () {
    $first = Grants::assign($this->user, Grants::profile('blocked', [], [VIEW]), $this->team);
    Grants::assign($this->user, Grants::profile('frozen', [], [VIEW]), $this->team);

    expect(access($this->user)->explain(VIEW, $this->site)->deniedBy)->toBe($first->id);
});

it('asks the host once per condition in one check', function () {
    $host = new class implements ProfileConditions
    {
        public int $asked = 0;

        public function isOn(string $condition, Model $tenant): bool
        {
            $this->asked++;

            return true;
        }
    };
    app()->instance(ProfileConditions::class, $host);
    Grants::assign($this->user, Grants::profile('board', [VIEW], [], null, 'board_approvals'), $this->team);
    Grants::assign($this->user, Grants::profile('board-reports', [APPROVE], [], null, 'board_approvals'), $this->team);

    expect(access($this->user)->can(VIEW, $this->site))->toBeTrue()
        ->and($host->asked)->toBe(1);
});

it('returns the covering entries as a list', function () {
    $entry = fn (int $id, bool $coversTenant) => [
        'assignment' => $id, 'profile' => 'p', 'covers_tenant' => $coversTenant, 'scopes' => [],
        'allow' => [VIEW], 'deny' => [], 'valid_from' => null, 'valid_until' => null, 'condition' => null,
    ];
    $set = GrantSet::fromArray([$entry(1, false), $entry(2, true)]);

    expect($set->covering($this->site, false, now()))->toBe([$entry(2, true)]);
});
