<?php

namespace Seisigma\AccessProfiles\Contracts;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Queries\ScopeSet;
use Seisigma\AccessProfiles\Resolver\Decision;

interface Resolver
{
    /** Decide whether the holder has the permission on the target (a tenant or a scope). */
    public function decide(Model $holder, string $permission, Model $target): Decision;

    /** @return list<string> every key the holder is allowed on the target, sorted */
    public function permissionsIn(Model $holder, Model $target): array;

    /** The scopes of the target's tenant where the holder has the permission. */
    public function scopesWhere(Model $holder, string $permission, Model $target): ScopeSet;
}
