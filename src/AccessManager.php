<?php

namespace Seisigma\AccessProfiles;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\Builders\ChangeBuilder;
use Seisigma\AccessProfiles\Builders\GrantBuilder;
use Seisigma\AccessProfiles\Builders\ProfileBuilder;
use Seisigma\AccessProfiles\Builders\RevokeBuilder;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;

/**
 * The entry point behind the `Access` facade.
 */
class AccessManager
{
    public function for(Model $holder): HolderAccess
    {
        return HolderAccess::for($holder);
    }

    public function grant(string|Profile $profile): GrantBuilder
    {
        return new GrantBuilder($profile);
    }

    public function revoke(Assignment $assignment): RevokeBuilder
    {
        return new RevokeBuilder($assignment);
    }

    public function change(Assignment $assignment): ChangeBuilder
    {
        return new ChangeBuilder($assignment);
    }

    public function profile(string $key): ProfileBuilder
    {
        return new ProfileBuilder($key);
    }
}
