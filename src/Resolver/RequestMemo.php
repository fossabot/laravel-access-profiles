<?php

namespace Seisigma\AccessProfiles\Resolver;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Grant sets already loaded in this request. Bound as a scoped instance, so
 * Laravel drops it at the start of every Octane request and queued job.
 */
class RequestMemo
{
    /** @var array<string, array<string, GrantSet>> tenant key => holder key => set */
    private array $sets = [];

    /** @param Closure(): GrantSet $load */
    public function remember(Model $holder, Model $tenant, Closure $load): GrantSet
    {
        return $this->sets[self::key($tenant)][self::key($holder)] ??= $load();
    }

    public function forgetTenant(Model $tenant): void
    {
        unset($this->sets[self::key($tenant)]);
    }

    private static function key(Model $model): string
    {
        return $model->getMorphClass().'|'.$model->getKey();
    }
}
