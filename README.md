# Laravel Access Profiles

> **Status: design draft.** The API below is the planned API ([docs/DESIGN.md](docs/DESIGN.md)). Nothing is released yet.

**Give people different powers in different parts of the same tenant — with deny rules, expiry dates and a record of who granted what, and why.**

## Why this package

Each point links to the row in [docs/COMPARISON.md](docs/COMPARISON.md) that backs it.

- **Two-level scope.** One assignment covers a whole tenant *or* a chosen set of scopes inside it (site 3 and site 7). Tenants and scopes are any Eloquent models. → rows *Multi-tenant scope*, *One assignment → a set of scopes*
- **Deny wins.** Allow all of `billing.*` and deny `billing.bank.reveal` in the same profile. → row *Deny*
- **Access that ends by itself.** `validUntil()` on an assignment; no scheduler needed. → row *Expiry / validity window*
- **An audit trail built in.** Every assignment stores the grantor, the reason and an optional title ("Board treasurer"), and fires events you can log. → rows *Grantor + reason audit*, *Appointment title*
- **"Who can approve this here?"** is one query that already applies deny, expiry and scope. → row *"Who can do X here?"*
- **Safe in queues and Octane.** No global "current team". You pass the target on every call. → row *Octane / queue global state*
- **No super-admin backdoor.** No `Gate::before`. Owners get a full profile like everyone else, and you can protect the last holder of that profile from removal. → row *Owner / super-admin*
- **Tiny footprint.** Runtime dependencies: `php` and `illuminate/*`. Sanctum token checks work without requiring Sanctum. → rows *Runtime deps*, *Sanctum / API tokens*

## Installation

```bash
composer require seisigma/laravel-access-profiles
php artisan vendor:publish --tag=access-profiles-migrations
php artisan vendor:publish --tag=access-profiles-config
php artisan migrate
```

## Usage

```php
// 1. List your permissions in code (a backed enum implementing PermissionCatalogue).
// 2. Grant a profile to a user for two sites of an organisation, for six months.
Access::grant('site_manager')->to($ana)->in($organisation)->onlyScopes([$site3, $site7])
    ->validUntil(now()->addMonths(6))->by($admin)->because('Covers parental leave')->save();

// 3. Check it — in a policy, a controller or a job.
$ana->canAccess('billing.invoice.approve', $site3);   // true
$ana->canAccess('billing.invoice.approve', $site5);   // false: not her scope

// 4. Ask the other way round.
Access::holders('billing.invoice.approve', $site3);   // everyone who can, right now
```

## When NOT to use this package

- **Your app has one tenant, or one flat "team" level.** Use [`spatie/laravel-permission`](https://github.com/spatie/laravel-permission) — bigger community, more tutorials.
- **Your roles are few and fixed in code.** Laravel's own [Gates and policies](https://laravel.com/docs/13.x/authorization) are enough.
- **You need record-level rules** ("only the author edits this post"). Write those in your policy; this package does not model records.
- **You need an admin UI out of the box.** There is none. You build the screens.
- **You need a mature, proven package today.** This one is new. Read the code before you trust it with access control.

## Testing

```bash
composer test
composer analyse
composer format
```

## Credits

- Ricardo A. Vargas R. (Seisigma SRL)
- Package layout follows [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
