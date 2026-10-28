<?php

namespace Seisigma\AccessProfiles\Builders;

use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Events\AssignmentRevoked;
use Seisigma\AccessProfiles\Events\Snapshot;
use Seisigma\AccessProfiles\Guards\ProtectedProfiles;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Support\TenantVersion;

/**
 * `Access::revoke($assignment)->by($admin)->because('Term ended')->save()`.
 * The row stays as history; only revoked_at, revoked_by and the reason are set.
 */
class RevokeBuilder extends AssignmentBuilder
{
    public function __construct(private readonly Assignment $assignment) {}

    public function save(): Assignment
    {
        return DB::transaction(function (): Assignment {
            $row = self::lockLive($this->assignment);
            $before = Snapshot::assignment($row);
            $tenant = self::tenantOf($row);
            $profile = Profile::withTrashed()->whereKey($row->getAttribute('profile_id'))->firstOrFail();
            // Revoking only takes reach away, so it needs no escalation check.
            // A holder record that is gone cannot be the grantor.
            $this->guardActor($row->holder()->getResults(), $profile, $tenant, [], escalation: false);
            $now = now();
            app(ProtectedProfiles::class)->check($row, $now);

            $row->forceFill([
                'revoked_at' => $now,
                'revoked_by_type' => $this->grantor?->getMorphClass(),
                'revoked_by_id' => $this->grantor?->getKey(),
                'revoke_reason' => $this->reason,
            ])->save();
            DB::afterCommit(fn () => app(TenantVersion::class)->bump($tenant));
            event(new AssignmentRevoked($this->grantor, $this->system, $this->reason, $before, Snapshot::assignment($row->refresh())));

            return $row;
        });
    }
}
