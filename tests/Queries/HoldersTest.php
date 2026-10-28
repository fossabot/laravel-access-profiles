<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\Exceptions\UnknownAccessTenant;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Queries\HoldersQuery;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\SwitchedConditions;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(HoldersQuery::class);

beforeEach(function () {
    config()->set('access-profiles.cache.enabled', false);
    config()->set('auth.providers.users.model', User::class);
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->site = Site::create(['team_id' => $this->team->id]);
    $this->otherSite = Site::create(['team_id' => $this->team->id]);
    $this->viewKey = 'billing.invoice.view';
    $this->approveKey = 'billing.invoice.approve';
    $this->viewer = Grants::profile('viewer', [$this->viewKey]);
});

afterEach(fn () => Carbon::setTestNow());

/** @return list<int> */
function holderIds(string $permission, Model $target): array
{
    return Access::holders($permission, $target)->modelKeys();
}

it('agrees with can() for every holder, key and target in a fixed matrix', function () {
    SwitchedConditions::bind(['live']);
    $view = $this->viewKey;
    $approve = $this->approveKey;
    $teamB = Team::create();
    $siteB = Site::create(['team_id' => $teamB->id]);
    $approver = Grants::profile('approver', [$approve, $view]);
    $blockApprove = Grants::profile('block_approve', [], [$approve]);
    $boardOnly = Grants::profile('board_only', [$approve], [], null, 'board');
    $offDeny = Grants::profile('off_deny', [], [$view], null, 'board');
    $liveApprove = Grants::profile('live_approve', [$approve], [], null, 'live');
    $liveDeny = Grants::profile('live_deny', [], [$view], null, 'live');
    $copyOfB = Grants::profile('copy_b', [$view, $approve], [], $teamB);
    $gone = Grants::profile('gone', [$view, $approve]);
    $gone->delete();

    $users = collect(range(1, 9))->map(fn () => User::create());
    [$u1, $u2, $u3, $u4, $u5, $u6, $u7, $u8, $u9] = $users->all();

    Grants::assign($u1, $this->viewer, $this->team);
    Grants::assign($u2, $approver, $this->team, [$this->site]);
    Grants::assign($u3, $approver, $this->team);
    Grants::assign($u3, $blockApprove, $this->team, [$this->otherSite]);
    Grants::assign($u4, $this->viewer, $this->team, null, ['valid_until' => '2026-10-28 11:59:59']);
    Grants::assign($u4, $approver, $this->team, [$this->site], ['valid_from' => '2026-10-28 13:00:00']);
    Grants::assign($u4, $this->viewer, $this->team, [$this->otherSite], ['revoked_at' => '2026-10-28 11:00:00']);
    Grants::assign($u5, $boardOnly, $this->team);
    Grants::assign($u5, $offDeny, $this->team);
    Grants::assign($u5, $this->viewer, $this->team);
    Grants::assign($u5, $liveApprove, $this->team, [$this->otherSite]);
    Grants::assign($u6, $copyOfB, $this->team);
    Grants::assign($u6, $gone, $this->team);
    Grants::assign($u6, $this->viewer, $teamB, [$this->site]);
    Grants::assign($u6, $approver, $teamB);
    Grants::assign($u7, $this->viewer, $this->team, [$this->site], ['valid_from' => '2026-10-28 12:00:00']);
    Grants::assign($u7, $approver, $this->team, null, ['valid_until' => '2026-10-28 12:00:00']);
    Grants::assign($u8, $approver, $this->team);
    Grants::assign($u8, $blockApprove, $this->team);
    Grants::assign($u9, $approver, $this->team);
    Grants::assign($u9, $liveDeny, $this->team, [$this->site]);
    Grants::assign($u9, $copyOfB, $teamB, [$siteB]);

    $combinations = 0;
    $allowed = 0;

    foreach ([$view, $approve] as $key) {
        foreach ([$this->team, $this->site, $this->otherSite, $teamB, $siteB] as $target) {
            $holders = holderIds($key, $target);

            foreach ($users as $user) {
                $can = HolderAccess::for($user)->can($key, $target);
                expect(in_array($user->id, $holders, true))->toBe($can, "user {$user->id}, {$key}, ".$target::class." {$target->id}");
                $combinations++;
                $allowed += (int) $can;
            }

            expect(Access::holdersQuery($key, $target)->count())->toBe(count($holders));
        }
    }

    // The matrix is not trivial: it has both answers, many times.
    expect($combinations)->toBe(90)
        ->and($allowed)->toBe(29);
});

it('returns the holders of a covering assignment', function () {
    $user = User::create();
    Grants::assign($user, $this->viewer, $this->team);

    expect(Access::holders($this->viewKey, $this->site))->toBeInstanceOf(Collection::class)
        ->and(holderIds($this->viewKey, $this->site))->toBe([$user->id])
        ->and(holderIds($this->viewKey, $this->team))->toBe([$user->id])
        ->and(holderIds($this->approveKey, $this->site))->toBe([]);
});

it('limits a scope-limited assignment to its listed scopes and never the tenant level', function () {
    $user = User::create();
    Grants::assign($user, $this->viewer, $this->team, [$this->site]);

    expect(holderIds($this->viewKey, $this->site))->toBe([$user->id])
        ->and(holderIds($this->viewKey, $this->otherSite))->toBe([])
        ->and(holderIds($this->viewKey, $this->team))->toBe([]);
});

it('matches a listed scope by morph type as well as id', function () {
    $user = User::create();
    $assignment = Grants::assign($user, $this->viewer, $this->team, [$this->site]);
    $assignment->scopes()->update(['scope_type' => Team::class]);

    expect(holderIds($this->viewKey, $this->site))->toBe([]);
});

it('removes a holder when a deny in a second profile covers the target', function () {
    $user = User::create();
    $other = User::create();
    Grants::assign($user, $this->viewer, $this->team);
    Grants::assign($other, $this->viewer, $this->team);
    Grants::assign($user, Grants::profile('blocked', [], [$this->viewKey]), $this->team, [$this->site]);

    expect(holderIds($this->viewKey, $this->site))->toBe([$other->id])
        ->and(holderIds($this->viewKey, $this->otherSite))->toBe([$user->id, $other->id])
        ->and(holderIds($this->viewKey, $this->team))->toBe([$user->id, $other->id]);
});

it('applies a deny only to its own key', function () {
    $user = User::create();
    Grants::assign($user, Grants::profile('both', [$this->viewKey, $this->approveKey]), $this->team);
    Grants::assign($user, Grants::profile('no_approve', [], [$this->approveKey]), $this->team);

    expect(holderIds($this->viewKey, $this->site))->toBe([$user->id])
        ->and(holderIds($this->approveKey, $this->site))->toBe([]);
});

it('lets a deny inside the same profile win', function () {
    $user = User::create();
    $profile = Grants::profile('torn', [$this->viewKey]);
    $profile->rules()->create(['permission' => $this->approveKey, 'effect' => 'deny']);
    Grants::assign($user, $profile, $this->team);
    Grants::assign($user, Grants::profile('approver', [$this->approveKey]), $this->team);

    expect(holderIds($this->approveKey, $this->site))->toBe([]);
});

it('excludes expired, future and revoked rows', function (array $attributes) {
    Grants::assign(User::create(), $this->viewer, $this->team, null, $attributes);

    expect(holderIds($this->viewKey, $this->site))->toBe([]);
})->with([
    'expired' => [['valid_until' => '2026-10-28 11:59:59']],
    'ends now' => [['valid_until' => '2026-10-28 12:00:00']],
    'future' => [['valid_from' => '2026-10-28 12:00:01']],
    'revoked' => [['revoked_at' => '2026-10-28 11:00:00']],
]);

it('includes rows inside their window', function (array $attributes) {
    $user = User::create();
    Grants::assign($user, $this->viewer, $this->team, null, $attributes);

    expect(holderIds($this->viewKey, $this->site))->toBe([$user->id]);
})->with([
    'starts now' => [['valid_from' => '2026-10-28 12:00:00']],
    'started' => [['valid_from' => '2026-10-28 11:00:00', 'valid_until' => '2026-10-28 12:00:01']],
]);

it('bounds the window with the app clock', function () {
    $user = User::create();
    Grants::assign($user, $this->viewer, $this->team, null, ['valid_from' => '2030-01-01 00:00:00', 'valid_until' => '2030-02-01 00:00:00']);

    expect(holderIds($this->viewKey, $this->site))->toBe([]);

    Carbon::setTestNow('2030-01-15 00:00:00');
    expect(holderIds($this->viewKey, $this->site))->toBe([$user->id]);

    Carbon::setTestNow('2030-02-01 00:00:00');
    expect(holderIds($this->viewKey, $this->site))->toBe([]);
});

it('ignores the deny of an expired, future or revoked row', function (array $attributes) {
    $user = User::create();
    Grants::assign($user, $this->viewer, $this->team);
    Grants::assign($user, Grants::profile('blocked', [], [$this->viewKey]), $this->team, null, $attributes);

    expect(holderIds($this->viewKey, $this->site))->toBe([$user->id]);
})->with([
    'expired' => [['valid_until' => '2026-10-28 12:00:00']],
    'future' => [['valid_from' => '2026-10-28 12:00:01']],
    'revoked' => [['revoked_at' => '2026-10-28 11:00:00']],
]);

it('excludes a profile whose condition is off, and ignores its deny', function () {
    $conditions = SwitchedConditions::bind([]);
    $gated = User::create();
    $denied = User::create();
    Grants::assign($gated, Grants::profile('board', [$this->viewKey], [], null, 'board_approvals'), $this->team);
    Grants::assign($denied, $this->viewer, $this->team);
    Grants::assign($denied, Grants::profile('board_block', [], [$this->viewKey], null, 'board_approvals'), $this->team);

    expect(holderIds($this->viewKey, $this->site))->toBe([$denied->id])
        ->and($conditions->asked)->toBe(['board_approvals']);

    SwitchedConditions::bind(['board_approvals']);
    expect(holderIds($this->viewKey, $this->site))->toBe([$gated->id]);
});

it('only asks about the conditions of profiles with a rule for the key', function () {
    $conditions = SwitchedConditions::bind([]);
    Grants::assign(User::create(), Grants::profile('board', [$this->approveKey], [], null, 'board_approvals'), $this->team);
    Grants::assign(User::create(), Grants::profile('night', [$this->viewKey], [], null, 'night_shift'), $this->team);

    holderIds($this->viewKey, $this->site);

    expect($conditions->asked)->toBe(['night_shift']);
});

it('excludes a scope of another tenant', function () {
    $teamB = Team::create();
    $user = User::create();
    // An assignment of team B that lists a site of team A (direct insert, past the guards).
    Grants::assign($user, $this->viewer, $teamB, [$this->site]);
    Grants::assign(User::create(), $this->viewer, $teamB);

    expect(holderIds($this->viewKey, $this->site))->toBe([]);
});

it('never counts an assignment of another tenant at the tenant level', function () {
    $user = User::create();
    Grants::assign($user, $this->viewer, Team::create());

    expect(holderIds($this->viewKey, $this->team))->toBe([]);
});

it('never uses a tenant copy that belongs to another tenant', function () {
    $teamB = Team::create();
    $user = User::create();
    Grants::assign($user, Grants::profile('viewer', [$this->viewKey], [], $teamB), $this->team);
    $own = User::create();
    Grants::assign($own, Grants::profile('viewer', [$this->viewKey], [], $this->team), $this->team);

    expect(holderIds($this->viewKey, $this->site))->toBe([$own->id]);
});

it('ignores a soft-deleted profile, its deny included', function () {
    $user = User::create();
    Grants::assign($user, $this->viewer, $this->team);
    $blocked = Grants::profile('blocked', [], [$this->viewKey]);
    Grants::assign($user, $blocked, $this->team);
    $gone = Grants::profile('gone', [$this->viewKey]);
    $other = User::create();
    Grants::assign($other, $gone, $this->team);

    expect(holderIds($this->viewKey, $this->site))->toBe([$other->id]);

    $blocked->delete();
    $gone->delete();
    expect(holderIds($this->viewKey, $this->site))->toBe([$user->id]);
});

it('returns each holder once', function () {
    $user = User::create();
    Grants::assign($user, $this->viewer, $this->team);
    Grants::assign($user, Grants::profile('also', [$this->viewKey]), $this->team, [$this->site]);

    expect(Access::holders($this->viewKey, $this->site))->toHaveCount(1)
        ->and(Access::holdersQuery($this->viewKey, $this->site)->count())->toBe(1);
});

it('returns a builder the host can filter and paginate', function () {
    $users = collect(range(1, 3))->map(fn () => User::create());
    $users->each(fn (User $user) => Grants::assign($user, $this->viewer, $this->team));

    $query = Access::holdersQuery($this->viewKey, $this->site);

    expect($query)->toBeInstanceOf(Builder::class)
        ->and($query->getModel())->toBeInstanceOf(User::class)
        ->and($query->clone()->whereKeyNot($users[0]->id)->pluck('id')->all())->toBe([$users[1]->id, $users[2]->id])
        ->and($query->paginate(2)->total())->toBe(3);
});

it('defaults the holder class to the configured user model', function () {
    $user = User::create();
    Grants::assign($user, $this->viewer, $this->team);
    Grants::assign($this->otherSite, $this->viewer, $this->team);

    expect(Access::holdersQuery($this->viewKey, $this->site)->getModel())->toBeInstanceOf(User::class)
        ->and(holderIds($this->viewKey, $this->site))->toBe([$user->id])
        ->and(Access::holders($this->viewKey, $this->site, Site::class)->modelKeys())->toBe([$this->otherSite->id]);
});

it('matches holders by morph type as well as id', function () {
    // A site holder and a user that share the id 500.
    $site = Site::create(['id' => 500, 'team_id' => $this->team->id]);
    User::create(['id' => 500]);
    Grants::assign($site, $this->viewer, $this->team);

    expect(holderIds($this->viewKey, $this->team))->toBe([]);
});

it('throws for a target that is neither a tenant nor a scope', function () {
    Access::holders($this->viewKey, User::create());
})->throws(UnknownAccessTenant::class);

it('runs one holder query plus the condition-name lookup, whatever the number of holders', function () {
    $users = collect(range(1, 10))->map(fn () => User::create());
    $users->each(fn (User $user) => Grants::assign($user, $this->viewer, $this->team, [$this->site]));
    Grants::assign($users[0], Grants::profile('blocked', [], [$this->viewKey]), $this->team);
    // The tenant of the site is host code (ScopeOwnership); load it outside the count.
    $this->site->load('team');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $holders = Access::holders($this->viewKey, $this->site);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($holders)->toHaveCount(9)
        ->and($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toContain('requires_condition')
        ->and($queries[1]['query'])->toContain('not exists');
});

it('is read-only: it never bumps the tenant version', function () {
    config()->set('access-profiles.cache.enabled', true);
    $versions = app(TenantVersion::class);
    $version = $versions->current($this->team);

    Access::holders($this->viewKey, $this->site);

    expect($versions->current($this->team))->toBe($version);
});
