<?php

namespace Seisigma\AccessProfiles\Guards;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;

class ProtectedProfiles
{
    /**
     * Rejects when $leaving is the last active row, in its tenant, of a
     * protected profile key (the template and the tenant copy count together).
     * Call inside the write transaction: every active row of that key in the
     * tenant is locked, so two parallel revokes cannot both pass.
     */
    public function check(Assignment $leaving, CarbonInterface $now): void
    {
        $profile = Profile::withTrashed()->whereKey($leaving->getAttribute('profile_id'))->first();

        if ($profile === null || ! in_array($profile->getAttribute('key'), config('access-profiles.guards.protected_profiles'), true)) {
            return;
        }

        $tenantType = $leaving->getAttribute('tenant_type');
        $tenantId = $leaving->getAttribute('tenant_id');
        $profiles = Profile::query()
            ->where('key', $profile->getAttribute('key'))
            ->where(fn (Builder $query) => $query->whereNull('tenant_type')
                ->orWhere(fn (Builder $query) => $query->where('tenant_type', $tenantType)->where('tenant_id', $tenantId)))
            ->select('id');

        $active = Assignment::query()
            ->where('tenant_type', $tenantType)
            ->where('tenant_id', $tenantId)
            ->whereIn('profile_id', $profiles)
            ->active($now)
            ->lockForUpdate()
            ->pluck('id');

        // An inactive row leaving takes nothing away.
        if (! $active->contains($leaving->getKey())) {
            return;
        }

        if ($active->count() < 2) {
            throw new GrantRejected(GrantRejected::LAST_HOLDER);
        }
    }
}
