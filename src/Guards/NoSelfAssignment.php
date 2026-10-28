<?php

namespace Seisigma\AccessProfiles\Guards;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;

class NoSelfAssignment
{
    /**
     * The grantor cannot grant, change or revoke their own assignment.
     * Records are compared (table + key), not object identity.
     */
    public function check(Model $grantor, ?Model $holder): void
    {
        if (! config('access-profiles.guards.no_self_assignment')) {
            return;
        }

        if ($holder !== null && $grantor->is($holder)) {
            throw new GrantRejected(GrantRejected::SELF_ASSIGNMENT);
        }
    }
}
