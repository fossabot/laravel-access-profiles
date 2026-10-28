<?php

// config for Seisigma/AccessProfiles — see docs/DESIGN.md §5.1 for what each key does.
return [

    /*
     * Classes that list every permission key the app knows. Each class is a
     * backed enum (or any class) implementing the PermissionCatalogue contract.
     * Keys stored in the database that are not in a catalogue are rejected on
     * write and reported by the census command.
     */
    'catalogues' => [
        // App\Access\Permission::class,
    ],

    /*
     * Table names. Change them before you run the migration.
     */
    'tables' => [
        'profiles' => 'access_profiles',
        'profile_permissions' => 'access_profile_permissions',
        'assignments' => 'access_assignments',
        'assignment_scopes' => 'access_assignment_scopes',
    ],

    /*
     * Cross-request cache of resolved grants. Every write bumps a per-tenant
     * version, so stale entries are never read. `store` null = default store.
     * TTL is also capped by the next valid_from / valid_until boundary.
     */
    'cache' => [
        'enabled' => true,
        'store' => null,
        'ttl' => 60 * 60 * 24,
        'prefix' => 'access-profiles',
    ],

    /*
     * When the holder authenticated with an API token (e.g. Sanctum), also
     * require $holder->tokenCan($permission). Detected by duck typing; the
     * package does not depend on laravel/sanctum.
     */
    'token_intersection' => true,

    /*
     * Write-time guards on grant/revoke. See docs/DESIGN.md §4.
     */
    'guards' => [
        'no_escalation' => true,
        'no_self_assignment' => true,
        // Profile keys whose last active holder in a tenant cannot be revoked.
        'protected_profiles' => [],
    ],

    /*
     * Fire AccessChecked on every decision (for shadow mode / debugging).
     * Grant, revoke and profile events always fire.
     */
    'events' => [
        'access_checked' => false,
    ],

    /*
     * Extension points. Swap any binding for your own implementation.
     */
    'bindings' => [
        // Contracts\ScopeOwnership::class => App\Access\ScopeOwnership::class,
        // Contracts\ProfileConditions::class => App\Access\ProfileConditions::class,
        // Contracts\AssignmentRule::class => App\Access\BoardSeatRule::class,
    ],

];
