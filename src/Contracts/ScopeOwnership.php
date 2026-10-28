<?php

namespace Seisigma\AccessProfiles\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ScopeOwnership
{
    public function tenantOf(Model $target): Model;
}
