<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\AssignmentRule;
use Seisigma\AccessProfiles\Models\Profile;

class OpenRule implements AssignmentRule
{
    public function name(): string
    {
        return 'open';
    }

    public function allows(Model $holder, Profile $profile, Model $tenant, array $scopes): bool
    {
        return true;
    }
}
