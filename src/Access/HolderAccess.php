<?php

namespace Seisigma\AccessProfiles\Access;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\Resolver;
use Seisigma\AccessProfiles\Queries\ScopeSet;
use Seisigma\AccessProfiles\Resolver\Decision;

/**
 * Checks for one holder: `Access::for($holder)->can($key, $target)`.
 */
class HolderAccess
{
    public function __construct(private readonly Resolver $resolver, private readonly Model $holder) {}

    public static function for(Model $holder): self
    {
        return new self(app(Resolver::class), $holder);
    }

    public function can(string $permission, Model $target): bool
    {
        return $this->explain($permission, $target)->allowed;
    }

    /** @throws AuthorizationException */
    public function authorize(string $permission, Model $target): void
    {
        if (! $this->can($permission, $target)) {
            throw new AuthorizationException;
        }
    }

    public function explain(string $permission, Model $target): Decision
    {
        return $this->resolver->decide($this->holder, $permission, $target);
    }

    /** @return list<string> */
    public function permissionsIn(Model $target): array
    {
        return $this->resolver->permissionsIn($this->holder, $target);
    }

    /** Where the holder has the permission in a tenant (a scope target means its tenant). */
    public function scopesWhere(string $permission, Model $tenant): ScopeSet
    {
        return $this->resolver->scopesWhere($this->holder, $permission, $tenant);
    }
}
