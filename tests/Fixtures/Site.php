<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seisigma\AccessProfiles\Contracts\HasAccessTenant;

class Site extends Model implements HasAccessTenant
{
    protected $guarded = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function accessTenant(): Model
    {
        return $this->team;
    }
}
