<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Commands\SyncTemplatesCommand;
use Seisigma\AccessProfiles\Contracts\TemplateDefinitions;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Templates\TemplateDefinition;
use Seisigma\AccessProfiles\Templates\TemplateSync;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\TestPermission;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(TemplateDefinition::class, TemplateSync::class, SyncTemplatesCommand::class);

class SyncTestDefinitions implements TemplateDefinitions
{
    public static array $definitions = [];

    public static function templates(): iterable
    {
        yield from self::$definitions;
    }
}

beforeEach(function () {
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    config()->set('access-profiles.catalogues', [TestPermission::class]);
    config()->set('access-profiles.templates', SyncTestDefinitions::class);
    app()->forgetInstance(Catalogue::class);
    Carbon::setTestNow('2026-10-28 12:00:00');
    SyncTestDefinitions::$definitions = [new TemplateDefinition(
        key: 'reader', name: 'Reader', description: 'Read records',
        allow: ['billing.invoice.view'], deny: ['billing.invoice.approve'], requiresCondition: 'enabled',
    )];
    $this->sync = fn () => $this->artisan('access-profiles:sync-templates')->assertExitCode(0);
    $this->assign = function (Profile $profile, $tenant) {
        return Assignment::create([
            'profile_id' => $profile->id, 'profile_version' => $profile->version,
            'tenant_type' => $tenant->getMorphClass(), 'tenant_id' => $tenant->getKey(),
            'holder_type' => User::class, 'holder_id' => User::create()->id,
        ]);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('creates global system templates with exact metadata and rules', function () {
    ($this->sync)();
    $profile = Profile::sole();
    expect($profile->key)->toBe('reader')->and($profile->name)->toBe('Reader')
        ->and($profile->description)->toBe('Read records')->and($profile->requires_condition)->toBe('enabled')
        ->and($profile->tenant_type)->toBeNull()->and($profile->tenant_id)->toBeNull()
        ->and($profile->is_system)->toBeTrue()->and($profile->version)->toBe(1)
        ->and($profile->rules()->orderBy('permission')->get()->mapWithKeys(fn ($rule) => [$rule->permission => $rule->effect->value])->all())
        ->toBe(['billing.invoice.approve' => 'deny', 'billing.invoice.view' => 'allow']);
});

it('expands wildcard rules and lets an explicit deny override an overlapping allow', function () {
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Reader', allow: ['billing.*', 'billing.invoice.view'], deny: ['billing.invoice.approve'])];
    ($this->sync)();
    expect(Profile::sole()->rules()->orderBy('permission')->get()->mapWithKeys(fn ($rule) => [$rule->permission => $rule->effect->value])->all())
        ->toBe(['billing.invoice.approve' => 'deny', 'billing.invoice.view' => 'allow']);
});

it('writes nothing for unchanged sorted rule sets including reordered duplicate patterns', function () {
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Reader', allow: ['billing.invoice.view', 'billing.invoice.approve'])];
    ($this->sync)();
    // Existing rows need not have been inserted in catalogue or alphabetical order.
    $profile = Profile::sole();
    $profile->rules()->delete();
    $profile->rules()->create(['permission' => 'billing.invoice.view', 'effect' => 'allow']);
    $profile->rules()->create(['permission' => 'billing.invoice.approve', 'effect' => 'allow']);
    $before = Profile::sole()->toArray();
    $rules = Profile::sole()->rules()->orderBy('id')->get()->toArray();
    Carbon::setTestNow(now()->addDay());
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Reader', allow: ['billing.invoice.approve', 'billing.invoice.view', 'billing.*'])];
    // SQL does not promise the order of a scan without ORDER BY.
    DB::statement('PRAGMA reverse_unordered_selects = ON');
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes) {
        if (in_array(strtolower(strtok(ltrim($query->sql), ' ')), ['insert', 'update', 'delete', 'replace'])) {
            $writes[] = $query->sql;
        }
    });
    $this->artisan('access-profiles:sync-templates')->expectsOutputToContain('No changes')->assertExitCode(0);
    expect($writes)->toBe([])->and(Profile::sole()->toArray())->toBe($before)
        ->and(Profile::sole()->rules()->orderBy('id')->get()->toArray())->toBe($rules);
});

it('replaces changed rules and increments an existing version by exactly one', function () {
    ($this->sync)();
    $profile = Profile::sole();
    $profile->update(['version' => 7]);
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Updated', 'New description', deny: ['billing.invoice.view'])];
    ($this->sync)();
    expect($profile->fresh()->version)->toBe(8)->and($profile->fresh()->name)->toBe('Updated')
        ->and($profile->fresh()->description)->toBe('New description')->and($profile->fresh()->requires_condition)->toBeNull()
        ->and($profile->rules()->pluck('permission')->all())->toBe(['billing.invoice.view'])
        ->and($profile->rules()->sole()->effect->value)->toBe('deny');
});

it('versions metadata changes even when rules stay the same', function () {
    ($this->sync)();
    SyncTestDefinitions::$definitions[0] = new TemplateDefinition('reader', 'Renamed', 'Read records', ['billing.invoice.view'], ['billing.invoice.approve'], 'enabled');
    ($this->sync)();
    expect(Profile::sole()->name)->toBe('Renamed')->and(Profile::sole()->version)->toBe(2);
});

it('soft deletes removed templates while keeping rules assignments and tenant copies', function () {
    ($this->sync)();
    $template = Profile::sole();
    $tenant = Team::create();
    $assignment = ($this->assign)($template, $tenant);
    $copy = Profile::create(['key' => 'reader', 'name' => 'Custom', 'tenant_type' => Team::class, 'tenant_id' => $tenant->id, 'based_on_id' => $template->id]);
    $version = app(TenantVersion::class)->current($tenant);
    SyncTestDefinitions::$definitions = [];
    ($this->sync)();
    expect(Profile::find($template->id))->toBeNull()
        ->and(Profile::withTrashed()->find($template->id)->deleted_at)->not->toBeNull()
        ->and(Profile::withTrashed()->find($template->id)->version)->toBe(2)
        ->and($template->rules()->count())->toBe(2)->and($assignment->fresh()->profile_id)->toBe($template->id)
        ->and($copy->fresh()->name)->toBe('Custom')->and($copy->fresh()->based_on_id)->toBe($template->id)
        ->and(app(TenantVersion::class)->current($tenant))->toBe($version + 1);
    ($this->sync)();
    expect(app(TenantVersion::class)->current($tenant))->toBe($version + 1);
});

it('never updates tenant copies or non system global profiles', function () {
    $tenant = Team::create();
    $copy = Profile::create(['key' => 'reader', 'name' => 'Custom', 'is_system' => true, 'tenant_type' => Team::class, 'tenant_id' => $tenant->id]);
    $local = Profile::create(['key' => 'local', 'name' => 'Local']);
    ($this->sync)();
    expect($copy->fresh()->name)->toBe('Custom')->and($copy->fresh()->version)->toBe(1)
        ->and($local->fresh()->name)->toBe('Local')->and(Profile::count())->toBe(3);
});

it('prints create update and delete diffs on dry run without database or cache changes', function () {
    ($this->sync)();
    $tenant = Team::create();
    ($this->assign)(Profile::sole(), $tenant);
    $removed = Profile::create(['key' => 'removed', 'name' => 'Removed', 'is_system' => true]);
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Updated'), new TemplateDefinition('new', 'New')];
    $before = DB::table('access_profiles')->get()->toArray();
    $rules = DB::table('access_profile_permissions')->get()->toArray();
    $version = app(TenantVersion::class)->current($tenant);
    $this->artisan('access-profiles:sync-templates', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')->expectsOutputToContain('Update: reader')
        ->expectsOutputToContain('Create: new')->expectsOutputToContain('Delete: removed')->assertExitCode(0);
    expect(DB::table('access_profiles')->get()->toArray())->toEqual($before)
        ->and(DB::table('access_profile_permissions')->get()->toArray())->toEqual($rules)
        ->and($removed->fresh()->deleted_at)->toBeNull()->and(app(TenantVersion::class)->current($tenant))->toBe($version);
});

it('rejects an unknown key atomically with no changes or tenant version bump', function () {
    ($this->sync)();
    $tenant = Team::create();
    ($this->assign)(Profile::sole(), $tenant);
    $before = DB::table('access_profiles')->get()->toArray();
    $rules = DB::table('access_profile_permissions')->get()->toArray();
    $version = app(TenantVersion::class)->current($tenant);
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Updated'), new TemplateDefinition('bad', 'Bad', allow: ['missing.record.view'])];
    $this->artisan('access-profiles:sync-templates')->expectsOutputToContain('missing.record.view')->assertExitCode(1);
    expect(DB::table('access_profiles')->get()->toArray())->toEqual($before)
        ->and(DB::table('access_profile_permissions')->get()->toArray())->toEqual($rules)
        ->and(app(TenantVersion::class)->current($tenant))->toBe($version);
});

it('rolls back earlier template writes if a later database write fails', function () {
    ($this->sync)();
    $tenant = Team::create();
    ($this->assign)(Profile::sole(), $tenant);
    $version = app(TenantVersion::class)->current($tenant);
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Updated'), new TemplateDefinition('broken', 'Broken')];
    Profile::creating(function (Profile $profile) {
        if ($profile->key === 'broken') {
            throw new RuntimeException('Write failed');
        }
    });
    try {
        expect(fn () => app(TemplateSync::class)->sync())->toThrow(RuntimeException::class, 'Write failed');
        expect(Profile::sole()->name)->toBe('Reader')->and(Profile::sole()->version)->toBe(1)
            ->and(Profile::sole()->rules()->count())->toBe(2)->and(app(TenantVersion::class)->current($tenant))->toBe($version);
    } finally {
        Profile::flushEventListeners();
    }
});

it('bumps each affected assignment tenant once using the tenant morph and leaves other tenants alone', function () {
    SyncTestDefinitions::$definitions[] = new TemplateDefinition('second', 'Second');
    ($this->sync)();
    $first = Team::create();
    $second = User::create();
    $unaffected = Team::create();
    foreach (Profile::all() as $profile) {
        ($this->assign)($profile, $first);
        ($this->assign)($profile, $second);
    }
    $versions = app(TenantVersion::class);
    $before = [$versions->current($first), $versions->current($second), $versions->current($unaffected)];
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Updated'), new TemplateDefinition('second', 'Updated')];
    ($this->sync)();
    expect($versions->current($first))->toBe($before[0] + 1)->and($versions->current($second))->toBe($before[1] + 1)
        ->and($versions->current($unaffected))->toBe($before[2]);
    ($this->sync)();
    expect($versions->current($first))->toBe($before[0] + 1)->and($versions->current($second))->toBe($before[1] + 1);
});

it('restores a returning template without replacing its assignment identity', function () {
    ($this->sync)();
    $template = Profile::sole();
    $assignment = ($this->assign)($template, Team::create());
    $definitions = SyncTestDefinitions::$definitions;
    SyncTestDefinitions::$definitions = [];
    ($this->sync)();
    SyncTestDefinitions::$definitions = $definitions;
    ($this->sync)();
    expect(Profile::sole()->id)->toBe($template->id)->and(Profile::sole()->version)->toBe(3)
        ->and($assignment->fresh()->profile->id)->toBe($template->id);
});

it('does nothing when no template provider is configured', function () {
    config()->set('access-profiles.templates', null);
    $this->artisan('access-profiles:sync-templates')->expectsOutputToContain('No template definitions configured')->assertExitCode(0);
    expect(Profile::count())->toBe(0);
});

it('rejects an invalid template provider without writes', function ($provider) {
    config()->set('access-profiles.templates', $provider);
    $this->artisan('access-profiles:sync-templates')->expectsOutputToContain('Templates must implement '.TemplateDefinitions::class)->assertExitCode(1);
    expect(Profile::count())->toBe(0);
})->with([['stdClass'], [['invalid']], [false]]);

it('rejects duplicate template keys without writes', function () {
    SyncTestDefinitions::$definitions[] = new TemplateDefinition('reader', 'Duplicate');
    $this->artisan('access-profiles:sync-templates')->expectsOutputToContain('Duplicate template key: reader')->assertExitCode(1);
    expect(Profile::count())->toBe(0);
});

it('continues syncing later templates after an unchanged template', function () {
    ($this->sync)();
    SyncTestDefinitions::$definitions[] = new TemplateDefinition('later', 'Later');
    ($this->sync)();
    expect(Profile::where('key', 'reader')->sole()->version)->toBe(1)
        ->and(Profile::where('key', 'later')->sole()->name)->toBe('Later');
});

it('compares definition rule sets in canonical order', function () {
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Reader', allow: ['billing.invoice.view', 'billing.invoice.approve'])];
    ($this->sync)();
    ($this->sync)();
    expect(Profile::sole()->version)->toBe(1);
});

it('invalidates multiple tenants with the same morph type', function () {
    ($this->sync)();
    $first = Team::create();
    $second = Team::create();
    ($this->assign)(Profile::sole(), $first);
    ($this->assign)(Profile::sole(), $second);
    $versions = app(TenantVersion::class);
    $before = [$versions->current($first), $versions->current($second)];
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Updated')];
    ($this->sync)();
    expect($versions->current($first))->toBe($before[0] + 1)->and($versions->current($second))->toBe($before[1] + 1);
});

it('continues invalidating existing tenants when an assignment tenant has been deleted', function () {
    ($this->sync)();
    $removed = Team::create();
    ($this->assign)(Profile::sole(), $removed);
    $removed->delete();
    $existing = Team::create();
    ($this->assign)(Profile::sole(), $existing);
    $version = app(TenantVersion::class)->current($existing);
    SyncTestDefinitions::$definitions = [new TemplateDefinition('reader', 'Updated')];
    ($this->sync)();
    expect(app(TenantVersion::class)->current($existing))->toBe($version + 1)
        ->and(Profile::sole()->version)->toBe(2);
});
