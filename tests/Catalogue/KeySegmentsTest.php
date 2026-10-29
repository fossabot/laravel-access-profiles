<?php

use Illuminate\Support\Carbon;
use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\AccessProfilesServiceProvider;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Catalogue\UnknownPermission;
use Seisigma\AccessProfiles\Contracts\PermissionCatalogue;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Tests\Fixtures\Grants;
use Seisigma\AccessProfiles\Tests\Fixtures\Rejection;
use Seisigma\AccessProfiles\Tests\Fixtures\Site;
use Seisigma\AccessProfiles\Tests\Fixtures\Team;
use Seisigma\AccessProfiles\Tests\Fixtures\User;

mutates(Catalogue::class);

class SegmentCatalogue implements PermissionCatalogue
{
    public static function keys(): array
    {
        return [
            'finance.repair',
            'finance.configure',
            'access.assign',
            'finance.expense.void',
            'finance.expense.void.request',
            'finance.expense.draft.delete',
            'financex.expense.view',
        ];
    }
}

function catalogueOf(string $key): void
{
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
}

beforeEach(function () {
    config()->set('access-profiles.catalogues', [SegmentCatalogue::class]);
    app()->forgetInstance(Catalogue::class);
});

it('accepts keys of two to four segments', function (string $key) {
    catalogueOf($key);
    expect(app(Catalogue::class)->all())->toBe([$key]);
})->with(['finance.repair', 'access_ops.assign_all', 'billing.invoice.view', 'finance.expense.void.request', 'fin_ops.expense_item.draft.delete_all']);

it('rejects keys of one or five segments and upper case', function (string $key) {
    catalogueOf($key);
})->with(['finance', 'finance.expense.void.request.extra', 'Finance.repair', 'finance.Expense.void.request', 'finance.expense.void.Request'])
    ->throws(InvalidArgumentException::class, 'Invalid permission key');

it('expands a wildcard at every depth', function () {
    $catalogue = app(Catalogue::class);
    expect($catalogue->expand('finance.*'))->toBe([
        'finance.repair', 'finance.configure', 'finance.expense.void', 'finance.expense.void.request', 'finance.expense.draft.delete',
    ])
        ->and($catalogue->expand('access.*'))->toBe(['access.assign'])
        ->and($catalogue->expand('finance.expense.*'))->toBe(['finance.expense.void', 'finance.expense.void.request', 'finance.expense.draft.delete'])
        ->and($catalogue->expand('finance.expense.draft.*'))->toBe(['finance.expense.draft.delete']);
});

it('keeps a key that is a prefix of a longer key separate', function () {
    $catalogue = app(Catalogue::class);
    expect($catalogue->expand('finance.expense.void'))->toBe(['finance.expense.void'])
        ->and($catalogue->expand('finance.expense.void.*'))->toBe(['finance.expense.void.request']);
});

it('rejects wildcards that match no key', function (string $pattern) {
    app(Catalogue::class)->expand($pattern);
})->with(['finance.repair.*', 'finance.expense.void.request.*', 'finance.exp*', 'finance.expense'])->throws(UnknownPermission::class);

describe('with the database', function () {
    beforeEach(function () {
        config()->set('access-profiles.cache.store', 'array');
        $this->runPackageMigration();
        (include __DIR__.'/../Fixtures/migrations/create_fixture_tables.php')->up();
        Carbon::setTestNow('2026-10-29 12:00:00');
        $this->team = Team::create();
        $this->site = Site::create(['team_id' => $this->team->id]);
        $this->user = User::create();
        $this->grantor = User::create();
    });

    afterEach(fn () => Carbon::setTestNow());

    it('grants a prefix key only itself and its wildcard only the longer keys', function () {
        $short = Access::profile('voider')->in($this->team)->allow('finance.expense.void')->save();
        $long = Access::profile('requester')->in($this->team)->allow('finance.expense.void.*')->save();
        $other = User::create();
        Grants::assign($this->user, $short, $this->team);
        Grants::assign($other, $long, $this->team);

        expect(HolderAccess::for($this->user)->can('finance.expense.void', $this->site))->toBeTrue()
            ->and(HolderAccess::for($this->user)->can('finance.expense.void.request', $this->site))->toBeFalse()
            ->and(HolderAccess::for($other)->can('finance.expense.void.request', $this->site))->toBeTrue()
            ->and(HolderAccess::for($other)->can('finance.expense.void', $this->site))->toBeFalse();
    });

    it('lets a deny wildcard block a four segment key', function () {
        $profile = Access::profile('limited')->in($this->team)
            ->allow('finance.*')->deny('finance.expense.void.*')->save();
        Grants::assign($this->user, $profile, $this->team);
        $access = HolderAccess::for($this->user);

        expect($access->can('finance.expense.void.request', $this->site))->toBeFalse()
            ->and($access->can('finance.expense.draft.delete', $this->site))->toBeTrue()
            ->and($access->can('finance.expense.void', $this->site))->toBeTrue()
            ->and($access->can('finance.repair', $this->site))->toBeTrue();
    });

    it('rejects escalation of a four segment key the grantor lacks', function () {
        $full = Access::profile('full')->in($this->team)->allow('finance.expense.*')->save();
        $partial = Access::profile('partial')->in($this->team)->allow('finance.expense.void')->allow('finance.expense.draft.delete')->save();
        $holder = User::create();
        Grants::assign($this->grantor, $partial, $this->team);
        Grants::assign($holder, $full, $this->team);
        $grant = fn (User $by) => fn () => Access::grant($full)->to($this->user)->in($this->team)->by($by)->save();

        expect(Rejection::of($grant($this->grantor)))->toBe(GrantRejected::ESCALATION)
            ->and(Rejection::of($grant($holder)))->toBeNull();
    });
});
