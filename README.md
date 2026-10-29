# Laravel Access Profiles

[![Latest Version on Packagist](https://img.shields.io/packagist/v/seisigmasrl/laravel-access-profiles.svg?style=flat-square)](https://packagist.org/packages/seisigmasrl/laravel-access-profiles)
[![Tests](https://img.shields.io/github/actions/workflow/status/seisigmasrl/laravel-access-profiles/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/seisigmasrl/laravel-access-profiles/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/seisigmasrl/laravel-access-profiles/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/seisigmasrl/laravel-access-profiles/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/seisigmasrl/laravel-access-profiles.svg?style=flat-square)](https://packagist.org/packages/seisigmasrl/laravel-access-profiles)
[![FOSSA Status](https://app.fossa.com/api/projects/git%2Bgithub.com%2Fseisigmasrl%2Flaravel-access-profiles.svg?type=shield)](https://app.fossa.com/projects/git%2Bgithub.com%2Fseisigmasrl%2Flaravel-access-profiles?ref=badge_shield)

Give people different permissions in different parts of a tenant. Profiles combine allow and deny rules, and any matching deny wins. Assignments cover a whole tenant or selected scopes, with validity dates and a record of who granted access and why.

Access is denied by default. There is no global current tenant and no owner bypass: every check takes a target.

## Requirements

PHP 8.3+ and Laravel 12.69+ or 13.30+. Runtime dependencies are PHP and `illuminate/*`. See [Getting started](https://laravel-access-profiles.pages.dev/docs/getting-started/#requirements).

## Installation

```bash
composer require seisigmasrl/laravel-access-profiles
php artisan vendor:publish --tag=access-profiles-config
php artisan vendor:publish --tag=access-profiles-migrations
```

Define your [catalogue and templates](https://laravel-access-profiles.pages.dev/docs/catalogue-and-templates/) and choose table names. Then migrate and sync the templates:

```bash
php artisan migrate
php artisan access-profiles:sync-templates
```

## Usage

Grant a profile in two sites of a team for six months. The grantor and the reason are stored with the assignment.

```php
$assignment = Access::grant('reviewer')->to($user)->in($team)
    ->onlyScopes([$site, $otherSite])->titled('Review cover')
    ->validFrom(now())->validUntil(now()->addMonths(6))
    ->by($admin)->because('Cover leave')->save();
```

Then check access with `Access::for($user)->can('billing.invoice.view', $site)` or `$user->canAccess(...)`.

## Documentation

- [Getting started](https://laravel-access-profiles.pages.dev/docs/getting-started/): requirements, install, config, commands.
- [Concepts](https://laravel-access-profiles.pages.dev/docs/concepts/): tenant, scope, profile, assignment and the decision rules.
- [Catalogue and templates](https://laravel-access-profiles.pages.dev/docs/catalogue-and-templates/): keys, templates, profiles, assignments and guards.
- [Checking access](https://laravel-access-profiles.pages.dev/docs/checking-access/): checks, Blade, Inertia, policies and queues.
- [Scoped queries](https://laravel-access-profiles.pages.dev/docs/scoped-queries/): who holds a key, which scopes a holder reaches, history.
- [Events and audit](https://laravel-access-profiles.pages.dev/docs/events-and-audit/): write events for your audit log.
- [Testing](https://laravel-access-profiles.pages.dev/docs/testing/) and [Upgrading](https://laravel-access-profiles.pages.dev/docs/upgrading/).
- [DESIGN.md](docs/DESIGN.md) for the decisions and [COMPARISON.md](docs/COMPARISON.md) for alternatives.

## When not to use it

- One tenant or one flat team level: consider [spatie/laravel-permission](https://github.com/spatie/laravel-permission).
- A few fixed roles: Laravel's Gates and policies may be enough.
- Record-level rules or an admin UI out of the box: this package has neither.
- A mature, proven package today: this package is new. Review it before you rely on it for access control.

## Testing

```bash
composer test
```

Each fenced PHP example in this README has a behaviour test in [ReadmeExamplesTest.php](tests/Docs/ReadmeExamplesTest.php).

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Ricardo A. Vargas R.](https://github.com/ricardov03)
- [All Contributors](../../contributors)

Package layout follows [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[![FOSSA Status](https://app.fossa.com/api/projects/git%2Bgithub.com%2Fseisigmasrl%2Flaravel-access-profiles.svg?type=large)](https://app.fossa.com/projects/git%2Bgithub.com%2Fseisigmasrl%2Flaravel-access-profiles?ref=badge_large)
