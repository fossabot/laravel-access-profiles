<?php

namespace Seisigma\AccessProfiles\Tests\Docs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Concerns\HasAccessProfiles;
use Seisigma\AccessProfiles\Contracts\PermissionCatalogue;
use Seisigma\AccessProfiles\Contracts\TemplateDefinitions;
use Seisigma\AccessProfiles\Events\AccessChecked;
use Seisigma\AccessProfiles\Events\AssignmentGranted;
use Seisigma\AccessProfiles\Events\AssignmentUpdated;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Templates\TemplateDefinition;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;

// Independent copies of the README definitions. No documentation is read by these tests.
enum Permission: string implements PermissionCatalogue
{
    case View = 'billing.invoice.view';
    case Approve = 'billing.invoice.approve';

    public static function keys(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}

class Templates implements TemplateDefinitions
{
    public static function templates(): iterable
    {
        return [new TemplateDefinition(
            key: 'reviewer',
            name: 'Invoice reviewer',
            allow: ['billing.*'],
            deny: ['billing.invoice.approve'],
        )];
    }
}

class User extends Authenticatable
{
    use HasAccessProfiles;
}

// The host owns Invoice and the Inertia middleware parent. Only their boundary
// contracts are fixtures here; the policy and share methods are copied below.
class Invoice extends Model
{
    protected $guarded = [];

    public function isPending(): bool
    {
        return $this->getAttribute('status') === 'pending';
    }
}

class InvoicePolicy
{
    public function approve(User $user, Invoice $invoice): bool
    {
        return $user->canAccess('billing.invoice.approve', $invoice->site)
            && $invoice->isPending()
            && $invoice->created_by !== $user->id;
    }
}

class HostInertiaMiddleware
{
    public function share(Request $request): array
    {
        return ['host' => 'shared'];
    }
}

class HandleInertiaRequests extends HostInertiaMiddleware
{
    public function share(Request $request): array
    {
        $currentScope = $request->attributes->get('currentScope');

        return [
            ...parent::share($request),
            'access' => $request->user() && $currentScope
                ? Access::for($request->user())->permissionsIn($currentScope)
                : [],
        ];
    }
}

class ExportInvoices implements ShouldQueue
{
    use SerializesModels;

    public function __construct(public User $actor, public Site $site) {}

    public function handle(): void
    {
        Access::for($this->actor)->authorize('billing.invoice.view', $this->site);
        // Export invoices here.
    }
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    config()->set('access-profiles.catalogues', [Permission::class]);
    config()->set('access-profiles.templates', Templates::class);
    config()->set('auth.providers.users.model', User::class);
    config()->set('access-profiles.cache.store', 'array');
    app()->forgetInstance(Catalogue::class);
    $this->artisan('access-profiles:sync-templates')->assertExitCode(0);
    $this->team = Team::create();
    $this->site = Site::create(['team_id' => $this->team->id]);
    $this->otherSite = Site::create(['team_id' => $this->team->id]);
    $this->user = User::create();
    $this->admin = User::create();
    Grants::assign($this->admin, Grants::profile('administrator', Permission::keys()), $this->team);
});

afterEach(fn () => Carbon::setTestNow());

it('registers the catalogue enum keys', function () {
    expect(app(Catalogue::class)->all())->toBe(['billing.invoice.view', 'billing.invoice.approve'])
        ->and(app(Catalogue::class)->expand('billing.*'))->toBe(Permission::keys());
    $this->artisan('access-profiles:census')->assertExitCode(0);
});

it('syncs the template definition with exact allow and deny rules', function () {
    $profile = Profile::where('key', 'reviewer')->sole();
    expect($profile->is_system)->toBeTrue()->and($profile->tenant_id)->toBeNull()
        ->and($profile->name)->toBe('Invoice reviewer')
        ->and($profile->rules()->orderBy('permission')->get()
            ->mapWithKeys(fn ($rule) => [$rule->permission => $rule->effect->value])->all())
        ->toBe(['billing.invoice.approve' => 'deny', 'billing.invoice.view' => 'allow']);
    $this->artisan('access-profiles:sync-templates')->assertExitCode(0);
    expect($profile->fresh()->version)->toBe(1);
});

it('copies and edits a tenant profile', function () {
    ['team' => $team, 'admin' => $admin] = get_object_vars($this);
    $template = Profile::where('key', 'reviewer')->sole();

    $copy = Access::profile('reviewer')->in($team)
        ->by($admin)->because('Use a local review profile')->copyFromTemplate();
    $profile = Access::profile('reviewer')->in($team)
        ->allow('billing.*')->deny('billing.invoice.approve')
        ->by($admin)->because('Keep approvals separate')->save();

    expect($copy->based_on_id)->toBe($template->id)
        ->and($profile->id)->toBe($copy->id)->and($profile->tenant->is($team))->toBeTrue()
        ->and($profile->is_system)->toBeFalse()->and($profile->rules()->count())->toBe(2)
        ->and($template->fresh()->version)->toBe(1);
    Grants::assign($this->user, $profile, $team);
    expect($this->user->canAccess('billing.invoice.view', $this->site))->toBeTrue()
        ->and($this->user->canAccess('billing.invoice.approve', $this->site))->toBeFalse();
});

it('grants a dated assignment with actor reason title and scopes', function () {
    ['team' => $team, 'site' => $site, 'otherSite' => $otherSite, 'user' => $user, 'admin' => $admin] = get_object_vars($this);

    $assignment = Access::grant('reviewer')->to($user)->in($team)
        ->onlyScopes([$site, $otherSite])->titled('Review cover')
        ->validFrom(now())->validUntil(now()->addMonths(6))
        ->by($admin)->because('Cover leave')->save();

    $row = $assignment->fresh();
    expect($row->grantedBy->is($admin))->toBeTrue()->and($row->reason)->toBe('Cover leave')
        ->and($row->title)->toBe('Review cover')->and($row->covers_tenant)->toBeFalse()
        ->and($row->scopes()->orderBy('scope_id')->pluck('scope_id')->all())->toBe([$site->id, $otherSite->id])
        ->and($row->valid_from->equalTo(now()))->toBeTrue()
        ->and($row->valid_until->equalTo(now()->addMonths(6)))->toBeTrue()
        ->and($user->canAccess('billing.invoice.view', $site))->toBeTrue()
        ->and($user->canAccess('billing.invoice.view', $team))->toBeFalse();
});

it('grants as a system actor', function () {
    ['team' => $team, 'user' => $user] = get_object_vars($this);
    Event::fake([AssignmentGranted::class]);

    $assignment = Access::grant('reviewer')->to($user)->in($team)
        ->bySystem('seed: initial reviewer')->save();

    expect($assignment->fresh()->reason)->toBe('seed: initial reviewer')
        ->and($assignment->granted_by_id)->toBeNull()->and($assignment->covers_tenant)->toBeTrue()
        ->and($user->canAccess('billing.invoice.view', $team))->toBeTrue();
    Event::assertDispatched(AssignmentGranted::class, fn ($event) => $event->system
        && $event->actor === null && $event->reason === 'seed: initial reviewer');
});

it('changes scope title and expiry while preserving the grant reason', function () {
    ['team' => $team, 'site' => $site, 'user' => $user, 'admin' => $admin] = get_object_vars($this);
    $assignment = Access::grant('reviewer')->to($user)->in($team)->by($admin)->because('Cover leave')->save();
    Event::fake([AssignmentUpdated::class]);

    $assignment = Access::change($assignment)->onlyScopes([$site])
        ->titled('Extended review cover')->validUntil(now()->addYear())
        ->by($admin)->because('Extend the cover')->save();

    expect($assignment->fresh()->title)->toBe('Extended review cover')
        ->and($assignment->valid_until->equalTo(now()->addYear()))->toBeTrue()
        ->and($assignment->reason)->toBe('Cover leave')
        ->and($user->canAccess('billing.invoice.view', $site))->toBeTrue()
        ->and($user->canAccess('billing.invoice.view', $this->otherSite))->toBeFalse();
    Event::assertDispatched(AssignmentUpdated::class, fn ($event) => $event->actor->is($admin)
        && $event->reason === 'Extend the cover' && $event->before['covers_tenant'] && ! $event->after['covers_tenant']);
});

it('revokes access and keeps its history row', function () {
    ['team' => $team, 'user' => $user, 'admin' => $admin] = get_object_vars($this);
    $assignment = Access::grant('reviewer')->to($user)->in($team)->by($admin)->save();

    $assignment = Access::revoke($assignment)
        ->by($admin)->because('Cover ended')->save();

    expect($assignment->fresh()->revoked_at->equalTo(now()))->toBeTrue()
        ->and($assignment->revokedBy->is($admin))->toBeTrue()
        ->and($assignment->revoke_reason)->toBe('Cover ended')
        ->and(Assignment::find($assignment->id))->not->toBeNull()
        ->and($user->canAccess('billing.invoice.view', $team))->toBeFalse()
        ->and(Access::history($user, $team)->modelKeys())->toBe([$assignment->id]);
});

it('adds the holder trait without replacing Laravel Gate', function () {
    $assignment = Access::grant('reviewer')->to($this->user)->in($this->team)->by($this->admin)->save();

    expect($this->user->accessAssignments()->get()->modelKeys())->toBe([$assignment->id])
        ->and($this->user->canAccess('billing.invoice.view', $this->site))->toBeTrue()
        ->and($this->user->can('billing.invoice.view', $this->site))->toBeFalse();
});

it('checks explains lists and authorizes access', function () {
    ['team' => $team, 'site' => $site, 'user' => $user, 'admin' => $admin] = get_object_vars($this);
    Access::grant('reviewer')->to($user)->in($team)->onlyScopes([$site])->by($admin)->save();
    Event::fake([AccessChecked::class]);

    $allowed = Access::for($user)->can('billing.invoice.view', $site);
    $sameAnswer = $user->canAccess('billing.invoice.view', $site);
    $decision = Access::for($user)->explain('billing.invoice.approve', $site);
    $keys = Access::for($user)->permissionsIn($site);
    Access::for($user)->authorize('billing.invoice.view', $site);

    expect($allowed)->toBeTrue()->and($sameAnswer)->toBeTrue()
        ->and($decision->allowed)->toBeFalse()->and($keys)->toBe(['billing.invoice.view'])
        ->and($user->canAccess('billing.invoice.view', $this->otherSite))->toBeFalse();
    Event::assertNotDispatched(AccessChecked::class);
    expect(fn () => Access::for($user)->authorize('billing.invoice.approve', $site))
        ->toThrow(AuthorizationException::class);
});

it('renders the Blade directive only for an allowed authenticated holder', function () {
    Access::grant('reviewer')->to($this->user)->in($this->team)->onlyScopes([$this->site])->by($this->admin)->save();
    $template = "@canAccess('billing.invoice.view', \$site)\nView invoices\n@endcanAccess";
    $this->actingAs($this->user);
    expect(trim(Blade::render($template, ['site' => $this->site])))->toBe('View invoices')
        ->and(trim(Blade::render($template, ['site' => $this->otherSite])))->toBe('');
    auth()->forgetGuards();
    expect(trim(Blade::render($template, ['site' => $this->site])))->toBe('');
});

it('queries holders scopes and revoked history', function () {
    ['team' => $team, 'site' => $site, 'user' => $user, 'admin' => $admin] = get_object_vars($this);
    $past = Access::grant('reviewer')->to($user)->in($team)->by($admin)->save();
    Access::revoke($past)->by($admin)->because('Replace scope')->save();
    $active = Access::grant('reviewer')->to($user)->in($team)->onlyScopes([$site])->by($admin)->save();
    Site::create(['team_id' => Team::create()->id]);

    $holders = Access::holders('billing.invoice.view', $site);
    $count = Access::holdersQuery('billing.invoice.view', $site)->count();
    $scopes = Access::for($user)->scopesWhere('billing.invoice.view', $team);
    $allSites = $scopes->coversTenant();
    $siteIds = $scopes->ids(Site::class);
    $sites = $scopes->constrain(
        Site::query()->where('team_id', $team->getKey()), 'id', Site::class,
    )->get();
    $history = Access::history($user, $team);

    expect($holders->modelKeys())->toEqualCanonicalizing([$user->id, $admin->id])
        ->and($count)->toBe(2)->and($allSites)->toBeFalse()->and($siteIds)->toBe([$site->id])
        ->and($sites->modelKeys())->toBe([$site->id])
        ->and($history->modelKeys())->toEqualCanonicalizing([$past->id, $active->id]);
});

it('logs the grant event actor reason and snapshots', function () {
    Log::spy();

    Event::listen(AssignmentGranted::class, function (AssignmentGranted $event): void {
        Log::info('Access granted', [
            'actor' => $event->actor?->getKey(),
            'system' => $event->system,
            'reason' => $event->reason,
            'before' => $event->before,
            'after' => $event->after,
        ]);
    });

    $assignment = Access::grant('reviewer')->to($this->user)->in($this->team)
        ->by($this->admin)->because('Review appointment')->save();
    Log::shouldHaveReceived('info')->once()->with('Access granted', \Mockery::on(fn ($context) => $context['actor'] === $this->admin->id && $context['system'] === false
        && $context['reason'] === 'Review appointment' && $context['before'] === null
        && $context['after']['id'] === $assignment->id));
});

it('shares only current scope keys through the Inertia share method', function () {
    Access::grant('reviewer')->to($this->user)->in($this->team)->onlyScopes([$this->site])->by($this->admin)->save();
    $request = Request::create('/sites/1');
    $request->setUserResolver(fn () => $this->user);
    $request->attributes->set('currentScope', $this->site);
    $middleware = new HandleInertiaRequests;

    expect($middleware->share($request))->toBe(['host' => 'shared', 'access' => ['billing.invoice.view']]);
    $request->attributes->set('currentScope', $this->otherSite);
    expect($middleware->share($request)['access'])->toBe([]);
    $request->attributes->remove('currentScope');
    expect($middleware->share($request)['access'])->toBe([]);
    $request->attributes->set('currentScope', $this->site);
    $request->setUserResolver(fn () => null);
    expect($middleware->share($request)['access'])->toBe([]);
});

it('combines canAccess with policy workflow rules', function () {
    $profile = Grants::profile('approver', ['billing.invoice.approve']);
    $assignment = Access::grant($profile)->to($this->user)->in($this->team)->by($this->admin)->save();
    $invoice = new Invoice(['status' => 'pending', 'created_by' => $this->admin->id]);
    $invoice->setRelation('site', $this->site);
    $policy = new InvoicePolicy;

    expect($policy->approve($this->user, $invoice))->toBeTrue();
    $invoice->created_by = $this->user->id;
    expect($policy->approve($this->user, $invoice))->toBeFalse();
    $invoice->created_by = $this->admin->id;
    $invoice->status = 'approved';
    expect($policy->approve($this->user, $invoice))->toBeFalse();
    $invoice->status = 'pending';
    Access::revoke($assignment)->by($this->admin)->save();
    expect($policy->approve($this->user, $invoice))->toBeFalse();
});

it('rechecks serialized queued job access at execution time', function () {
    $assignment = Access::grant('reviewer')->to($this->user)->in($this->team)->onlyScopes([$this->site])->by($this->admin)->save();
    $payload = serialize(new ExportInvoices($this->user, $this->site));
    $job = unserialize($payload);
    $job->handle();
    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->actor->is($this->user))->toBeTrue()->and($job->site->is($this->site))->toBeTrue();

    Access::revoke($assignment)->by($this->admin)->because('Revoked while queued')->save();
    app()->forgetScopedInstances();
    $job = unserialize($payload);
    expect(fn () => $job->handle())->toThrow(AuthorizationException::class);
});
