<?php

namespace Seisigma\AccessProfiles;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\Builders\ChangeBuilder;
use Seisigma\AccessProfiles\Builders\GrantBuilder;
use Seisigma\AccessProfiles\Builders\ProfileBuilder;
use Seisigma\AccessProfiles\Builders\RevokeBuilder;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Queries\History;
use Seisigma\AccessProfiles\Queries\HoldersQuery;

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

    /**
     * Everyone who holds the permission on the target, deny applied.
     *
     * @param  class-string<Model>|null  $holderClass  null = config('auth.providers.users.model')
     * @return Collection<int, Model>
     */
    public function holders(string $permission, Model $target, ?string $holderClass = null): Collection
    {
        return $this->holdersQuery($permission, $target, $holderClass)->get();
    }

    /**
     * The holders() query, to paginate, count or filter further.
     *
     * @param  class-string<Model>|null  $holderClass  null = config('auth.providers.users.model')
     * @return Builder<Model>
     */
    public function holdersQuery(string $permission, Model $target, ?string $holderClass = null): Builder
    {
        return app(HoldersQuery::class)->query($permission, $target, $holderClass);
    }

    /**
     * Every assignment of the holder in the target's tenant, revoked and expired included.
     *
     * @return Collection<int, Assignment>
     */
    public function history(Model $holder, Model $target): Collection
    {
        return app(History::class)->of($holder, $target);
    }
}
