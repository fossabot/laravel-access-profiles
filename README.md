# Laravel Access Profiles

**Status: released 0.1.0 (2026-10-28).** Requires PHP 8.3+ and Laravel 12.69+ or 13.30+. See [Supported versions](#supported-versions).

Give people different permissions in different parts of a tenant. Profiles combine allow and deny rules. Assignments cover a whole tenant or selected scopes, with validity dates and a record of who granted access and why. Any matching deny wins. Access is denied by default.

Tenants, scopes and holders are Eloquent models. A team can be a tenant and its sites can be scopes. Tenant and scope models implement `Seisigma\AccessProfiles\Contracts\HasAccessTenant`. Their `accessTenant(): Model` method returns the tenant: `$this` on a team, `$this->team` on a site.

There is no global current tenant or owner bypass. Checks always take a target. Runtime dependencies are PHP and `illuminate/*`. See [DESIGN.md](docs/DESIGN.md) for the decisions and [COMPARISON.md](docs/COMPARISON.md) for alternatives.

## Supported versions

CI runs every row on its lowest and its newest allowed release.

| Package | PHP | Laravel | Testbench |
|---|---|---|---|
| 0.1.x | 8.3, 8.4, 8.5 | 12.69+ | 10.x |
| 0.1.x | 8.3, 8.4, 8.5 | 13.30+ | 11.x |

The minimums are the first Laravel releases with no open security advisory. Composer refuses older releases.

## Install

1. Run `composer require seisigmasrl/laravel-access-profiles`.
2. Run `php artisan vendor:publish --tag=access-profiles-config`.
3. Run `php artisan vendor:publish --tag=access-profiles-migrations`.
4. Configure the catalogue and templates below. Choose table names before migrating.
5. Run `php artisan migrate` against your intended database.
6. Run `php artisan access-profiles:sync-templates`. Run it again when deploying changed definitions.

## Catalogue and templates

Define `App\Access\Permission`. Keys use `module.resource.action` in lower snake case.

```php
use Seisigma\AccessProfiles\Contracts\PermissionCatalogue;

enum Permission: string implements PermissionCatalogue
{
    case View = 'billing.invoice.view';
    case Approve = 'billing.invoice.approve';

    public static function keys(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
```

Define `App\Access\Templates`. Wildcards expand to exact catalogue keys at write time.

```php
use Seisigma\AccessProfiles\Contracts\TemplateDefinitions;
use Seisigma\AccessProfiles\Templates\TemplateDefinition;

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
```

In `config/access-profiles.php`, set `catalogues` to `[App\Access\Permission::class]` and `templates` to `App\Access\Templates::class` before the package boots. Sync creates global system templates. Unchanged definitions keep their version. Removed templates are soft-deleted. Tenant copies are independent.

## Profiles and assignments

Import `Seisigma\AccessProfiles\Facades\Access` for the examples below. `$team`, `$site`, `$otherSite`, `$user` and `$admin` are saved host models. Both sites belong to the team. The admin already holds the profile's allow keys throughout that team.

Create a tenant copy, then adjust its rules. `copyFromTemplate()` saves immediately.

```php
$copy = Access::profile('reviewer')->in($team)
    ->by($admin)->because('Use a local review profile')->copyFromTemplate();
$profile = Access::profile('reviewer')->in($team)
    ->allow('billing.*')->deny('billing.invoice.approve')
    ->by($admin)->because('Keep approvals separate')->save();
```

Grant scope access. Omit `onlyScopes()` to cover the whole tenant. Start dates are inclusive; end dates are exclusive. Expiry needs no scheduler.

```php
$assignment = Access::grant('reviewer')->to($user)->in($team)
    ->onlyScopes([$site, $otherSite])->titled('Review cover')
    ->validFrom(now())->validUntil(now()->addMonths(6))
    ->by($admin)->because('Cover leave')->save();
```

Guards reject self-assignment and escalation by default. Scopes must belong to the tenant. `guards.protected_profiles` lists profiles whose last active holder must stay. Host `AssignmentRule` implementations add checks. Rejections throw `Seisigma\AccessProfiles\Exceptions\GrantRejected` with a `reason` code.

Trusted seeders and backfills can use a system actor. This skips escalation and self-assignment checks. Tenant consistency, protected profiles, catalogue checks, duplicate checks and host rules still apply. This example assumes no live reviewer assignment yet.

```php
$assignment = Access::grant('reviewer')->to($user)->in($team)
    ->bySystem('seed: initial reviewer')->save();
```

Change only the fields provided. The change reason is in the event; it does not overwrite the original grant reason.

```php
$assignment = Access::change($assignment)->onlyScopes([$site])
    ->titled('Extended review cover')->validUntil(now()->addYear())
    ->by($admin)->because('Extend the cover')->save();
```

Revoke without deleting the history row.

```php
$assignment = Access::revoke($assignment)
    ->by($admin)->because('Cover ended')->save();
```

## Checks and views

Add the trait to your holder model. It adds `canAccess()` and the `accessAssignments()` morph relation. Laravel's `$user->can()` and Gate keep their normal behaviour.

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Seisigma\AccessProfiles\Concerns\HasAccessProfiles;

class User extends Authenticatable
{
    use HasAccessProfiles;
}
```

These checks assume an active reviewer assignment limited to `$site`. Approval remains denied by the profile.

```php
$allowed = Access::for($user)->can('billing.invoice.view', $site);
$sameAnswer = $user->canAccess('billing.invoice.view', $site);
$decision = Access::for($user)->explain('billing.invoice.approve', $site);
$keys = Access::for($user)->permissionsIn($site);
Access::for($user)->authorize('billing.invoice.view', $site);
```

`authorize()` throws Laravel's `AuthorizationException` when denied. For real API tokens, `token_intersection` also requires `tokenCan($key)`. Tokens can only narrow access. Optional profile conditions are evaluated at check time. An off condition ignores both allow and deny rules in that profile.

Blade uses the authenticated user. Guests cannot see the block.

```blade
@canAccess('billing.invoice.view', $site)
View invoices
@endcanAccess
```

## Query access and history

`holders()` and `holdersQuery()` apply deny, scope, validity and conditions. The holder class defaults to `auth.providers.users.model`; pass a third argument for another holder type. `history()` includes revoked and expired assignments.

```php
$holders = Access::holders('billing.invoice.view', $site);
$count = Access::holdersQuery('billing.invoice.view', $site)->count();
$scopes = Access::for($user)->scopesWhere('billing.invoice.view', $team);
$allSites = $scopes->coversTenant();
$siteIds = $scopes->ids(Site::class);
$sites = $scopes->constrain(
    Site::query()->where('team_id', $team->getKey()), 'id', Site::class,
)->get();
$history = Access::history($user, $team);
```

Always limit the base query to the tenant yourself. `constrain()` adds no restriction when access covers every scope. It returns no rows for an empty set. A tenant-wide allow with scope denies uses exclusions; `exceptIds(Site::class)` lists them. Then `coversTenant()` is false and `ids()` is empty; use `constrain()` to apply the exclusions.

## Events

Writes dispatch after commit: `AssignmentGranted`, `AssignmentRevoked`, `AssignmentUpdated`, `ProfileCreated`, `ProfileUpdated`, `ProfileDeleted` and `TemplatesSynced`. They carry readonly `actor`, `system`, `reason`, `before` and `after` fields. A system actor is null. Passive expiry fires no event.

Register listeners in your host to send snapshots to its audit log.

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Seisigma\AccessProfiles\Events\AssignmentGranted;

Event::listen(AssignmentGranted::class, function (AssignmentGranted $event): void {
    Log::info('Access granted', [
        'actor' => $event->actor?->getKey(),
        'system' => $event->system,
        'reason' => $event->reason,
        'before' => $event->before,
        'after' => $event->after,
    ]);
});
```

`events.access_checked` is **false by default**. Enable it for shadow checks or debugging. `AccessChecked` carries `actor`, `target` and `decision` plus `system` and `reason`; it has no before/after snapshots. It fires for each decision, including memo hits.

## Inertia, policies and queues

In your host's `HandleInertiaRequests::share()`, share keys for the current scope. Earlier middleware must resolve a `Site` into the request attribute `currentScope`. Import `Illuminate\Http\Request` and the `Access` facade. The package has no Inertia dependency.

```php
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
```

Keep workflow rules in a policy. Here `Invoice` is a host model with a `site` relation and an `isPending()` method. Its approval profile must allow the key without a covering deny.

```php
public function approve(User $user, Invoice $invoice): bool
{
    return $user->canAccess('billing.invoice.approve', $invoice->site)
        && $invoice->isPending()
        && $invoice->created_by !== $user->id;
}
```

Jobs carry their actor and scope. Recheck when executing so a grant revoked after dispatch is honoured. Import the host `User` and `Site` models and the `Access` facade. Put export work after `authorize()`.

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

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
```

## Commands

Run with `php artisan`. References use `morph-type:primary-key`, such as `team:1` when your morph map registers `team`. Without a morph map, use the fully qualified model class as the type and quote the argument in your shell.

| Command | Purpose |
| --- | --- |
| `access-profiles:census` | Exit 1 for unknown stored keys. Unused catalogue keys are warnings. |
| `access-profiles:sync-templates --dry-run` | Preview changes without writes. Omit `--dry-run` to sync. |
| `access-profiles:cache-clear --tenant=team:1` | Bump one tenant's version. Omit `--tenant` for all stored tenants. |
| `access-profiles:backfill legacy --dry-run --tenant=team:1` | Preview drafts from the registered source named `legacy`. Omit `--tenant` for all source tenants. |
| `access-profiles:show user:1 team:1` | Show active assignments, scopes, validity and effective keys. |

Implement `Seisigma\AccessProfiles\Contracts\BackfillSource` with `name()`, `tenants()` and `drafts($tenant)`. Return `Seisigma\AccessProfiles\Backfill\AssignmentDraft` objects. Register source classes in `backfill.sources`. Read the dry-run report before running without `--dry-run`. Real runs use system grants, skip existing live assignments and roll back only the rejected tenant. Other tenants can commit. Any rejection gives exit 1.

## When NOT to use this package

- **One tenant or one flat team level.** Consider [spatie/laravel-permission](https://github.com/spatie/laravel-permission).
- **A few fixed roles.** Laravel's Gates and policies may be enough.
- **Record-level rules.** Keep rules such as “only the author edits this post” in a policy.
- **An admin UI out of the box.** Build your own screens.
- **A mature, proven package today.** This package is new. Review it before relying on it for access control.

## Testing

Run `composer test`, `composer analyse` and `composer format`. Each fenced example above has a behaviour test in [ReadmeExamplesTest.php](tests/Docs/ReadmeExamplesTest.php). Tests keep their own copies of the examples.

## Credits and license

Ricardo A. Vargas R. (Seisigma SRL). Package layout follows [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel).

MIT. See [LICENSE.md](LICENSE.md).
