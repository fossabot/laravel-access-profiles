<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seisigma\AccessProfiles\Enums\Effect;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\AssignmentScope;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Models\ProfileRule;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

beforeEach(function () {
    foreach (config('access-profiles.tables') as $key => $table) {
        config()->set("access-profiles.tables.{$key}", 'custom_'.$table);
    }
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->user = User::create();
    $this->profile = Profile::create(['key' => 'reader', 'name' => 'Reader']);
    $this->assignment = function (array $attributes = []) {
        return Assignment::create(array_merge([
            'tenant_type' => $this->team->getMorphClass(), 'tenant_id' => $this->team->id,
            'holder_type' => $this->user->getMorphClass(), 'holder_id' => $this->user->id,
            'profile_id' => $this->profile->id, 'profile_version' => 1,
        ], $attributes));
    };
});

afterEach(fn () => Carbon::setTestNow());

it('persists all four models to configured tables with casts and relationships', function () {
    $this->profile->tenant()->associate($this->team);
    $this->profile->save();
    $copy = Profile::create(['key' => 'copy', 'name' => 'Copy', 'based_on_id' => $this->profile->id]);
    $rule = ProfileRule::create(['profile_id' => $this->profile->id, 'permission' => 'billing.invoice.view', 'effect' => Effect::Deny]);
    $assignment = ($this->assignment)(['covers_tenant' => false, 'valid_from' => now(), 'valid_until' => now()->addDay(), 'revoked_at' => now()]);
    $assignment->grantedBy()->associate($this->user);
    $assignment->revokedBy()->associate($this->user);
    $assignment->save();
    $site = Site::create(['team_id' => $this->team->id]);
    $scope = AssignmentScope::create(['assignment_id' => $assignment->id, 'scope_type' => $site->getMorphClass(), 'scope_id' => $site->id]);
    $assignment = $assignment->fresh();

    foreach ([new Profile, new ProfileRule, new Assignment, new AssignmentScope] as $model) {
        expect($model->getTable())->toStartWith('custom_')
            ->and(DB::table($model->getTable())->count())->toBeGreaterThan(0);
    }
    expect($this->profile->fresh()->is_system)->toBeFalse()
        ->and($this->profile->fresh()->version)->toBe(1)
        ->and($this->profile->tenant->is($this->team))->toBeTrue()
        ->and($copy->basedOn->is($this->profile))->toBeTrue()
        ->and($this->profile->rules->sole()->is($rule))->toBeTrue()
        ->and($rule->profile->is($this->profile))->toBeTrue()
        ->and($rule->fresh()->effect)->toBe(Effect::Deny)
        ->and($assignment->fresh()->covers_tenant)->toBeFalse()
        ->and($assignment->fresh()->profile_version)->toBe(1)
        ->and($assignment->valid_from)->toBeInstanceOf(Carbon::class)
        ->and($assignment->valid_until)->toBeInstanceOf(Carbon::class)
        ->and($assignment->revoked_at)->toBeInstanceOf(Carbon::class)
        ->and($assignment->tenant->is($this->team))->toBeTrue()
        ->and($assignment->holder->is($this->user))->toBeTrue()
        ->and($assignment->profile->is($this->profile))->toBeTrue()
        ->and($assignment->grantedBy->is($this->user))->toBeTrue()
        ->and($assignment->revokedBy->is($this->user))->toBeTrue()
        ->and($assignment->scopes->sole()->is($scope))->toBeTrue()
        ->and($scope->assignment->is($assignment))->toBeTrue()
        ->and($scope->scope->is($site))->toBeTrue()
        ->and($this->profile->assignments->sole()->is($assignment))->toBeTrue();
});

it('normalizes string version attributes before persistence', function () {
    expect((new Profile(['version' => '3']))->version)->toBe(3)
        ->and((new Assignment(['profile_version' => '3']))->profile_version)->toBe(3);
});

it('uses default table names when not overridden', function () {
    foreach (['profiles' => Profile::class, 'profile_permissions' => ProfileRule::class, 'assignments' => Assignment::class, 'assignment_scopes' => AssignmentScope::class] as $key => $class) {
        $table = config("access-profiles.tables.{$key}");
        config()->set("access-profiles.tables.{$key}", substr($table, 7));
        expect((new $class)->getTable())->toBe(substr($table, 7));
    }
});

it('filters active assignments using inclusive starts exclusive ends and revocation', function (array $attributes, bool $active) {
    $assignment = ($this->assignment)($attributes);
    expect(Assignment::query()->active(now())->pluck('id')->all())->toBe($active ? [$assignment->id] : []);
})->with([
    'unbounded' => [[], true],
    'starts now' => [['valid_from' => '2026-10-28 12:00:00'], true],
    'started' => [['valid_from' => '2026-10-28 11:59:59'], true],
    'future' => [['valid_from' => '2026-10-28 12:00:01'], false],
    'ends now' => [['valid_until' => '2026-10-28 12:00:00'], false],
    'expired' => [['valid_until' => '2026-10-28 11:59:59'], false],
    'ends later' => [['valid_until' => '2026-10-28 12:00:01'], true],
    'revoked' => [['revoked_at' => '2026-10-28 11:00:00'], false],
    'revoked with bounds' => [['valid_from' => '2026-10-28 11:00:00', 'valid_until' => '2026-10-28 13:00:00', 'revoked_at' => '2026-10-28 11:30:00'], false],
]);

it('groups active predicates when composing filters and uses the supplied time', function () {
    $matching = ($this->assignment)(['valid_from' => now()->addHour(), 'valid_until' => now()->addHours(3)]);
    ($this->assignment)(['holder_id' => User::create()->id]);
    ($this->assignment)(['tenant_id' => Team::create()->id]);
    expect(Assignment::query()->forHolder($this->user)->inTenant($this->team)->active(now()->addHours(2))->pluck('id')->all())->toBe([$matching->id]);
});

it('matches holder and tenant morph types as well as ids', function () {
    $matching = ($this->assignment)();
    ($this->assignment)(['holder_type' => Team::class]);
    ($this->assignment)(['tenant_type' => User::class]);
    expect(Assignment::query()->forHolder($this->user)->inTenant($this->team)->pluck('id')->all())->toBe([$matching->id]);
});

it('soft deletes profiles and preserves assignment history', function () {
    $assignment = ($this->assignment)();
    $this->profile->delete();
    expect(Profile::find($this->profile->id))->toBeNull()
        ->and(Profile::withTrashed()->find($this->profile->id)->deleted_at)->toBeInstanceOf(Carbon::class)
        ->and($assignment->fresh())->not->toBeNull();
});

it('enforces unique rule scope and tenant profile keys', function (string $kind) {
    if ($kind === 'profile') {
        $this->profile->tenant()->associate($this->team)->save();
        Profile::create(['key' => 'reader', 'name' => 'Duplicate', 'tenant_type' => Team::class, 'tenant_id' => $this->team->id]);
    } elseif ($kind === 'rule') {
        $attributes = ['profile_id' => $this->profile->id, 'permission' => 'billing.invoice.view'];
        ProfileRule::create($attributes);
        ProfileRule::create($attributes);
    } else {
        $attributes = ['assignment_id' => ($this->assignment)()->id, 'scope_type' => Site::class, 'scope_id' => 1];
        AssignmentScope::create($attributes);
        AssignmentScope::create($attributes);
    }
})->with(['profile', 'rule', 'scope'])->throws(QueryException::class);

it('drops all four configured tables', function () {
    (include __DIR__.'/../../database/migrations/create_access_profiles_tables.php.stub')->down();
    foreach (config('access-profiles.tables') as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});
