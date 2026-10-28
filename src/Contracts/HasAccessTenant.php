<?php

namespace Seisigma\AccessProfiles\Contracts;

use Illuminate\Database\Eloquent\Model;

interface HasAccessTenant
{
    public function accessTenant(): Model;
}
