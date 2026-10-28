<?php

namespace Seisigma\AccessProfiles\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Seisigma\AccessProfiles\Facades\Access;
use Seisigma\AccessProfiles\Models\Assignment;

trait HasAccessProfiles
{
    public function canAccess(string $key, Model $target): bool
    {
        return Access::for($this)->can($key, $target);
    }

    /** @return MorphMany<Assignment, $this> */
    public function accessAssignments(): MorphMany
    {
        return $this->morphMany(Assignment::class, 'holder');
    }
}
