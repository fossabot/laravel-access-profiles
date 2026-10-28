<?php

use Illuminate\Support\Carbon;
use Seisigma\AccessProfiles\Builders\ProfileBuilder;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Catalogue\UnknownPermission;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Models\ProfileRule;
use Seisigma\AccessProfiles\Support\TenantVersion;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Rejection;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\TestPermission;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(ProfileBuilder::class);

beforeEach(function () {
    config()->set('access-profiles.cache.store', 'array');
    config()->set('access-profiles.catalogues', [TestPermission::class]);
    app()->forgetInstance(Catalogue::class);
    $this->runPackageMigration();
    (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
    Carbon::setTestNow('2026-10-28 12:00:00');
    $this->team = Team::create();
    $this->template = Grants::profile('auditor', ['billing.invoice.view'], ['billing.invoice.approve']);
    $this->template->update(['description' => 'Reads records', 'requires_condition' => 'audits']);
});

afterEach(fn () => Carbon::setTestNow());

function rulesOf(Profile $profile): array
{
    return ProfileRule::where('profile_id', $profile->id)->orderBy('permission')->get()
        ->mapWithKeys(fn (ProfileRule $rule) => [$rule->permission => $rule->effect->value])->all();
}

it('expands a wildcard into exact keys of that module only', function () {
    $profile = Access::profile('billing_admin')->in($this->team)->allow('billing.*')->save();

    expect(rulesOf($profile))->toBe(['billing.invoice.approve' => 'allow', 'billing.invoice.view' => 'allow'])
        ->and($profile->tenant->is($this->team))->toBeTrue()
        ->and($profile->is_system)->toBeFalse()
        ->and($profile->name)->toBe('billing_admin')
        ->and($profile->version)->toBe(1);
});

it('stores a deny that wins over an allow of the same key', function () {
    $profile = Access::profile('clerk')->in($this->team)->allow('billing.*')->deny('billing.invoice.approve')->save();

    expect(rulesOf($profile))->toBe(['billing.invoice.approve' => 'deny', 'billing.invoice.view' => 'allow']);
});

it('lets a deny win whatever order allow and deny are called in', function () {
    $profile = Access::profile('clerk')->in($this->team)->deny('billing.invoice.approve')->allow('billing.*')->save();

    expect(rulesOf($profile))->toBe(['billing.invoice.approve' => 'deny', 'billing.invoice.view' => 'allow']);
});

it('uses the given name and description', function () {
    $profile = Access::profile('clerk')->in($this->team)->named('Clerk', 'Front desk')->allow('billing.invoice.view')->save();

    expect($profile->name)->toBe('Clerk')->and($profile->description)->toBe('Front desk');
});

it('throws for an unknown key and writes nothing', function () {
    Access::profile('clerk')->in($this->team)->allow('billing.invoice.view')->allow('nope.*')->save();
})->throws(UnknownPermission::class);

it('leaves no profile behind after an unknown key', function () {
    try {
        Access::profile('clerk')->in($this->team)->allow('billing.invoice.view')->deny('nope.thing.do')->save();
    } catch (UnknownPermission) {
    }

    expect(Profile::where('key', 'clerk')->exists())->toBeFalse();
});

it('rejects editing a system template', function () {
    expect(Rejection::of(fn () => Access::profile('auditor')->allow('billing.*')->save()))->toBe(GrantRejected::SYSTEM_TEMPLATE)
        ->and(rulesOf($this->template))->toBe(['billing.invoice.approve' => 'deny', 'billing.invoice.view' => 'allow']);
});

it('rejects editing a profile flagged as system even inside a tenant', function () {
    $flagged = Grants::profile('locked', ['billing.invoice.view'], tenant: $this->team);
    $flagged->update(['is_system' => true]);

    expect(Rejection::of(fn () => Access::profile('locked')->in($this->team)->allow('billing.*')->save()))->toBe(GrantRejected::SYSTEM_TEMPLATE)
        ->and(rulesOf($flagged))->toBe(['billing.invoice.view' => 'allow']);
});

it('rejects a scope given as the tenant of a profile', function () {
    $site = Site::create(['team_id' => $this->team->id]);

    expect(Rejection::of(fn () => Access::profile('clerk')->in($site)->allow('billing.*')->save()))->toBe(GrantRejected::NOT_A_TENANT);
});

it('copies a template into the tenant', function () {
    $copy = Access::profile('auditor')->in($this->team)->copyFromTemplate();

    expect($copy->id)->not->toBe($this->template->id)
        ->and($copy->tenant->is($this->team))->toBeTrue()
        ->and($copy->based_on_id)->toBe($this->template->id)
        ->and($copy->is_system)->toBeFalse()
        ->and($copy->name)->toBe('Auditor')
        ->and($copy->description)->toBe('Reads records')
        ->and($copy->requires_condition)->toBe('audits')
        ->and($copy->version)->toBe(1)
        ->and(rulesOf($copy))->toBe(rulesOf($this->template));
});

it('rejects a second copy and a copy without a template', function () {
    Access::profile('auditor')->in($this->team)->copyFromTemplate();

    expect(Rejection::of(fn () => Access::profile('auditor')->in($this->team)->copyFromTemplate()))->toBe(GrantRejected::DUPLICATE_PROFILE)
        ->and(Rejection::of(fn () => Access::profile('missing')->in($this->team)->copyFromTemplate()))->toBe(GrantRejected::UNKNOWN_PROFILE)
        ->and(Rejection::of(fn () => Access::profile('auditor')->copyFromTemplate()))->toBe(GrantRejected::SYSTEM_TEMPLATE);
});

it('does not copy a soft-deleted template', function () {
    $this->template->delete();

    expect(Rejection::of(fn () => Access::profile('auditor')->in($this->team)->copyFromTemplate()))->toBe(GrantRejected::UNKNOWN_PROFILE);
});

it('adds rules to an existing copy and bumps its version', function () {
    Access::profile('auditor')->in($this->team)->copyFromTemplate();

    $profile = Access::profile('auditor')->in($this->team)->allow('billingx.invoice.view')->save();

    expect(rulesOf($profile))->toBe([
        'billing.invoice.approve' => 'deny',
        'billing.invoice.view' => 'allow',
        'billingx.invoice.view' => 'allow',
    ])->and($profile->fresh()->version)->toBe(2)
        ->and(Profile::where('key', 'auditor')->count())->toBe(2);
});

it('turns an allow into a deny and removes a rule with forget', function () {
    Access::profile('auditor')->in($this->team)->copyFromTemplate();

    $profile = Access::profile('auditor')->in($this->team)->deny('billing.invoice.view')->forget('billing.invoice.approve')->save();

    expect(rulesOf($profile))->toBe(['billing.invoice.view' => 'deny']);
});

it('keeps the version when nothing changed', function () {
    Access::profile('auditor')->in($this->team)->copyFromTemplate();

    $profile = Access::profile('auditor')->in($this->team)->allow('billing.invoice.view')->save();

    expect($profile->fresh()->version)->toBe(1);
});

it('never touches the template when editing its copy', function () {
    Access::profile('auditor')->in($this->team)->copyFromTemplate();

    Access::profile('auditor')->in($this->team)->allow('billing.*')->save();

    expect(rulesOf($this->template))->toBe(['billing.invoice.approve' => 'deny', 'billing.invoice.view' => 'allow'])
        ->and($this->template->fresh()->version)->toBe(1);
});

it('bumps the tenant version on copy and on save', function () {
    $versions = app(TenantVersion::class);
    $start = $versions->current($this->team);

    Access::profile('auditor')->in($this->team)->copyFromTemplate();
    $afterCopy = $versions->current($this->team);
    Access::profile('auditor')->in($this->team)->allow('billing.*')->save();

    expect($afterCopy)->toBe($start + 1)
        ->and($versions->current($this->team))->toBe($start + 2);
});

it('changes what holders of the copy can do at once', function () {
    $copy = Access::profile('auditor')->in($this->team)->copyFromTemplate();
    $copy->update(['requires_condition' => null]);
    $user = User::create();
    Grants::assign($user, Grants::profile('reader', ['billing.invoice.view']), $this->team);
    Grants::assign($user, $copy, $this->team);
    expect(Access::for($user)->can('billing.invoice.view', $this->team))->toBeTrue();

    Access::profile('auditor')->in($this->team)->deny('billing.invoice.view')->save();

    expect(Access::for($user)->can('billing.invoice.view', $this->team))->toBeFalse();
});
