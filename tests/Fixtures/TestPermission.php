<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Seisigma\AccessProfiles\Contracts\PermissionCatalogue;

enum TestPermission: string implements PermissionCatalogue
{
    case View = 'billing.invoice.view';
    case Approve = 'billing.invoice.approve';
    case Other = 'billingx.invoice.view';

    public static function keys(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
