<?php

namespace Seisigma\AccessProfiles\Guards;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Access\HolderAccess;
use Seisigma\AccessProfiles\Enums\Effect;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Models\ProfileRule;

class NoEscalation
{
    /**
     * The grantor must hold every allow key of the profile in every target:
     * each listed scope, or the tenant itself for a covers-tenant assignment.
     * The resolver answers, so a deny of the grantor counts as lacking the key.
     *
     * @param  list<Model>  $scopes  empty = the whole tenant
     */
    public function check(Model $grantor, Profile $profile, Model $tenant, array $scopes): void
    {
        if (! config('access-profiles.guards.no_escalation')) {
            return;
        }

        $keys = ProfileRule::query()
            ->where('profile_id', $profile->getKey())
            ->where('effect', Effect::Allow->value)
            ->pluck('permission');
        $access = HolderAccess::for($grantor);

        foreach ($scopes === [] ? [$tenant] : $scopes as $target) {
            foreach ($keys as $key) {
                if (! $access->can($key, $target)) {
                    throw new GrantRejected(GrantRejected::ESCALATION);
                }
            }
        }
    }
}
