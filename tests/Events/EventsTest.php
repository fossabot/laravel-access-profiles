<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Seisigma\AccessProfiles\Builders\AssignmentBuilder;
use Seisigma\AccessProfiles\Builders\ChangeBuilder;
use Seisigma\AccessProfiles\Builders\GrantBuilder;
use Seisigma\AccessProfiles\Builders\ProfileBuilder;
use Seisigma\AccessProfiles\Builders\RecordsActor;
use Seisigma\AccessProfiles\Builders\RevokeBuilder;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Contracts\TemplateDefinitions;
use Seisigma\AccessProfiles\Events\AccessChecked;
use Seisigma\AccessProfiles\Events\AssignmentGranted;
use Seisigma\AccessProfiles\Events\AssignmentRevoked;
use Seisigma\AccessProfiles\Events\AssignmentUpdated;
use Seisigma\AccessProfiles\Events\ProfileCreated;
use Seisigma\AccessProfiles\Events\ProfileDeleted;
use Seisigma\AccessProfiles\Events\ProfileUpdated;
use Seisigma\AccessProfiles\Events\Snapshot;
use Seisigma\AccessProfiles\Events\TemplatesSynced;
use Seisigma\AccessProfiles\Events\WriteEvent;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Resolver\DatabaseResolver;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Templates\TemplateDefinition;
use Seisigma\AccessProfiles\Templates\TemplateSync;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Rejection;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\TestPermission;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(
    RecordsActor::class,
    AssignmentBuilder::class,
    GrantBuilder::class,
    ChangeBuilder::class,
    RevokeBuilder::class,
    ProfileBuilder::class,
    DatabaseResolver::class,
    TemplateSync::class,
    WriteEvent::class,
    Snapshot::class,
    AccessChecked::class, AssignmentGranted::class, AssignmentUpdated::class, AssignmentRevoked::class,
    ProfileCreated::class, ProfileUpdated::class, ProfileDeleted::class, TemplatesSynced::class,
);

class EventTemplates implements TemplateDefinitions
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
    config()->set('access-profiles.cache.store', 'array');
    config()->set('access-profiles.catalogues', [TestPermission::class]);
    app()->forgetInstance(Catalogue::class);
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->tenant = Team::create();
    $this->actor = User::create();
    $this->holder = User::create();
    $this->profile = Grants::profile('reader', ['billing.invoice.view']);
    Grants::assign($this->actor, $this->profile, $this->tenant);
    Event::fake([AssignmentGranted::class, AssignmentUpdated::class, AssignmentRevoked::class,
        ProfileCreated::class, ProfileUpdated::class, ProfileDeleted::class, TemplatesSynced::class, AccessChecked::class]);
});

afterEach(fn () => Carbon::setTestNow());

it('records one immutable before and after event for each assignment write', function () {
    $site = Site::create(['team_id' => $this->tenant->id]);
    $row = Access::grant('reader')->to($this->holder)->in($this->tenant)->by($this->actor)->because('Appointed')->save();
    Access::change($row)->onlyScopes([$site])->titled('Reader')->by($this->actor)->because('Limited')->save();
    Access::revoke($row)->by($this->actor)->because('Ended')->save();

    Event::assertDispatchedTimes(AssignmentGranted::class, 1);
    Event::assertDispatchedTimes(AssignmentUpdated::class, 1);
    Event::assertDispatchedTimes(AssignmentRevoked::class, 1);
    Event::assertDispatched(AssignmentGranted::class, fn ($e) => $e->actor->is($this->actor) && ! $e->system
        && $e->reason === 'Appointed' && $e->before === null && $e->after['id'] === $row->id && $e->after['scopes'] === [] && $e->after['revoked_at'] === null);
    Event::assertDispatched(AssignmentUpdated::class, fn ($e) => $e->actor->is($this->actor) && ! $e->system && $e->reason === 'Limited'
        && $e->before['covers_tenant'] === true && $e->before['title'] === null && $e->before['scopes'] === []
        && $e->after['title'] === 'Reader' && $e->after['covers_tenant'] === false && $e->after['scopes'][0]['scope_id'] === $site->id
        && $e->after['scopes'][0]['scope_type'] === $site->getMorphClass());
    Event::assertDispatched(AssignmentRevoked::class, fn ($e) => $e->actor->is($this->actor) && ! $e->system && $e->reason === 'Ended'
        && $e->before['revoked_at'] === null && $e->after['revoked_at'] !== null && $e->after['revoke_reason'] === 'Ended');
});

it('shares actor transitions and reasons on profile saves copies and updates', function () {
    $copy = Access::profile('reader')->in($this->tenant)->bySystem('Seed')->copyFromTemplate();
    Access::profile('reader')->in($this->tenant)->bySystem('Old')->by($this->actor)->because('Restrict')->deny('billing.invoice.view')->save();
    Access::profile('other')->in($this->tenant)->by($this->actor)->bySystem('Import')->because('Final')->save();
    Event::assertDispatchedTimes(ProfileCreated::class, 2);
    Event::assertDispatchedTimes(ProfileUpdated::class, 1);
    Event::assertDispatched(ProfileCreated::class, fn ($e) => $e->after['id'] === $copy->id && $e->actor === null && $e->system
        && $e->reason === 'Seed' && $e->before === null && $e->after['rules'] === ['billing.invoice.view' => 'allow']);
    Event::assertDispatched(ProfileCreated::class, fn ($e) => $e->after['key'] === 'other' && $e->actor === null && $e->system && $e->reason === 'Final');
    Event::assertDispatched(ProfileUpdated::class, fn ($e) => $e->actor->is($this->actor) && ! $e->system && $e->reason === 'Restrict'
        && $e->before['rules'] === ['billing.invoice.view' => 'allow'] && $e->after['rules'] === ['billing.invoice.view' => 'deny']);
});

it('soft deletes an unused tenant profile after commit and preserves its rules', function () {
    $copy = Access::profile('reader')->in($this->tenant)->copyFromTemplate();
    $versions = app(TenantVersion::class);
    $version = $versions->current($this->tenant);
    DB::beginTransaction();
    Access::profile('reader')->in($this->tenant)->by($this->actor)->because('Retired')->delete();
    Event::assertNotDispatched(ProfileDeleted::class);
    expect($versions->current($this->tenant))->toBe($version);
    DB::commit();
    expect(Profile::find($copy->id))->toBeNull()
        ->and(Profile::withTrashed()->findOrFail($copy->id)->trashed())->toBeTrue()
        ->and($copy->rules()->count())->toBe(1)
        ->and($versions->current($this->tenant))->toBe($version + 1);
    Event::assertDispatchedTimes(ProfileDeleted::class, 1);
    Event::assertDispatched(ProfileDeleted::class, fn ($e) => $e->actor->is($this->actor) && ! $e->system && $e->reason === 'Retired'
        && $e->before['deleted_at'] === null && $e->after['deleted_at'] !== null);
});

it('refuses deletion with any active assignment even in system mode', function () {
    $copy = Access::profile('reader')->in($this->tenant)->copyFromTemplate();
    Grants::assign($this->holder, $copy, $this->tenant, attributes: ['valid_from' => now(), 'valid_until' => now()->addSecond()]);
    expect(Rejection::of(fn () => Access::profile('reader')->in($this->tenant)->bySystem('Cleanup')->delete()))->toBe('profile_in_use')
        ->and($copy->fresh()->trashed())->toBeFalse();
    Event::assertNotDispatched(ProfileDeleted::class);
});

it('allows deletion when assignments are inactive', function (string $state) {
    $copy = Access::profile('reader')->in($this->tenant)->copyFromTemplate();
    $row = Grants::assign($this->holder, $copy, $this->tenant);
    $row->update(match ($state) {
        'revoked' => ['revoked_at' => now()],
        'expired' => ['valid_until' => now()],
        'future' => ['valid_from' => now()->addSecond()],
    });
    Access::profile('reader')->in($this->tenant)->bySystem('Cleanup')->delete();
    expect(Profile::withTrashed()->findOrFail($copy->id)->trashed())->toBeTrue()->and($row->fresh())->not->toBeNull();
})->with(['revoked', 'expired', 'future']);

it('refuses deletion of templates and missing or foreign copies', function () {
    expect(Rejection::of(fn () => Access::profile('reader')->bySystem('Cleanup')->delete()))->toBe(GrantRejected::SYSTEM_TEMPLATE);
    $flagged = Grants::profile('locked', [], tenant: $this->tenant);
    $flagged->update(['is_system' => true]);
    expect(Rejection::of(fn () => Access::profile('locked')->in($this->tenant)->delete()))->toBe(GrantRejected::SYSTEM_TEMPLATE);
    Grants::profile('foreign', [], tenant: Team::create());
    expect(Rejection::of(fn () => Access::profile('foreign')->in($this->tenant)->delete()))->toBe(GrantRejected::UNKNOWN_PROFILE);
    Event::assertNotDispatched(ProfileDeleted::class);
});

it('fires nothing for a rejected grant', function () {
    expect(Rejection::of(fn () => Access::grant('reader')->to($this->holder)->in($this->tenant)->save()))->toBe(GrantRejected::MISSING_GRANTOR);
    Event::assertNothingDispatched();
});

it('defers a grant event until the outer transaction commits', function () {
    DB::beginTransaction();
    Access::grant('reader')->to($this->holder)->in($this->tenant)->bySystem('Seed')->save();
    Event::assertNotDispatched(AssignmentGranted::class);
    DB::commit();
    Event::assertDispatchedTimes(AssignmentGranted::class, 1);
    Event::assertDispatched(AssignmentGranted::class, fn ($e) => $e->actor === null && $e->system && $e->reason === 'Seed');
});

it('discards grant and profile deletion events and cache bumps on rollback', function () {
    $copy = Access::profile('reader')->in($this->tenant)->copyFromTemplate();
    $version = app(TenantVersion::class)->current($this->tenant);
    DB::beginTransaction();
    Access::grant($this->profile)->to($this->holder)->in($this->tenant)->bySystem('Seed')->save();
    Access::profile('reader')->in($this->tenant)->bySystem('Cleanup')->delete();
    DB::rollBack();
    expect($copy->fresh()->trashed())->toBeFalse()->and(app(TenantVersion::class)->current($this->tenant))->toBe($version);
    Event::assertNotDispatched(AssignmentGranted::class);
    Event::assertNotDispatched(ProfileDeleted::class);
});

it('keeps access checks off by default and emits each decision including memo hits when enabled', function () {
    expect(config('access-profiles.events.access_checked'))->toBeFalse();
    Access::for($this->actor)->can('billing.invoice.view', $this->tenant);
    Event::assertNotDispatched(AccessChecked::class);
    config()->set('access-profiles.events', []);
    Access::for($this->actor)->can('billing.invoice.view', $this->tenant);
    Event::assertNotDispatched(AccessChecked::class);
    config()->set('access-profiles.events.access_checked', true);
    $decision = Access::for($this->actor)->explain('billing.invoice.view', $this->tenant);
    Access::for($this->actor)->can('billing.invoice.view', $this->tenant);
    Access::for($this->holder)->can('billing.invoice.view', $this->tenant);
    Event::assertDispatchedTimes(AccessChecked::class, 3);
    Event::assertDispatched(AccessChecked::class, fn ($e) => $e->actor->is($this->actor) && $e->target->is($this->tenant)
        && $e->decision === $decision && $e->decision->allowed);
    Event::assertDispatched(AccessChecked::class, fn ($e) => $e->actor->is($this->holder) && ! $e->decision->allowed);
});

it('defers all other write events and discards them on rollback', function () {
    $row = Grants::assign($this->holder, $this->profile, $this->tenant);
    $copy = Grants::profile('copy', ['billing.invoice.view'], tenant: $this->tenant);
    config()->set('access-profiles.templates', EventTemplates::class);
    EventTemplates::$definitions = [new TemplateDefinition('reader', 'Changed')];
    $version = app(TenantVersion::class)->current($this->tenant);
    DB::beginTransaction();
    Access::profile('new')->in($this->tenant)->bySystem('Seed')->save();
    Access::profile('copy')->in($this->tenant)->bySystem('Change')->named('Changed')->save();
    Access::change($row)->bySystem('Change')->titled('Changed')->save();
    Access::revoke($row)->bySystem('Remove')->save();
    $this->artisan('access-profiles:sync-templates')->assertExitCode(0);
    Event::assertNothingDispatched();
    DB::rollBack();
    Event::assertNothingDispatched();
    expect($row->fresh()->title)->toBeNull()->and($row->fresh()->revoked_at)->toBeNull()
        ->and($copy->fresh()->name)->toBe('Copy')->and(Profile::where('key', 'new')->exists())->toBeFalse()
        ->and(app(TenantVersion::class)->current($this->tenant))->toBe($version);
});

it('dispatches profile updates with metadata snapshots and nullable actor context', function () {
    Access::profile('local')->in($this->tenant)->save();
    Access::profile('local')->in($this->tenant)->named('Renamed', 'Details')->save();
    Event::assertDispatched(ProfileUpdated::class, fn ($e) => $e->actor === null && ! $e->system && $e->reason === null
        && $e->before['name'] === 'local' && $e->after['name'] === 'Renamed' && $e->after['description'] === 'Details');
});

it('emits one sync event with the changed template states and none on dry run or no change', function () {
    config()->set('access-profiles.templates', EventTemplates::class);
    EventTemplates::$definitions = [new TemplateDefinition('synced', 'Synced', allow: ['billing.invoice.view'])];
    $this->artisan('access-profiles:sync-templates', ['--dry-run' => true])->assertExitCode(0);
    Event::assertNotDispatched(TemplatesSynced::class);
    $this->artisan('access-profiles:sync-templates')->assertExitCode(0);
    Event::assertDispatchedTimes(TemplatesSynced::class, 1);
    Event::assertDispatched(TemplatesSynced::class, fn ($e) => $e->actor === null && $e->system && $e->reason === 'access-profiles:sync-templates'
        && $e->before[0]['key'] === 'reader' && $e->after[0]['key'] === 'synced' && $e->after[0]['rules'] === ['billing.invoice.view' => 'allow']);
    $this->artisan('access-profiles:sync-templates')->assertExitCode(0);
    Event::assertDispatchedTimes(TemplatesSynced::class, 1);
    EventTemplates::$definitions = [];
    $this->artisan('access-profiles:sync-templates')->assertExitCode(0);
    Event::assertDispatchedTimes(TemplatesSynced::class, 2);
    Event::assertDispatched(TemplatesSynced::class, fn ($e) => ($e->before[0]['deleted_at'] ?? 'missing') === 'missing'
        && $e->after[0]['deleted_at'] !== null);
});
