<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\Queries\ScopeSet;
use Seisigma\AccessProfiles\Resolver\DatabaseResolver;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\PersonalToken;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\SwitchedConditions;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\TokenUser;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(ScopeSet::class, DatabaseResolver::class);

beforeEach(function () {
    config()->set('access-profiles.cache.enabled', false);
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->site = Site::create(['team_id' => $this->team->id]);
    $this->otherSite = Site::create(['team_id' => $this->team->id]);
    $this->thirdSite = Site::create(['team_id' => $this->team->id]);
    $this->user = User::create();
    $this->key = 'billing.invoice.view';
    $this->viewer = Grants::profile('viewer', [$this->key]);
    $this->blocked = Grants::profile('blocked', [], [$this->key]);
});

afterEach(fn () => Carbon::setTestNow());

function scopesOf(User $user, string $key, Team $team): ScopeSet
{
    return HolderAccess::for($user)->scopesWhere($key, $team);
}

/** @return list<int> the team's sites that the constrained query returns */
function constrainedSites(ScopeSet $set, Team $team): array
{
    return $set->constrain(Site::query()->where('team_id', $team->id), 'id')->orderBy('id')->pluck('id')->all();
}

it('covers the tenant with a covers-tenant allow and adds no filter', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    $set = scopesOf($this->user, $this->key, $this->team);
    $query = Site::query();

    expect($set->coversTenant())->toBeTrue()
        ->and($set->constrain($query, 'id'))->toBe($query)
        ->and($query->toBase()->wheres)->toBe([])
        ->and(constrainedSites($set, $this->team))->toBe([$this->site->id, $this->otherSite->id, $this->thirdSite->id]);
});

it('lists the scopes of scope-limited allows and constrains to them', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);
    Grants::assign($this->user, Grants::profile('more', [$this->key]), $this->team, [$this->thirdSite]);
    $set = scopesOf($this->user, $this->key, $this->team);

    expect($set->coversTenant())->toBeFalse()
        ->and($set->ids(Site::class))->toBe([$this->site->id, $this->thirdSite->id])
        ->and(constrainedSites($set, $this->team))->toBe([$this->site->id, $this->thirdSite->id]);
});

it('returns no rows, never no filter, for an empty set', function () {
    Grants::assign($this->user, Grants::profile('other', ['billing.invoice.approve']), $this->team);
    $set = scopesOf($this->user, $this->key, $this->team);

    expect($set->coversTenant())->toBeFalse()
        ->and($set->ids(Site::class))->toBe([])
        ->and(constrainedSites($set, $this->team))->toBe([])
        ->and($set->constrain(DB::table('sites'), 'id')->count())->toBe(0);
});

it('removes a scope with a scope-level deny', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site, $this->otherSite]);
    Grants::assign($this->user, $this->blocked, $this->team, [$this->otherSite]);
    $set = scopesOf($this->user, $this->key, $this->team);

    expect($set->ids(Site::class))->toBe([$this->site->id])
        ->and(constrainedSites($set, $this->team))->toBe([$this->site->id]);
});

it('removes a denied scope from a tenant-wide allow', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    Grants::assign($this->user, $this->blocked, $this->team, [$this->otherSite]);
    $set = scopesOf($this->user, $this->key, $this->team);

    expect($set->coversTenant())->toBeFalse()
        ->and($set->exceptIds(Site::class))->toBe([$this->otherSite->id])
        ->and(constrainedSites($set, $this->team))->toBe([$this->site->id, $this->thirdSite->id]);
});

it('is empty when a covers-tenant deny exists, whatever the allows', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    Grants::assign($this->user, Grants::profile('more', [$this->key]), $this->team, [$this->site]);
    Grants::assign($this->user, $this->blocked, $this->team);
    $set = scopesOf($this->user, $this->key, $this->team);

    expect($set->coversTenant())->toBeFalse()
        ->and($set->ids(Site::class))->toBe([])
        ->and(constrainedSites($set, $this->team))->toBe([]);
});

it('ignores revoked, expired, future and condition-off rows, deny included', function () {
    SwitchedConditions::bind([]);
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);
    Grants::assign($this->user, $this->blocked, $this->team, null, ['revoked_at' => '2026-10-28 11:00:00']);
    Grants::assign($this->user, $this->blocked, $this->team, null, ['valid_until' => '2026-10-28 12:00:00']);
    Grants::assign($this->user, $this->blocked, $this->team, null, ['valid_from' => '2026-10-28 12:00:01']);
    Grants::assign($this->user, Grants::profile('gated_block', [], [$this->key], null, 'board'), $this->team);
    Grants::assign($this->user, Grants::profile('gated', [$this->key], [], null, 'board'), $this->team);

    expect(scopesOf($this->user, $this->key, $this->team)->ids(Site::class))->toBe([$this->site->id]);

    app()->forgetScopedInstances();
    SwitchedConditions::bind(['board']);
    expect(constrainedSites(scopesOf($this->user, $this->key, $this->team), $this->team))->toBe([]);
});

it('agrees with can() on every site of the tenant', function () {
    $users = collect(range(1, 4))->map(fn () => User::create());
    Grants::assign($users[0], $this->viewer, $this->team);
    Grants::assign($users[0], $this->blocked, $this->team, [$this->site]);
    Grants::assign($users[1], $this->viewer, $this->team, [$this->otherSite, $this->thirdSite]);
    Grants::assign($users[1], $this->blocked, $this->team, [$this->thirdSite]);
    Grants::assign($users[2], $this->viewer, $this->team, [$this->site], ['valid_until' => '2026-10-28 11:00:00']);
    Grants::assign($users[3], $this->viewer, $this->team, null, ['valid_from' => '2026-10-28 12:00:00']);

    foreach ($users as $user) {
        $allowed = constrainedSites(scopesOf($user, $this->key, $this->team), $this->team);

        foreach ([$this->site, $this->otherSite, $this->thirdSite] as $site) {
            expect(in_array($site->id, $allowed, true))->toBe(HolderAccess::for($user)->can($this->key, $site));
        }
    }
});

it('never lists a scope of another tenant', function () {
    $teamB = Team::create();
    Grants::assign($this->user, $this->viewer, $teamB, [$this->site]);
    Grants::assign($this->user, $this->viewer, $teamB);

    $set = scopesOf($this->user, $this->key, $this->team);

    expect($set->coversTenant())->toBeFalse()
        ->and($set->ids(Site::class))->toBe([]);
});

it('resolves the tenant when it is given a scope', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->otherSite]);

    expect(HolderAccess::for($this->user)->scopesWhere($this->key, $this->site)->ids(Site::class))->toBe([$this->otherSite->id]);
});

it('lets a stored token narrow the set to nothing', function () {
    $user = TokenUser::create();
    Grants::assign($user, $this->viewer, $this->team);

    $user->accessToken = PersonalToken::allowing(['billing.invoice.approve']);
    expect(constrainedSites(HolderAccess::for($user)->scopesWhere($this->key, $this->team), $this->team))->toBe([]);

    app()->forgetScopedInstances();
    $user->accessToken = PersonalToken::allowing([$this->key]);
    expect(HolderAccess::for($user)->scopesWhere($this->key, $this->team)->coversTenant())->toBeTrue();
});

it('keeps scope types apart', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);
    $assignment = Grants::assign($this->user, Grants::profile('teams', [$this->key]), $this->team, [$this->otherSite]);
    $assignment->scopes()->update(['scope_type' => Team::class]);
    $set = scopesOf($this->user, $this->key, $this->team);

    expect($set->ids(Site::class))->toBe([$this->site->id])
        ->and($set->ids(Team::class))->toBe([$this->otherSite->id])
        ->and($set->constrain(Site::query(), 'id', Site::class)->pluck('id')->all())->toBe([$this->site->id]);
});

it('needs a class to constrain a set with more than one scope type', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);
    $assignment = Grants::assign($this->user, Grants::profile('teams', [$this->key]), $this->team, [$this->otherSite]);
    $assignment->scopes()->update(['scope_type' => Team::class]);

    scopesOf($this->user, $this->key, $this->team)->constrain(Site::query(), 'id');
})->throws(InvalidArgumentException::class);

it('reads the grant set and runs no extra query', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);
    $access = HolderAccess::for($this->user);
    $access->can($this->key, $this->site);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $set = $access->scopesWhere($this->key, $this->team);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($set->ids(Site::class))->toBe([$this->site->id])
        ->and($queries)->toBe([]);
});

it('reads every entry and lists each scope once, sorted', function () {
    Grants::assign($this->user, Grants::profile('other', ['billing.invoice.approve']), $this->team, [$this->otherSite]);
    Grants::assign($this->user, $this->viewer, $this->team, [$this->thirdSite]);
    Grants::assign($this->user, Grants::profile('again', [$this->key]), $this->team, [$this->site, $this->thirdSite]);

    expect(scopesOf($this->user, $this->key, $this->team)->ids(Site::class))->toBe([$this->site->id, $this->thirdSite->id]);
});

it('collects the denies of every entry', function () {
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site, $this->otherSite, $this->thirdSite]);
    Grants::assign($this->user, $this->blocked, $this->team, [$this->thirdSite, $this->otherSite]);
    Grants::assign($this->user, Grants::profile('blocked_too', [], [$this->key]), $this->team, [$this->otherSite]);

    expect(scopesOf($this->user, $this->key, $this->team)->ids(Site::class))->toBe([$this->site->id]);
});

it('collects the denies of every entry inside a tenant-wide allow', function () {
    Grants::assign($this->user, $this->viewer, $this->team);
    Grants::assign($this->user, $this->blocked, $this->team, [$this->thirdSite, $this->otherSite]);
    Grants::assign($this->user, Grants::profile('blocked_too', [], [$this->key]), $this->team, [$this->otherSite]);

    expect(scopesOf($this->user, $this->key, $this->team)->exceptIds(Site::class))->toBe([$this->otherSite->id, $this->thirdSite->id]);
});

it('finds scopes stored under a numeric morph alias', function () {
    Relation::morphMap([7 => Site::class]);
    Grants::assign($this->user, $this->viewer, $this->team, [$this->site]);

    $set = scopesOf($this->user, $this->key, $this->team);

    expect($set->ids(Site::class))->toBe([$this->site->id])
        ->and($set->constrain(Site::query(), 'id', Site::class)->pluck('id')->all())->toBe([$this->site->id]);
})->after(fn () => Relation::morphMap([], false));
