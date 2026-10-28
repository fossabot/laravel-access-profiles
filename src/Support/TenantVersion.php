<?php

namespace Seisigma\AccessProfiles\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class TenantVersion
{
    public function current(Model $tenant): int
    {
        $store = Cache::store(config('access-profiles.cache.store'));
        $key = $this->key($tenant);
        $version = $store->get($key);

        if ($version === null) {
            $store->add($key, 1);
            $version = $store->get($key);
        }

        return (int) $version;
    }

    public function bump(Model $tenant): int
    {
        $store = Cache::store(config('access-profiles.cache.store'));
        $key = $this->key($tenant);
        // add is atomic and never replaces a counter another writer initialized.
        $store->add($key, 1);

        return (int) $store->increment($key);
    }

    private function key(Model $tenant): string
    {
        return config('access-profiles.cache.prefix').':version:'.$tenant->getMorphClass().':'.$tenant->getKey();
    }
}
