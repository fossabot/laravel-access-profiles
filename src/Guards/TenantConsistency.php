<?php

namespace Seisigma\AccessProfiles\Guards;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\ScopeOwnership;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Exceptions\UnknownAccessTenant;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;

/**
 * Always on: no config switch reaches this class.
 */
class TenantConsistency
{
    public function __construct(private readonly ScopeOwnership $ownership) {}

    public function tenant(Model $tenant): void
    {
        try {
            $owner = $this->ownership->tenantOf($tenant);
        } catch (UnknownAccessTenant) {
            throw new GrantRejected(GrantRejected::NOT_A_TENANT);
        }

        if (! $owner->is($tenant)) {
            throw new GrantRejected(GrantRejected::NOT_A_TENANT);
        }
    }

    /** A template (no tenant) or a copy of this same tenant. */
    public function profile(Profile $profile, Model $tenant): void
    {
        $type = $profile->getAttribute('tenant_type');

        if ($type === null) {
            return;
        }

        if ($type !== $tenant->getMorphClass() || (string) $profile->getAttribute('tenant_id') !== (string) $tenant->getKey()) {
            throw new GrantRejected(GrantRejected::PROFILE_OUTSIDE_TENANT);
        }
    }

    /** @param list<Model> $scopes */
    public function scopes(array $scopes, Model $tenant): void
    {
        foreach ($scopes as $scope) {
            if ($scope->is($tenant)) {
                throw new GrantRejected(GrantRejected::SCOPE_IS_TENANT);
            }

            try {
                $owner = $this->ownership->tenantOf($scope);
            } catch (UnknownAccessTenant) {
                throw new GrantRejected(GrantRejected::SCOPE_OUTSIDE_TENANT);
            }

            if (! $owner->is($tenant)) {
                throw new GrantRejected(GrantRejected::SCOPE_OUTSIDE_TENANT);
            }
        }
    }

    /**
     * One live row per holder × profile × tenant: a row that is not revoked and
     * has not ended (current or starting later) blocks a new grant. Revoked and
     * ended rows stay as history. Call inside the write transaction: the rows
     * are locked, so two parallel grants cannot both pass.
     */
    public function noDuplicate(Model $holder, Profile $profile, Model $tenant, CarbonInterface $now): void
    {
        $live = Assignment::query()
            ->inTenant($tenant)
            ->forHolder($holder)
            ->where('profile_id', $profile->getKey())
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $now))
            ->lockForUpdate()
            ->pluck('id');

        if ($live->isNotEmpty()) {
            throw new GrantRejected(GrantRejected::DUPLICATE_ASSIGNMENT);
        }
    }
}
