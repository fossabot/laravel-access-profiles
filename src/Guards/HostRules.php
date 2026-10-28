<?php

namespace Seisigma\AccessProfiles\Guards;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Seisigma\AccessProfiles\Contracts\AssignmentRule;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Models\Profile;

/**
 * Runs every class in `config('access-profiles.guards.rules')`, in order.
 */
class HostRules
{
    /** @param list<Model> $scopes empty = the whole tenant */
    public function check(Model $holder, Profile $profile, Model $tenant, array $scopes): void
    {
        foreach (config('access-profiles.guards.rules', []) as $class) {
            $rule = app($class);

            if (! $rule instanceof AssignmentRule) {
                throw new InvalidArgumentException('Assignment rules must implement '.AssignmentRule::class);
            }

            if (! $rule->allows($holder, $profile, $tenant, $scopes)) {
                throw GrantRejected::rule($rule->name());
            }
        }
    }
}
