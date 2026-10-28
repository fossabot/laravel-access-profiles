<?php

namespace Seisigma\AccessProfiles\Support;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\HasAccessTenant;
use Seisigma\AccessProfiles\Contracts\ScopeOwnership;
use Seisigma\AccessProfiles\Exceptions\UnknownAccessTenant;

class DefaultScopeOwnership implements ScopeOwnership
{
    public function tenantOf(Model $target): Model
    {
        if ($target instanceof HasAccessTenant) {
            return $target->accessTenant();
        }

        throw new UnknownAccessTenant('Cannot resolve access tenant for '.$target::class.': implement '.HasAccessTenant::class);
    }
}
