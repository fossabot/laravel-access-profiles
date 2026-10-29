<?php

use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\AccessProfilesServiceProvider;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Catalogue\UnknownPermission;
use Seisigma\AccessProfiles\Contracts\PermissionCatalogue;
use Seisigma\AccessProfiles\Tests\Fixtures\TestPermission;

class SecondCatalogue implements PermissionCatalogue
{
    public static function keys(): array
    {
        return ['team.members.manage'];
    }
}

class DuplicateCatalogue implements PermissionCatalogue
{
    public static function keys(): array
    {
        return ['billing.invoice.view'];
    }
}

beforeEach(function () {
    config()->set('access-profiles.catalogues', [TestPermission::class, SecondCatalogue::class]);
    app()->forgetInstance(Catalogue::class);
});

it('merges catalogues and checks exact membership', function () {
    $catalogue = app(Catalogue::class);
    expect($catalogue->all())->toBe([...TestPermission::keys(), 'team.members.manage'])
        ->and($catalogue->has('billing.invoice.view'))->toBeTrue()
        ->and($catalogue->has('billing'))->toBeFalse()
        ->and($catalogue->has('nope.invoice.view'))->toBeFalse();
});

it('rejects duplicate keys at boot', function () {
    config()->set('access-profiles.catalogues', [TestPermission::class, DuplicateCatalogue::class]);
    (new AccessProfilesServiceProvider(app()))->boot();
})->throws(InvalidArgumentException::class, 'billing.invoice.view');

it('rejects malformed keys at boot', function (string $key) {
    $catalogue = new class($key) implements PermissionCatalogue
    {
        private static string $key;

        public function __construct(string $key)
        {
            self::$key = $key;
        }

        public static function keys(): array
        {
            return [self::$key];
        }
    };
    config()->set('access-profiles.catalogues', [$catalogue::class]);
    (new AccessProfilesServiceProvider(app()))->boot();
})->with(['billing', 'Billing.invoice.view', 'billing..view', 'billing.invoice.view.extra.more', 'billing.invoice.*', 'billing.invoice.view-', 'billing.invoice.view'."\n"])
    ->throws(InvalidArgumentException::class);

it('accepts lower snake keys', function () {
    $catalogue = new class implements PermissionCatalogue
    {
        public static function keys(): array
        {
            return ['billing_ops.invoice_item.view_all'];
        }
    };
    config()->set('access-profiles.catalogues', [$catalogue::class]);
    expect(app(Catalogue::class)->all())->toBe(['billing_ops.invoice_item.view_all']);
});

it('expands only the dotted prefix and exact existing keys', function () {
    $catalogue = app(Catalogue::class);
    expect($catalogue->expand('billing.*'))->toBe(['billing.invoice.view', 'billing.invoice.approve'])
        ->and($catalogue->expand('team.*'))->toBe(['team.members.manage'])
        ->and($catalogue->expand('billing.invoice.view'))->toBe(['billing.invoice.view']);
});

it('rejects unknown exact keys and patterns', function (string $pattern) {
    app(Catalogue::class)->expand($pattern);
})->with(['billing', 'nope.*', 'billing*', 'billing.invoice.missing'])->throws(UnknownPermission::class);

it('supports an empty catalogue', function () {
    config()->set('access-profiles.catalogues', []);
    expect(app(Catalogue::class)->all())->toBe([]);
});

it('fails census and names unknown stored keys', function () {
    $this->runPackageMigration();
    $id = DB::table('access_profiles')->insertGetId(['key' => 'reader', 'name' => 'Reader']);
    DB::table('access_profile_permissions')->insert(['profile_id' => $id, 'permission' => 'gone.invoice.view']);
    $this->artisan('access-profiles:census')->expectsOutputToContain('gone.invoice.view')->assertExitCode(1);
});

it('warns about unused keys without failing census', function () {
    config()->set('access-profiles.tables.profile_permissions', 'custom_rules');
    $this->runPackageMigration();
    $id = DB::table('access_profiles')->insertGetId(['key' => 'reader', 'name' => 'Reader']);
    DB::table('custom_rules')->insert(['profile_id' => $id, 'permission' => 'billing.invoice.view']);
    $this->artisan('access-profiles:census')
        ->doesntExpectOutputToContain('Unused permission: billing.invoice.view')
        ->expectsOutputToContain('Unused permission: billing.invoice.approve')->assertExitCode(0);
});

it('passes census when every key is used', function () {
    $this->runPackageMigration();
    $id = DB::table('access_profiles')->insertGetId(['key' => 'reader', 'name' => 'Reader']);
    foreach (app(Catalogue::class)->all() as $key) {
        DB::table('access_profile_permissions')->insert(['profile_id' => $id, 'permission' => $key]);
    }
    $this->artisan('access-profiles:census')->doesntExpectOutputToContain('Unused permission:')->assertExitCode(0);
});
