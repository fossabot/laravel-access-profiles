<?php

namespace Seisigma\AccessProfiles\Facades;

use Illuminate\Support\Facades\Facade;
use Seisigma\AccessProfiles\AccessManager;

/**
 * @method static \Seisigma\AccessProfiles\Access\HolderAccess for(\Illuminate\Database\Eloquent\Model $holder)
 * @method static \Seisigma\AccessProfiles\Builders\GrantBuilder grant(string|\Seisigma\AccessProfiles\Models\Profile $profile)
 * @method static \Seisigma\AccessProfiles\Builders\RevokeBuilder revoke(\Seisigma\AccessProfiles\Models\Assignment $assignment)
 * @method static \Seisigma\AccessProfiles\Builders\ChangeBuilder change(\Seisigma\AccessProfiles\Models\Assignment $assignment)
 * @method static \Seisigma\AccessProfiles\Builders\ProfileBuilder profile(string $key)
 *
 * @see AccessManager
 */
class Access extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AccessManager::class;
    }
}
