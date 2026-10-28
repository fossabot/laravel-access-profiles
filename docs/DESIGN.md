# laravel-access-profiles — Design

Status: **draft for approval** (2026-10-28). No resolver, models or business code exist yet.
The skeleton contains only the service provider, the config file and the migration stub for the four tables below.

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

## 3. Public API sketch

Names are proposals. Everything below is a sketch, not code that exists.

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
//   ScopeSet->ids(Site::class): list<int>
//   ScopeSet->constrain($query, 'site_id'): Builder   (adds whereIn, or nothing when coversTenant)

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

Access::profile('auditor')->in($organisation)->copyFromTemplate();       // tenant copy
Access::profile('auditor')->in($organisation)->allow('billing.*')->deny('billing.bank.reveal')->save();
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

Optional: `'gate' => ['register' => true]` defines one Gate ability per catalogue key,
so `$user->can('billing.invoice.approve', $site)` and the `can:` middleware also work. Off by default (open question Q3).

### 3.4 Blade and Inertia

- Blade: `@canAccess('billing.invoice.approve', $site) … @endcanAccess`.
- Inertia: no dependency. The README shows a `HandleInertiaRequests::share()` example that shares
  `Access::for($user)->permissionsIn($currentScope)` — the keys for the **current scope only**, never the whole tenant.

### 3.5 API tokens (optional)

If `token_intersection` is on and the holder has `currentAccessToken()` returning a real token (not a transient cookie token),
the resolver also requires `$holder->tokenCan($permission)`. This is detected by duck typing (`method_exists`),
so it works with Sanctum and needs no `laravel/sanctum` dependency. Profile grants can only **narrow** with a token, never widen.

### 3.6 Events (audit, no activity-log dependency)

All events carry the actor (`by`), the reason and the before/after state.

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
| `access-profiles:backfill {source} {--dry-run}` | Run a host `BackfillSource` (contract) that yields assignment drafts from the old system; report per tenant; write only without `--dry-run`. |
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
| `catalogues` | `[]` | Classes implementing `PermissionCatalogue`. |
| `tables.*` | `access_profiles`, `access_profile_permissions`, `access_assignments`, `access_assignment_scopes` | Table names. |
| `cache.enabled` / `store` / `ttl` / `prefix` | `true` / default store / 24 h / `access-profiles` | §2.2. |
| `token_intersection` | `true` | §3.5. |
| `guards.no_escalation` / `no_self_assignment` / `protected_profiles` | `true` / `true` / `[]` | §4. |
| `events.access_checked` | `false` | §3.6. |
| `bindings` | `[]` | Swap contract implementations. |
| `gate.register` (planned) | `false` | §3.3. |

### 5.2 Tables

| Table | Key columns |
|---|---|
| `access_profiles` | id, `tenant` morph (nullable = template), key, name, description, based_on_id, is_system, requires_condition, version, timestamps, soft deletes. Unique (tenant, key). |
| `access_profile_permissions` | profile_id, permission, effect (`allow`/`deny`). Unique (profile_id, permission). |
| `access_assignments` | id, `tenant` morph, `holder` morph, profile_id, profile_version, covers_tenant, title, valid_from, valid_until, `granted_by` morph, reason, revoked_at, `revoked_by` morph, revoke_reason, timestamps. |
| `access_assignment_scopes` | assignment_id, `scope` morph. Unique (assignment, scope). |

Changes from the source design (Condomera PRJ-030 §4.2): `company_id` → `tenant` morph; `user_id` → `holder` morph;
`scope enum(company, condominiums)` → `covers_tenant` boolean; `condominium_id` → `scope` morph;
`position_key` dropped (domain field — hosts can keep their own mapping); `revoke_reason` added;
`companies.access_version` replaced by the cache counter (§2.2). The "one row per profile per user" rule is enforced in the grant builder,
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

### 5.4 Out of scope

- **No UI.** No admin panel, no Livewire/Inertia screens.
- **No record-level rules.** "Only the author may edit this post" stays in your policy.
- **No workflow rules.** Maker-checker, approval counts, separation of duties live in your actions; the package gives you
  `holders()` to count eligible people.
- **No role hierarchy** (profile A includes profile B). Copy a template instead.
- **No read-time wildcards.** Wildcards are expanded when a profile is saved.
- **No resident/customer portal roles.** The package is for staff-style access inside a tenant.
- **No multiple auth guards logic.** A holder is a model; use one holder type per guard if you need that.

## 6. How Condomera would use it (example host)

Condomera is the first host. The mapping, from PRJ-030 §4.5–§4.6:

| Package concept | Condomera |
|---|---|
| Tenant | `Company` (the administration company) |
| Scope | `Condominium` (implements `HasAccessTenant`, returns its company) |
| Holder | `User` (company members only — an `AssignmentRule` checks Jetstream membership) |
| Catalogue | `App\Access\Permission` enum: `finance.expense.approve`, `finance.payable.pay`, `finance.bank.reveal`, `hr.salary.view`, `porteria.package.register`, `access.members.manage`, … |
| Templates | `admin_general`, `admin_condominio`, `contador`, `auxiliar`, `cobros`, `tesoreria`, `rrhh`, `operaciones`, `porteria`, `junta_lectura`, `junta_aprobadora`, `revision`, `tecnico_legado` |
| `covers_tenant = true` | `admin_general`, `rrhh` (company level) |
| `covers_tenant = false` + scopes | `admin_condominio` of condos 3 and 7; `operaciones`; `porteria` |
| Validity window | `contador` (external, expiry suggested), `revision` (expiry required) |
| Title | Junta appointments: "Presidente de junta", "Tesorero de junta" |
| Condition | `junta_aprobadora.requires_condition = board_approvals` → `ProfileConditions` reads the System-configuration flag (Q2, OFF by default) |
| `AssignmentRule` | `junta_aprobadora` only for a user with `board_member` in that condominium |
| `protected_profiles` | `['admin_general']` — last one cannot be removed (G1) |
| Guards | `no_escalation` + `no_self_assignment` = PRJ-030 "Grant safety (G1)" |
| Owner | No wildcard. Backfill gives the owner `admin_general`. |
| Events → audit | One listener writes every event to `spatie/laravel-activitylog` (already in Condomera). |
| Backfill | `BackfillSource` maps Jetstream `company_user.role`: owner/admin → `admin_general`; manager → `admin_condominio` with today's reach; technician → `tecnico_legado`; security → `porteria`. Ricardo runs it in production with `--dry-run` first. |
| Shadow mode (S3) | `events.access_checked = true`; a listener compares with the old check and logs mismatches. |

**DEC-065/067 eligible approvers** (PRJ-030 §4.6), with no package change:

```php
$eligible = Access::holders('finance.expense.approve', $expense->condominium)
    ->reject(fn (User $u) => $u->is($expense->creator));   // maker never approves own item

match (true) {
    $eligible->isEmpty() => /* switch disabled; sole operator path (Q4): log record/approve/pay */,
    $eligible->count() === 1 => /* the other one approves */,
    default => /* company default approver, or any other when the default is the maker */,
};
```

Because `holders()` applies deny, validity, revocation and the `board_approvals` condition, a board approver counts only while the flag is ON
and the appointment is valid — exactly the PRJ-030 rule.

## 7. Delivery plan (after approval)

| Block | Content |
|---|---|
| D2 | Models, catalogue contract, template sync, resolver + cache + scoped memo; tests for every rule in §2.1. |
| D3 | Grant/revoke builders, guards, events. |
| D4 | `holders()` / `scopesWhere()` queries (SQL, deny applied); Blade directive; token intersection. |
| D5 | Commands; README examples run as tests; 0.1.0 tag (local). |

## 8. Package name

Checked on 2026-10-28: Packagist `https://repo.packagist.org/p2/seisigma/<name>.json` (404 = free),
Packagist search `https://packagist.org/search.json?q=<name>` (any vendor), and GitHub repository search by name (`gh api search/repositories`).

| Name | Packagist `seisigma/` | Packagist any vendor | GitHub repos with that name | Note |
|---|---|---|---|---|
| `laravel-access-profiles` | 404 (free) | 0 results | 0 | Says what it is: profiles of access. Matches the domain word "profile" used in the docs. |
| `laravel-scoped-access` | 404 (free) | 0 results | 0 | Stresses the scope feature; "access" alone is vague. |
| `laravel-grantbook` | 404 (free) | 0 results | 0 | Memorable, stresses the audit trail; does not say what it does. |

**Pick: `laravel-access-profiles`** (namespace `Seisigma\AccessProfiles`). `seisigmasrl/laravel-access-profiles` is also free (see Q1).

## 9. Open questions for Ricardo

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
