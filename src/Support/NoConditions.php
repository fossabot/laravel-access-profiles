<?php

namespace Seisigma\AccessProfiles\Support;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\ProfileConditions;

class NoConditions implements ProfileConditions
{
    public function isOn(string $condition, Model $tenant): bool
    {
        return false;
    }
}
