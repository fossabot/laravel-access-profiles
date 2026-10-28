# laravel-access-profiles — Design

Status: **released 0.1.0** (2026-10-28; decisions in §9).
Package: `seisigmasrl/laravel-access-profiles`. Requires PHP 8.3+ and Laravel 12.69+ or 13.30+.
The package includes the resolver, models, builders, queries, events and commands described below.

---

## 1. The problem

Many multi-tenant apps have the same shape:

- A **tenant** (an organisation, account, agency) has many **scopes** inside it (sites, buildings, stores, projects).
- One person needs **different powers in different scopes** of the same tenant: manager in two sites, read-only in a third.
- Some access is **temporary** (an external auditor until 31 March) or **appointed** ("Board treasurer").
- A broad grant sometimes needs **one hole** in it (everything in billing, except approving payments).
- Someone will ask **"who can approve payments in site 4?"** — and the answer must be a query, not a loop over every user.
- Somebody will ask **"who gave Ana this access, and why?"**

Existing packages cover one tenant dimension, or deny, or a role list — not all of these together. See [COMPARISON.md](COMPARISON.md).

## 2. Concepts

| Concept | What it is | Stored where |
|---|---|---|
| **Holder** | Who receives access. Any Eloquent model that is `Authorizable` (normally `User`). Morph. | `access_assignments.holder_*` |
| **Tenant** | The boundary that owns profiles and assignments. Any Eloquent model. Morph. | `*.tenant_*` |
| **Scope** | A model inside a tenant that an assignment can be limited to. Any Eloquent model. Morph. Optional. | `access_assignment_scopes.scope_*` |
| **Catalogue** | Every permission key the app knows, **in code**: a backed enum implementing `PermissionCatalogue`. Keys look like `module.resource.action` (`billing.invoice.approve`). | PHP class, listed in `config('access-profiles.catalogues')` |
| **Profile** | A named set of rules. Each rule = permission key + effect (`allow` or `deny`). | `access_profiles`, `access_profile_permissions` |
| **System template** | A profile with `tenant = null` and `is_system = true`. Defined in code, synced to the database by a command. Read-only at runtime. | `access_profiles` |
| **Tenant copy** | A profile a tenant owns. Usually copied from a template (`based_on_id`) and then edited. | `access_profiles` |
| **Assignment** | Holder × profile × tenant × (whole tenant **or** a set of scopes) × validity window × grantor × reason × title. Revoked rows stay (history). | `access_assignments`, `access_assignment_scopes` |
| **Condition** | An optional switch on a profile (`requires_condition`, e.g. `board_approvals`). The host decides per tenant whether it is on. Off → the profile grants nothing. | `access_profiles.requires_condition` + `ProfileConditions` contract |

### 2.1 Decision rules (the resolver)

For `can(holder, permission, target)` where `target` is a tenant or a scope:

1. Resolve the **tenant** of the target (`ScopeOwnership` contract; a tenant is its own tenant).
2. Load the holder's **active** assignments in that tenant:
   `revoked_at is null` and `valid_from <= now` (or null) and `valid_until > now` (or null) and the profile's condition is on.
3. Keep the assignments that **cover** the target:
   `covers_tenant = true` covers the tenant and every scope in it; `covers_tenant = false` covers only its listed scopes.
   A tenant-level check (target = tenant) is covered only by `covers_tenant = true`.
4. Collect the rules for `permission` from those assignments' profiles.
5. **Any deny wins.** If one covering rule says `deny`, the answer is `false`, even if another profile allows.
6. Otherwise `true` if at least one rule says `allow`. Default is `false`.
7. If the holder authenticated with an API token, also require `tokenCan(permission)` (§4.4).

There is **no owner wildcard and no `Gate::before`**. A tenant owner gets access the same way as everyone else:
an assignment of a full profile. The package never answers `true` because of who the holder *is*.

### 2.2 Versioned cache

- Every write (grant, revoke, profile rule change, template sync) bumps a **per-tenant version** stored in the cache store
  (`{prefix}:version:{tenantType}:{tenantId}`, incremented atomically).
- Resolved grants are cached under `{prefix}:{tenantType}:{tenantId}:{holderType}:{holderId}:v{version}`.
  Old versions are never read again and expire by TTL. No tags needed, so every cache driver works.
- The TTL is `min(config ttl, seconds until the next valid_from / valid_until boundary)` for that holder,
  so an expiring assignment stops working on time without a scheduler.
- If the cache store is flushed, the version and the entries go together. The next read rebuilds from the database. Safe.
- No extra column on the host's tenant table (the source design had `companies.access_version`; the cache counter replaces it).

### 2.3 Octane and queue safety

- **No global tenant state.** There is no `setTeamId()`. Every call takes the tenant or scope as an argument.
- The per-request memo is a Laravel **scoped** binding, which Laravel flushes at the start of every Octane request and every queued job
  ([Laravel 13 container docs — "Binding Scoped Singletons"](https://laravel.com/docs/13.x/container#binding-scoped)).
- Jobs carry the **actor** (holder) explicitly and re-check at execution time:
  `Access::for($this->actor)->authorize('billing.report.export', $this->site);`
  A grant revoked between dispatch and execution is therefore honoured.

## 3. Public API

The following names are implemented in 0.1.0.

### 3.1 Checks

```php
use Seisigma\AccessProfiles\Facades\Access;

// On the holder model (trait HasAccessProfiles)
$user->canAccess('billing.invoice.approve', $site);          // bool
$user->canAccess('team.members.manage', $organisation);      // tenant-level check

// Through the facade
Access::for($user)->can('billing.invoice.approve', $site);   // bool
Access::for($user)->authorize('billing.invoice.approve', $site); // throws AuthorizationException
Access::for($user)->explain('billing.invoice.approve', $site);   // Decision: allowed?, matched rules, assignments, reason

// Lists
Access::for($user)->permissionsIn($site);                    // list<string> granted keys (for UI)
Access::for($user)->scopesWhere('billing.invoice.view', $organisation); // ScopeSet
//   ScopeSet->coversTenant(): bool   (true = every scope)
//   ScopeSet->ids(Site::class): list<int|string>
//   ScopeSet->exceptIds(Site::class): list<int|string> (scope denies inside tenant-wide access)
//   ScopeSet->constrain($query, 'site_id', Site::class): Builder
//     (tenant-filtered query; adds whereIn / whereNotIn, or nothing when coversTenant)

// "Who holds permission P in scope S" — one query, deny applied
Access::holders('billing.invoice.approve', $site);           // Eloquent\Collection of holders
Access::holdersQuery('billing.invoice.approve', $site);      // Builder (paginate, count, filter)
```

### 3.2 Grant and revoke

```php
$assignment = Access::grant('board_reviewer')                // profile key (tenant copy first, then template) or Profile model
    ->to($user)
    ->in($organisation)                                      // tenant
    ->onlyScopes([$site3, $site4])                           // omit = whole tenant
    ->titled('Board treasurer')
    ->validFrom(now())->validUntil(now()->addMonths(6))
    ->by($admin)                                             // grantor; required when guards are on
    ->because('Elected at the 2026 assembly')
    ->save();                                                // runs guards, writes, bumps version, fires event

Access::revoke($assignment)->by($admin)->because('Term ended')->save();

Access::profile('auditor')->in($organisation)->by($admin)->because('Local copy')->copyFromTemplate(); // saves the tenant copy
Access::profile('auditor')->in($organisation)->allow('billing.*')->deny('billing.bank.reveal')
    ->by($admin)->because('Local rules')->save();

Access::change($assignment)->validUntil(now()->addYear())->onlyScopes([$site3])
    ->titled('Review cover')->by($admin)->because('Extended term')->save();
Access::grant('auditor')->to($user)->in($organisation)->bySystem('seed: initial audit access')->save();
```

`billing.*` is **expanded at write time** against the catalogue, so the stored rules are always exact keys.
Read-time wildcard matching does not exist. The census command can then prove every stored key is real.

### 3.3 Policies

```php
public function approve(User $user, Invoice $invoice): bool
{
    return $user->canAccess('billing.invoice.approve', $invoice->site)
        && $invoice->isPending()                 // state check stays in the policy
        && $invoice->created_by !== $user->id;   // workflow rule stays in the policy
}
```

Version 0.1 has **explicit checks only** (`canAccess()` / `Access::for()`); `$user->can()` stays plain Laravel (Q3 A).
A later version may add an opt-in `'gate' => ['register' => true]` that defines one Gate ability per catalogue key,
so `$user->can('billing.invoice.approve', $site)` and the `can:` middleware also work. Not in 0.1.

### 3.4 Blade and Inertia

- Blade: `@canAccess('billing.invoice.approve', $site) … @endcanAccess`.
- Inertia: no dependency. The README shows a `HandleInertiaRequests::share()` example that shares
  `Access::for($user)->permissionsIn($currentScope)` — the keys for the **current scope only**, never the whole tenant.

### 3.5 API tokens (optional)

If `token_intersection` is on and the holder has `currentAccessToken()` returning a real token (not a transient cookie token),
the resolver also requires `$holder->tokenCan($permission)`. This is detected by duck typing (`method_exists`),
so it works with Sanctum and needs no `laravel/sanctum` dependency. Profile grants can only **narrow** with a token, never widen.

### 3.6 Events (audit, no activity-log dependency)

Write events carry the actor (`by`, nullable for system writes), `system`, the reason and the before/after state.
`AccessChecked` instead carries `actor`, `target`, `decision`, `system` and `reason`, without snapshots.

| Event | When |
|---|---|
| `AssignmentGranted` | `grant()->save()` |
| `AssignmentRevoked` | `revoke()->save()` |
| `AssignmentUpdated` | validity, title or scopes changed |
| `ProfileCreated` / `ProfileUpdated` / `ProfileDeleted` | tenant copy changes |
| `TemplatesSynced` | the sync command changed a template |
| `AccessChecked` | every decision — **off by default**; for shadow mode while migrating from another system |

The host writes them to its own audit log (e.g. `spatie/laravel-activitylog`) with one listener.
Passive expiry fires nothing (nothing happens at that moment); `Access::history($user, $tenant)` reads it from the rows.

### 3.7 Artisan commands

| Command | Does |
|---|---|
| `access-profiles:sync-templates` | Upsert system templates from code definitions; bump their `version`; report changes. `--dry-run`. |
| `access-profiles:census` | Fail (exit 1) if a stored rule key is not in a catalogue; list catalogue keys no profile uses. For CI. |
| `access-profiles:cache-clear {--tenant=}` | Bump the version of one tenant or all tenants. |
| `access-profiles:backfill {source} {--dry-run} {--tenant=}` | Run a host `BackfillSource` (contract) that yields assignment drafts from the old system; report per tenant; write only without `--dry-run`. |
| `access-profiles:show {holder} {tenant}` | Print active assignments, scopes, validity and effective keys. |

## 4. Write-time guards

On by default; each is a config switch. They run in `save()` and throw `GrantRejected` with a reason code.

| Guard | Rule |
|---|---|
| `no_escalation` | The grantor (`by`) must hold every **allow** key of the profile in every scope of the new assignment. |
| `no_self_assignment` | The grantor cannot grant, change or revoke their own assignments. |
| `protected_profiles` | The last active holder of a listed profile in a tenant cannot be revoked (no lock-out). |
| Tenant consistency | Every scope must belong to the tenant (`ScopeOwnership`). Always on. |
| Catalogue | Every rule key must exist in a catalogue. Always on. |
| `AssignmentRule` (contract) | Host rules, e.g. "this profile only for holders who sit on the board of that scope". Zero or more. |

## 5. Config, tables and extension points

### 5.1 Config keys (`config/access-profiles.php`)

| Key | Default | Purpose |
|---|---|---|
| `catalogues` | `[]` | Classes implementing `PermissionCatalogue::keys()` (static). |
| `templates` | `null` | Class implementing static `TemplateDefinitions::templates()`. |
| `backfill.sources` | `[]` | Classes implementing `BackfillSource`. |
| `tables.*` | `access_profiles`, `access_profile_permissions`, `access_assignments`, `access_assignment_scopes` | Table names. |
| `cache.enabled` / `store` / `ttl` / `prefix` | `true` / default store / 24 h / `access-profiles` | §2.2. |
| `token_intersection` | `true` | §3.5. |
| `guards.no_escalation` / `no_self_assignment` / `protected_profiles` | `true` / `true` / `[]` | §4. |
| `events.access_checked` | `false` | §3.6. |
| `bindings` | `[]` | Swap contract implementations. |
| `gate.register` (later, not in 0.1) | `false` | §3.3. |

### 5.2 Tables

| Table | Key columns |
|---|---|
| `access_profiles` | id, `tenant` morph (nullable = template), key, name, description, based_on_id, is_system, requires_condition, version, timestamps, soft deletes. Unique (tenant, key). |
| `access_profile_permissions` | profile_id, permission, effect (`allow`/`deny`). Unique (profile_id, permission). |
| `access_assignments` | id, `tenant` morph, `holder` morph, profile_id, profile_version, covers_tenant, title, valid_from, valid_until, `granted_by` morph, reason, revoked_at, `revoked_by` morph, revoke_reason, timestamps. |
| `access_assignment_scopes` | assignment_id, `scope` morph. Unique (assignment, scope). |

Changes from the source design: a fixed team ID → `tenant` morph; a fixed user ID → `holder` morph;
a team-or-sites scope enum → `covers_tenant` boolean; a fixed site ID → `scope` morph;
`position_key` dropped (domain field — hosts can keep their own mapping); `revoke_reason` added;
the team's access-version column replaced by the cache counter (§2.2). The "one row per profile per user" rule is enforced in the grant builder,
not by a unique index, so revoked history rows can stay.

### 5.3 Contracts (extension points)

| Contract | Default | Host implements when |
|---|---|---|
| `PermissionCatalogue` | — (required) | Always: list your keys. |
| `TemplateDefinitions` | none | You ship system templates in code. |
| `ScopeOwnership` | reads `$scope->accessTenant()` from an interface on the scope model | Your scope → tenant link is not a simple relation. |
| `ProfileConditions` | every condition is off | You use `requires_condition`. |
| `AssignmentRule` | none | Extra grant rules (§4). |
| `BackfillSource` | none | You migrate from another permission system. |
| `Resolver` | package resolver | You need a different decision algorithm (rare). |

Tenant models also implement `HasAccessTenant` and return `$this`; scope models return their tenant, other targets throw an exception naming their class, and `$target->is($target->accessTenant())` identifies a tenant-level target.

### 5.4 Out of scope

- **No UI.** No admin panel, no Livewire/Inertia screens.
- **No record-level rules.** "Only the author may edit this post" stays in your policy.
- **No workflow rules.** Maker-checker, approval counts, separation of duties live in your actions; the package gives you
  `holders()` to count eligible people.
- **No role hierarchy** (profile A includes profile B). Copy a template instead.
- **No read-time wildcards.** Wildcards are expanded when a profile is saved.
- **No resident/customer portal roles.** The package is for staff-style access inside a tenant.
- **No multiple auth guards logic.** A holder is a model; use one holder type per guard if you need that.

## 6. How a team app uses it (example host)

A host maps its team and site models to the package concepts:

| Package concept | Example host |
|---|---|
| Tenant | `Team` (the organisation) |
| Scope | `Site` (implements `HasAccessTenant`, returns its team) |
| Holder | `User` (team members only — an `AssignmentRule` checks membership) |
| Catalogue | `App\Access\Permission` enum: `billing.invoice.approve`, `billing.payment.pay`, `billing.bank.reveal`, `people.salary.view`, `site.delivery.register`, `access.members.manage`, … |
| Templates | `team_admin`, `site_admin`, `accountant`, `assistant`, `collections`, `treasury`, `people_admin`, `operations`, `reception`, `board_reader`, `board_approver`, `reviewer`, `legacy_technician` |
| `covers_tenant = true` | `team_admin`, `people_admin` (team level) |
| `covers_tenant = false` + scopes | `site_admin` of sites 3 and 7; `operations`; `reception` |
| Validity window | `accountant` (external, expiry suggested), `reviewer` (expiry required) |
| Title | Board appointments: "Board chair", "Board treasurer" |
| Condition | `board_approver.requires_condition = board_approvals` → `ProfileConditions` reads the host configuration flag (OFF by default) |
| `AssignmentRule` | `board_approver` only for a user with `board_member` in that site |
| `protected_profiles` | `['team_admin']` — last one cannot be removed |
| Guards | `no_escalation` + `no_self_assignment` = PRJ-030 "Grant safety (G1)" |
| Owner | No wildcard. Backfill gives the owner `team_admin`. |
| Events → audit | One listener writes every event to the host audit log. |
| Backfill | `BackfillSource` maps the team membership role: owner/admin → `team_admin`; manager → `site_admin` with today's reach; technician → `legacy_technician`; reception → `reception`. The host operator runs it with `--dry-run` first. |
| Shadow mode (S3) | `events.access_checked = true`; a listener compares with the old check and logs mismatches. |

**Eligible approvers**, with no package change:

```php
$eligible = Access::holders('billing.invoice.approve', $invoice->site)
    ->reject(fn (User $u) => $u->is($invoice->creator));   // maker never approves own item

match (true) {
    $eligible->isEmpty() => /* switch disabled; sole operator path (Q4): log record/approve/pay */,
    $eligible->count() === 1 => /* the other one approves */,
    default => /* team default approver, or any other when the default is the maker */,
};
```

Because `holders()` applies deny, validity, revocation and the `board_approvals` condition, a board approver counts only while the flag is ON
and the appointment is valid. The host keeps its workflow rules.

## 7. Delivery blocks (completed in 0.1.0)

| Block | Content |
|---|---|
| D2 | Models, catalogue contract, template sync, resolver + cache + scoped memo; tests for every rule in §2.1. |
| D3 | Grant/revoke builders, guards, events. |
| D4 | `holders()` / `scopesWhere()` queries (SQL, deny applied); Blade directive; token intersection. |
| D5 | Commands; README examples run as tests; 0.1.0 tagged and published. |

## 8. Package name (pre-release research)

Checked on 2026-10-28: Packagist `https://repo.packagist.org/p2/seisigma/<name>.json` (404 = free),
Packagist search `https://packagist.org/search.json?q=<name>` (any vendor), and GitHub repository search by name (`gh api search/repositories`).

| Name | Packagist `seisigma/` | Packagist any vendor | GitHub repos with that name | Note |
|---|---|---|---|---|
| `laravel-access-profiles` | 404 (free) | 0 results | 0 | Says what it is: profiles of access. Matches the domain word "profile" used in the docs. |
| `laravel-scoped-access` | 404 (free) | 0 results | 0 | Stresses the scope feature; "access" alone is vague. |
| `laravel-grantbook` | 404 (free) | 0 results | 0 | Memorable, stresses the audit trail; does not say what it does. |

**Pick: `laravel-access-profiles`** (namespace `Seisigma\AccessProfiles`). `seisigmasrl/laravel-access-profiles` was also free when checked before release (see Q1).
**Decided:** `seisigmasrl/laravel-access-profiles` (Q1 B). The PHP namespace stays `Seisigma\AccessProfiles`, the same as `seisigmasrl/dgii-rnc-validator`.

## 9. Historical Q&A for Ricardo

Historical pre-release discussion; availability and skeleton statements below describe that point in time.
Decided 2026-10-28 (Ricardo): Q1 B, Q2 B, Q3 A, Q4 A

**Q1 — Packagist vendor.**
A) `seisigma/` as the brief says — new vendor, nothing published there yet.
B) `seisigmasrl/` — the vendor that already has `dgii-rnc-validator`, `lemonsqueezy-php`, `momentum-lock`, and matches the GitHub org.
**Pick: B.** One vendor is easier to trust and to find. Both names are free today (Packagist 404 on 2026-10-28).

**Q2 — Laravel 11 support.**
A) `^11 || ^12 || ^13` as the brief says.
B) `^12 || ^13` only. Laravel 11 security support ended 2026-03-12 ([support policy](https://laravel.com/docs/13.x/releases#support-policy));
`spatie/laravel-permission` 8.x also dropped it.
**Pick: B.** Smaller test matrix, no users lost on a supported Laravel. The skeleton keeps 11 until you decide.

**Q3 — Gate integration.**
A) Explicit only: `$user->canAccess(...)` / `Access::for()`. Policies call it. `$user->can()` stays plain Laravel.
B) Also register one Gate ability per catalogue key (opt-in config) so `can:` middleware and `@can` work.
**Pick: A for 0.1**, B as an opt-in later. Explicit calls make the scope argument impossible to forget.

**Q4 — Where system templates live.**
A) In code (`TemplateDefinitions`), synced to the database by `access-profiles:sync-templates` (deploy step), versioned.
B) Only in the database, created by a seeder/migration in the host.
**Pick: A.** Templates are reviewed in PRs, the census can check them, and a deploy can never drift from the code.
