<?php

namespace Seisigma\AccessProfiles\Contracts;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Models\Profile;

/**
 * A host rule checked on every grant and change, system grants included.
 * List the classes in `config('access-profiles.guards.rules')`.
 */
interface AssignmentRule
{
    /** Short name used in the rejection code `rule:<name>`. */
    public function name(): string;

    /** @param list<Model> $scopes the scopes of the assignment; empty = the whole tenant */
    public function allows(Model $holder, Profile $profile, Model $tenant, array $scopes): bool;
}
