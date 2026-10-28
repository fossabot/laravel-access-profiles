<?php

namespace Seisigma\AccessProfiles\Queries;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seisigma\AccessProfiles\Contracts\ScopeOwnership;
use Seisigma\AccessProfiles\Models\Assignment;

/**
 * Every assignment row of one holder in one tenant: active, future, expired
 * and revoked. Passive expiry fires no event, so this is where it shows.
 */
final class History
{
    public function __construct(private readonly ScopeOwnership $ownership) {}

    /** @return Collection<int, Assignment> oldest grant first */
    public function of(Model $holder, Model $target): Collection
    {
        return Assignment::query()
            ->forHolder($holder)
            ->inTenant($this->ownership->tenantOf($target))
            ->with([
                'profile' => fn (BelongsTo $query) => $query->withTrashed(),
                'scopes',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
