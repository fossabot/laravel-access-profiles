<?php

namespace Seisigma\AccessProfiles\Contracts;

interface PermissionCatalogue
{
    /** @return list<string> */
    public static function keys(): array;
}
