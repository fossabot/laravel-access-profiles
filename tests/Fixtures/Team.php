<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\HasAccessTenant;

class Team extends Model implements HasAccessTenant
{
    protected $guarded = [];

    public function accessTenant(): Model
    {
        return $this;
    }
}
