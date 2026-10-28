<?php

namespace Seisigma\AccessProfiles\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Decides per tenant whether a profile's `requires_condition` switch is on.
 * A profile whose condition is off is ignored completely: its allow and its deny.
 * Read at check time, never cached by the package.
 */
interface ProfileConditions
{
    public function isOn(string $condition, Model $tenant): bool;
}
