<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Seisigma\AccessProfiles\Concerns\HasAccessProfiles;

class User extends Authenticatable
{
    use HasAccessProfiles;

    protected $guarded = [];
}
