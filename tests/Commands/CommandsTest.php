<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Backfill\AssignmentDraft;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Contracts\BackfillSource;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\AssignmentScope;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\RecordingRule;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\TestPermission;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    config()->set('access-profiles.catalogues', [TestPermission::class]);
    app()->forgetInstance(Catalogue::class);
    $this->tenant = Team::create();
    $this->other = Team::create();
    $this->holder = User::create();
    $this->site = Site::create(['team_id' => $this->tenant->id]);
    $this->profile = Grants::profile('reader', ['billing.invoice.view']);
    $this->reference = fn ($model) => $model->getMorphClass().':'.$model->getKey();
    $this->source = new class($this->tenant, $this->other) implements BackfillSource
    {
        public array $rows = [];

        public function __construct(private $first, private $second) {}

        public function name(): string
        {
            return 'fixture';
        }

        public function tenants(): iterable
        {
            return [$this->first, $this->second];
        }

        public function drafts(Model $tenant): iterable
        {
            return $this->rows[$tenant->getKey()] ?? [];
        }
    };
    app()->instance('fixture.source', $this->source);
    config()->set('access-profiles.backfill.sources', ['fixture.source']);
});

afterEach(fn () => Carbon::setTestNow());

it('clears exactly one explicit tenant even without stored access rows', function () {
    $versions = app(TenantVersion::class);
    $before = [$versions->current($this->tenant), $versions->current($this->other)];
    $this->artisan('access-profiles:cache-clear', ['--tenant' => ($this->reference)($this->tenant)])->assertExitCode(0);
    expect($versions->current($this->tenant))->toBe($before[0] + 1)
        ->and($versions->current($this->other))->toBe($before[1]);
});

it('clears all stored tenants once including profile-only tenants and skips deleted tenants', function () {
    Grants::assign($this->holder, $this->profile, $this->tenant);
    Grants::assign(User::create(), $this->profile, $this->tenant);
    Grants::profile('custom', tenant: $this->other);
    $deleted = Team::create();
    Grants::assign($this->holder, $this->profile, $deleted);
    $deleted->delete();
    $versions = app(TenantVersion::class);
    $before = [$versions->current($this->tenant), $versions->current($this->other)];
    $this->artisan('access-profiles:cache-clear')->expectsOutputToContain('Cleared 2 tenants')->assertExitCode(0);
    expect($versions->current($this->tenant))->toBe($before[0] + 1)
        ->and($versions->current($this->other))->toBe($before[1] + 1);
});

it('dry runs per-tenant counts without executing writes or changing row counts or versions', function () {
    Grants::assign($this->holder, $this->profile, $this->tenant);
    $this->source->rows[$this->tenant->id] = [
        new AssignmentDraft($this->holder, 'reader', true),
        new AssignmentDraft(User::create(), 'reader', false, [$this->site]),
        new AssignmentDraft(User::create(), 'missing', true, sourceRef: 'bad-row'),
    ];
    $version = app(TenantVersion::class)->current($this->tenant);
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes) {
        if (in_array(strtolower(strtok(ltrim($query->sql), ' ')), ['insert', 'update', 'delete', 'replace'])) {
            $writes[] = $query->sql;
        }
    });
    $this->artisan('access-profiles:backfill', ['source' => 'fixture', '--dry-run' => true])
        ->expectsOutputToContain('drafts=3 would-create=1 already-present=1 rejected=1')
        ->expectsOutputToContain('bad-row: unknown_profile')
        ->expectsOutputToContain(($this->reference)($this->other).' drafts=0')
        ->assertExitCode(1);
    expect(Assignment::count())->toBe(1)->and(AssignmentScope::count())->toBe(0)
        ->and($writes)->toBe([])->and(app(TenantVersion::class)->current($this->tenant))->toBe($version);
});

it('creates drafts as system grants preserving scope title validity profile version and reason then skips them', function () {
    $this->profile->update(['version' => 4]);
    $this->source->rows[$this->tenant->id] = [new AssignmentDraft(
        $this->holder, 'reader', false, [$this->site], 'Reviewer', now()->subDay(), now()->addDay(), 'legacy:12',
    )];
    $version = app(TenantVersion::class)->current($this->tenant);
    $this->artisan('access-profiles:backfill', ['source' => 'fixture'])->expectsOutputToContain('created=1')->assertExitCode(0);
    $row = Assignment::sole();
    expect($row->reason)->toBe('backfill:fixture legacy:12')->and($row->granted_by_id)->toBeNull()
        ->and($row->profile_version)->toBe(4)->and($row->title)->toBe('Reviewer')->and($row->covers_tenant)->toBeFalse()
        ->and($row->valid_from->equalTo(now()->subDay()))->toBeTrue()
        ->and($row->valid_until->equalTo(now()->addDay()))->toBeTrue()
        ->and($row->scopes()->sole()->scope_id)->toBe($this->site->id)
        ->and(app(TenantVersion::class)->current($this->tenant))->toBe($version + 1);
    $this->artisan('access-profiles:backfill', ['source' => 'fixture'])
        ->expectsOutputToContain('already-present=1')->expectsOutputToContain('created=0')->assertExitCode(0);
    expect(Assignment::count())->toBe(1)->and(AssignmentScope::count())->toBe(1)
        ->and(app(TenantVersion::class)->current($this->tenant))->toBe($version + 1);
});

it('rolls back only rejected tenants and continues after all rejection reasons', function ($bad) {
    $this->source->rows[$this->tenant->id] = [
        new AssignmentDraft($this->holder, 'reader', true),
        $bad($this),
    ];
    $this->source->rows[$this->other->id] = [new AssignmentDraft($this->holder, 'reader', true)];
    $versions = app(TenantVersion::class);
    $version = $versions->current($this->tenant);
    $this->artisan('access-profiles:backfill', ['source' => 'fixture'])
        ->expectsOutputToContain('created=0 rolled-back')->assertExitCode(1);
    expect(Assignment::inTenant($this->tenant)->count())->toBe(0)
        ->and(Assignment::inTenant($this->other)->count())->toBe(1)
        ->and($versions->current($this->tenant))->toBe($version);
})->with([
    'unknown profile' => fn () => fn ($test) => new AssignmentDraft(User::create(), 'missing', true),
    'foreign scope' => fn () => fn ($test) => new AssignmentDraft(User::create(), 'reader', false, [Site::create(['team_id' => $test->other->id])]),
]);

it('filters backfill to the requested tenant', function () {
    foreach ([$this->tenant, $this->other] as $tenant) {
        $this->source->rows[$tenant->id] = [new AssignmentDraft($this->holder, 'reader', true)];
    }
    $this->artisan('access-profiles:backfill', ['source' => 'fixture', '--tenant' => ($this->reference)($this->other)])->assertExitCode(0);
    expect(Assignment::sole()->tenant_id)->toBe($this->other->id);
});

it('prints only active holder assignments with scope validity and effective keys for each target', function () {
    Grants::assign($this->holder, $this->profile, $this->tenant, [$this->site], [
        'title' => 'Current reviewer', 'valid_from' => now()->subDay(), 'valid_until' => now()->addDay(),
    ]);
    Grants::assign($this->holder, Grants::profile('expired'), $this->tenant, attributes: ['valid_until' => now()]);
    Grants::assign($this->holder, Grants::profile('revoked'), $this->tenant, attributes: ['revoked_at' => now()]);
    Grants::assign($this->holder, Grants::profile('future'), $this->tenant, attributes: ['valid_from' => now()->addDay()]);
    Grants::assign($this->holder, Grants::profile('elsewhere'), $this->other);
    Grants::assign(User::create(), Grants::profile('someone_else'), $this->tenant);
    expect(Artisan::call('access-profiles:show', [
        'holder' => ($this->reference)($this->holder), 'tenant' => ($this->reference)($this->tenant),
    ]))->toBe(0);
    expect(Artisan::output())->toContain(
        'reader', 'Current reviewer', ($this->reference)($this->site),
        '2026-10-27 12:00:00', '2026-10-29 12:00:00', 'billing.invoice.view',
    )->not->toContain('expired', 'revoked', 'future', 'elsewhere', 'someone_else');
});

it('counts duplicate source drafts consistently without dry-run writes', function ($dryRun) {
    $draft = new AssignmentDraft($this->holder, 'reader', true);
    $this->source->rows[$this->tenant->id] = [$draft, $draft];
    $this->artisan('access-profiles:backfill', ['source' => 'fixture', '--dry-run' => $dryRun])
        ->expectsOutputToContain('drafts=2 would-create=1 already-present=1 rejected=0')->assertExitCode(0);
    expect(Assignment::count())->toBe($dryRun ? 0 : 1);
})->with([true, false]);

it('keeps separate profiles for one holder distinct in dry-run idempotency counts', function () {
    Grants::profile('second');
    $first = new AssignmentDraft($this->holder, 'reader', true);
    $second = new AssignmentDraft($this->holder, 'second', true);
    $this->source->rows[$this->tenant->id] = [$first, $second, $first, $second];
    $this->artisan('access-profiles:backfill', ['source' => 'fixture', '--dry-run' => true])
        ->expectsOutputToContain('drafts=4 would-create=2 already-present=2 rejected=0')->assertExitCode(0);
    expect(Assignment::count())->toBe(0);
});

it('accepts validity windows with only one bound', function () {
    $this->source->rows[$this->tenant->id] = [
        new AssignmentDraft($this->holder, 'reader', true, validFrom: now()->subDay()),
        new AssignmentDraft(User::create(), 'reader', true, validUntil: now()->addDay()),
    ];
    $this->artisan('access-profiles:backfill', ['source' => 'fixture', '--dry-run' => true])
        ->expectsOutputToContain('drafts=2 would-create=2 already-present=0 rejected=0')->assertExitCode(0);
    expect(Assignment::count())->toBe(0);
});

it('keeps future grants idempotent while allowing replacements for ended and revoked history', function () {
    foreach ([['valid_from' => now()->addDay()], ['valid_until' => now()], ['revoked_at' => now()]] as $attributes) {
        $holder = User::create();
        Grants::assign($holder, $this->profile, $this->tenant, attributes: $attributes);
        $this->source->rows[$this->tenant->id][] = new AssignmentDraft($holder, 'reader', true);
    }
    $this->artisan('access-profiles:backfill', ['source' => 'fixture'])
        ->expectsOutputToContain('drafts=3 would-create=2 already-present=1 rejected=0 created=2')->assertExitCode(0);
    expect(Assignment::count())->toBe(5);
});

it('rejects missing scopes and invalid windows on a dry run', function () {
    $this->source->rows[$this->tenant->id] = [
        new AssignmentDraft($this->holder, 'reader', false, sourceRef: 'empty'),
        new AssignmentDraft($this->holder, 'reader', true, validFrom: now(), validUntil: now(), sourceRef: 'window'),
    ];
    $this->artisan('access-profiles:backfill', ['source' => 'fixture', '--dry-run' => true])
        ->expectsOutputToContain('empty: no_scopes')->expectsOutputToContain('window: invalid_window')
        ->expectsOutputToContain('drafts=2 would-create=0 already-present=0 rejected=2')->assertExitCode(1);
    expect(Assignment::count())->toBe(0);
});

it('uses the tenant profile ahead of the template', function () {
    $copy = Grants::profile('reader', ['billing.invoice.approve'], tenant: $this->tenant);
    $this->source->rows[$this->tenant->id] = [new AssignmentDraft($this->holder, 'reader', true)];
    $this->artisan('access-profiles:backfill', ['source' => 'fixture'])->assertExitCode(0);
    expect(Assignment::sole()->profile_id)->toBe($copy->id);
});

it('keeps catalogue and host rule checks on for system backfills in dry and real runs', function ($dryRun, $failure) {
    if ($failure === 'unknown_permission') {
        $this->profile->rules()->create(['permission' => 'missing.record.view', 'effect' => 'allow']);
    } else {
        config()->set('access-profiles.guards.rules', [RecordingRule::class]);
        RecordingRule::$allows = false;
    }
    $this->source->rows[$this->tenant->id] = [new AssignmentDraft($this->holder, 'reader', true, sourceRef: 'guarded')];
    try {
        $this->artisan('access-profiles:backfill', ['source' => 'fixture', '--dry-run' => $dryRun])
            ->expectsOutputToContain('guarded: '.$failure)->assertExitCode(1);
        expect(Assignment::count())->toBe(0);
    } finally {
        RecordingRule::$allows = true;
        RecordingRule::$calls = [];
    }
})->with([true, false])->with(['unknown_permission', 'rule:board_seat']);

it('reports invalid source names and implementations without writing', function () {
    $this->artisan('access-profiles:backfill', ['source' => 'missing'])
        ->expectsOutputToContain('Unknown backfill source: missing')->assertExitCode(1);
    config()->set('access-profiles.backfill.sources', [stdClass::class]);
    $this->artisan('access-profiles:backfill', ['source' => 'fixture'])
        ->expectsOutputToContain('Sources must implement')->assertExitCode(1);
    expect(Assignment::count())->toBe(0);
});

it('supports morph aliases and rejects invalid or missing model references', function () {
    Relation::morphMap(['team' => Team::class, 'user' => User::class]);
    try {
        $this->artisan('access-profiles:cache-clear', ['--tenant' => 'team:'.$this->tenant->id])->assertExitCode(0);
        $this->artisan('access-profiles:show', ['holder' => 'user:'.$this->holder->id, 'tenant' => 'team:'.$this->tenant->id])->assertExitCode(0);
        $this->artisan('access-profiles:cache-clear', ['--tenant' => 'invalid'])->expectsOutputToContain('Expected morph-type:primary-key')->assertExitCode(1);
        $this->artisan('access-profiles:cache-clear', ['--tenant' => 'invalid:1'])->expectsOutputToContain('Expected morph-type:primary-key')->assertExitCode(1);
        $this->artisan('access-profiles:cache-clear', ['--tenant' => 'team:'])->expectsOutputToContain('Expected morph-type:primary-key')->assertExitCode(1);
        $this->artisan('access-profiles:cache-clear', ['--tenant' => 'team:999'])->expectsOutputToContain('Model not found')->assertExitCode(1);
        $this->artisan('access-profiles:show', ['holder' => 'missing', 'tenant' => 'team:1'])->assertExitCode(1);
    } finally {
        Relation::morphMap([], false);
    }
});

it('rejects a foreign scope during dry-run preflight without writing', function () {
    $this->source->rows[$this->tenant->id] = [new AssignmentDraft(
        $this->holder, 'reader', false, [Site::create(['team_id' => $this->other->id])], sourceRef: 'foreign',
    )];
    $this->artisan('access-profiles:backfill', ['source' => 'fixture', '--dry-run' => true])
        ->expectsOutputToContain('foreign: scope_outside_tenant')->assertExitCode(1);
    expect(Assignment::count())->toBe(0);
});

it('rejects a scope passed as the tenant for commands', function () {
    $this->artisan('access-profiles:cache-clear', ['--tenant' => ($this->reference)($this->site)])
        ->expectsOutputToContain('not_a_tenant')->assertExitCode(1);
    $this->artisan('access-profiles:show', ['holder' => ($this->reference)($this->holder), 'tenant' => ($this->reference)($this->site)])
        ->expectsOutputToContain('not_a_tenant')->assertExitCode(1);
    $this->source = new class($this->site, $this->holder) implements BackfillSource
    {
        public function __construct(private $tenant, private $holder) {}

        public function name(): string
        {
            return 'scope-tenant';
        }

        public function tenants(): iterable
        {
            return [$this->tenant];
        }

        public function drafts(Model $tenant): iterable
        {
            return [new AssignmentDraft($this->holder, 'reader', true)];
        }
    };
    app()->instance('fixture.source', $this->source);
    $this->artisan('access-profiles:backfill', ['source' => 'scope-tenant', '--dry-run' => true])
        ->expectsOutputToContain('not_a_tenant')->assertExitCode(1);
});

it('renders assignment table columns and effective keys at both tenant and scope', function () {
    $first = Grants::assign($this->holder, $this->profile, $this->tenant);
    $second = Grants::assign($this->holder, Grants::profile('scoped'), $this->tenant, [$this->site]);
    $this->artisan('access-profiles:show', [
        'holder' => ($this->reference)($this->holder), 'tenant' => ($this->reference)($this->tenant),
    ])->expectsTable(['Assignment', 'Profile', 'Title', 'Scopes', 'Valid from', 'Valid until'], [
        [$first->id, 'reader', '', 'whole tenant', 'unbounded', 'unbounded'],
        [$second->id, 'scoped', '', ($this->reference)($this->site), 'unbounded', 'unbounded'],
    ])->expectsOutputToContain('Effective keys '.($this->reference)($this->tenant).': billing.invoice.view')
        ->expectsOutputToContain('Effective keys '.($this->reference)($this->site).': billing.invoice.view')->assertExitCode(0);
});

it('includes the explicit tenant in cache-clear output and returns after clearing it', function () {
    Grants::assign($this->holder, $this->profile, $this->tenant);
    $version = app(TenantVersion::class)->current($this->tenant);
    $this->artisan('access-profiles:cache-clear', ['--tenant' => ($this->reference)($this->tenant)])
        ->expectsOutputToContain('Cleared '.($this->reference)($this->tenant))->assertExitCode(0);
    expect(app(TenantVersion::class)->current($this->tenant))->toBe($version + 1);
});
