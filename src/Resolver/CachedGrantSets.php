<?php

namespace Seisigma\AccessProfiles\Resolver;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Seisigma\AccessProfiles\Support\TenantVersion;

/**
 * Cross-request cache of grant sets, keyed by the tenant version (DESIGN §2.2).
 * A write bumps the version, so an old entry is never read again.
 */
class CachedGrantSets
{
    public function __construct(private readonly TenantVersion $versions) {}

    /** @param Closure(): GrantSet $load */
    public function get(Model $holder, Model $tenant, Closure $load): GrantSet
    {
        if (! config('access-profiles.cache.enabled')) {
            return $load();
        }

        // Read the version before the database: a write that lands during the
        // load bumps the version, so what we store under the old key is never read.
        $key = config('access-profiles.cache.prefix')
            .':'.$tenant->getMorphClass().':'.$tenant->getKey()
            .':'.$holder->getMorphClass().':'.$holder->getKey()
            .':v'.$this->versions->current($tenant);
        $store = Cache::store(config('access-profiles.cache.store'));
        $cached = $store->get($key);

        if (is_array($cached)) {
            /** @var list<array{assignment: int, profile: string, covers_tenant: bool, scopes: list<string>, allow: list<string>, deny: list<string>, valid_from: int|null, valid_until: int|null, condition: string|null}> $cached only this class writes the entry */
            return GrantSet::fromArray($cached);
        }

        $set = $load();
        $now = now();
        $ttl = (int) config('access-profiles.cache.ttl');
        $boundary = $set->nextBoundary($now);

        if ($boundary !== null) {
            $ttl = min($ttl, $boundary - $now->getTimestamp());
        }

        $store->put($key, $set->toArray(), $ttl);

        return $set;
    }
}
