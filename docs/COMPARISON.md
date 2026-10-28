# Comparison

Checked on **2026-10-28** against these exact versions. Every cell links to the doc or source line it is based on.
"—" means the feature does not exist in that version (searched in docs and `src/`).

| | Version checked | Released |
|---|---|---|
| **laravel-access-profiles** | design only (this repo) | — |
| [`spatie/laravel-permission`](https://github.com/spatie/laravel-permission/tree/8.3.2) | 8.3.2 (current major 8) | 2026-10-19 ([Packagist](https://repo.packagist.org/p2/spatie/laravel-permission.json)) |
| [`silber/bouncer`](https://github.com/JosephSilber/bouncer/tree/v1.0.4) | v1.0.4 | 2026-03-18 ([Packagist](https://repo.packagist.org/p2/silber/bouncer.json)) |
| [`santigarcor/laratrust`](https://github.com/santigarcor/laratrust/tree/8.5.6) | 8.5.6 | 2026-10-22 ([Packagist](https://repo.packagist.org/p2/santigarcor/laratrust.json)) |
| Laravel Gate + policies | Laravel 13.x | built in ([docs](https://laravel.com/docs/13.x/authorization)) |

Short links used below: **SP** = `spatie/laravel-permission@8.3.2`, **BO** = `silber/bouncer@v1.0.4`, **LT** = `santigarcor/laratrust@8.5.6`.

## Feature table

| Row | access-profiles (design) | spatie/laravel-permission 8.3.2 | silber/bouncer 1.0.4 | laratrust 8.5.6 | Plain Gate / policies |
|---|---|---|---|---|---|
| **Runtime deps** | `php`, `illuminate/{contracts,database,support}` | `illuminate/*` + `spatie/laravel-package-tools` ([composer.json](https://github.com/spatie/laravel-permission/blob/8.3.2/composer.json)) | `illuminate/{auth,cache,container,contracts,database}` only ([composer.json](https://github.com/JosephSilber/bouncer/blob/v1.0.4/composer.json)) | the whole `laravel/framework` + `kkszymanowski/traitor` ([composer.json](https://github.com/santigarcor/laratrust/blob/8.5.6/composer.json)) | none |
| **Multi-tenant scope** | Two levels: tenant + scopes inside it, any models (morph) | One `team_id` dimension ([teams](https://github.com/spatie/laravel-permission/blob/8.3.2/docs/basic-usage/teams-permissions.md)) | One tenant id per request via `Bouncer::scope()->to()` ([README "Multi-tenancy"](https://github.com/JosephSilber/bouncer/blob/v1.0.4/README.md#multi-tenancy)) | One team dimension, passed per call ([teams](https://github.com/santigarcor/laratrust/blob/8.5.6/docs/docs/8.x/usage/teams.md)) | You write it |
| **One assignment → a set of scopes** | Yes: one row + N scope rows, or whole tenant | — (one role row per team) | — (abilities can target one model instance, `to('edit', $post)`, not a scope set) | — (one role row per team) | You write it |
| **Deny** | Yes, deny wins | — | Yes, `forbid()` ([README](https://github.com/JosephSilber/bouncer/blob/v1.0.4/README.md#forbidding-an-ability)) | — | You write it |
| **Expiry / validity window** | `valid_from` / `valid_until`; cache TTL capped at the next boundary | — | — | — | You write it |
| **Grantor + reason audit** | On every assignment + events | — (role events exist, opt-in, no grantor/reason: [events](https://github.com/spatie/laravel-permission/blob/8.3.2/docs/advanced-usage/events.md)) | — (no events in `src/`) | — (model events `roleAdded` etc., no grantor/reason: [events](https://github.com/santigarcor/laratrust/blob/8.5.6/docs/docs/8.x/usage/events.md)) | You write it |
| **Appointment title** | `title` column | — (`model_has_roles` has a composite primary key and no extra columns: [migration](https://github.com/spatie/laravel-permission/blob/8.3.2/database/migrations/create_permission_tables.php.stub)) | — | — | You write it |
| **"Who can do X here?"** | `Access::holders('x', $scope)` — deny, validity, scope applied | `User::permission('x')` scope, for the current global team ([basic usage](https://github.com/spatie/laravel-permission/blob/8.3.2/docs/basic-usage/basic-usage.md)) | By role only: `whereIs('admin')` ([source](https://github.com/JosephSilber/bouncer/blob/v1.0.4/src/Database/Concerns/HasRoles.php)); not by ability | `whereHasPermission('x', $team)` ([source](https://github.com/santigarcor/laratrust/blob/8.5.6/src/Traits/HasLaratrustScopes.php)) | — (loop over users) |
| **Octane / queue global state** | None: target passed on every call; memo is a `scoped` binding | Global team id via `setPermissionsTeamId()`; must be set per request/job; optional Octane reset listener ([cache](https://github.com/spatie/laravel-permission/blob/8.3.2/docs/advanced-usage/cache.md#octane-cache-reset)) | Static scope (`Models::$scope`, [source](https://github.com/JosephSilber/bouncer/blob/v1.0.4/src/Database/Models.php)) set by middleware | None for checks: team is a call argument | None |
| **Sanctum / API tokens** | Optional `tokenCan` intersection by duck typing, no dependency | — (Passport client-credentials guide only: [passport](https://github.com/spatie/laravel-permission/blob/8.3.2/docs/basic-usage/passport.md)) | — | — | `tokenCan()` by hand |
| **Wildcard** | Write-time expansion against the catalogue (`billing.*`) | Optional read-time wildcard ([wildcard](https://github.com/spatie/laravel-permission/blob/8.3.2/docs/basic-usage/wildcard-permissions.md)) | `everything()`, `toManage(Model)` ([README](https://github.com/JosephSilber/bouncer/blob/v1.0.4/README.md)) | `hasPermission('admin.*')` ([roles and permissions](https://github.com/santigarcor/laratrust/blob/8.5.6/docs/docs/8.x/usage/roles-and-permissions.md)) | — |
| **Cache** | Per-request memo + versioned per-tenant cache, any driver, no tags | One cached permission set, 24 h default, reset on change ([cache](https://github.com/spatie/laravel-permission/blob/8.3.2/docs/advanced-usage/cache.md)) | Per-request; cross-request optional; full refresh uses cache tags when available ([README "Cache"](https://github.com/JosephSilber/bouncer/blob/v1.0.4/README.md#cache)) | Optional, on in production by default ([config](https://github.com/santigarcor/laratrust/blob/8.5.6/config/laratrust.php)) | — |
| **Owner / super-admin** | No bypass; owner gets a full profile like anyone | Recommends `Gate::before` ([super-admin](https://github.com/spatie/laravel-permission/blob/8.3.2/docs/basic-usage/super-admin.md)) | `everything()` | role check | `Gate::before` |
| **Customer-editable profiles per tenant** | Template + tenant copy (`based_on_id`) | Team roles (`team_id` on `roles`) | Scoped roles | Roles are global; teams scope the assignment (`team_id` only on `role_user` / `permission_user`: [migration](https://github.com/santigarcor/laratrust/blob/8.5.6/resources/views/migration.blade.php)) | — |
| **Laravel versions** | 11, 12, 13 (Q2 proposes 12, 13) | 12, 13 | 11, 12, 13 | 10, 11, 12, 13 | — |
| **Maintenance** | new | Active: 8.3.0 (2026-07-03), 8.3.1 + 8.3.2 (2026-10-19) | Slow: v1.0.2 (2024-03-14), v1.0.3 (2025-02-24), v1.0.4 (2026-03-18) | Active: 8.5.4 (2026-02-23), 8.5.5 (2026-03-06), 8.5.6 (2026-10-22) | Laravel core |

Release dates come from the Packagist metadata linked in the version table.

## Reading the table honestly

- **spatie/laravel-permission** is the safe default for a single-tenant or one-dimension-team app. It has the biggest community.
  Our package is worth it only when you need the rows where Spatie shows "—": scope sets, deny, validity, grantor/title audit.
- **Bouncer** is the closest in spirit (it has deny). It lacks validity, audit and a two-level scope, keeps tenant state static,
  and releases about once a year.
- **Laratrust** passes the team explicitly (good for queues) but pulls in the whole framework package and has no deny or expiry.
- **Plain Gate + policies** is enough when roles are few and fixed in code. Our package sits on top of the Gate, not instead of it.
