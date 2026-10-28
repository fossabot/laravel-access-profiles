<?php

namespace Seisigma\AccessProfiles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seisigma\AccessProfiles\Enums\Effect;

class ProfileRule extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('access-profiles.tables.profile_permissions');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effect' => Effect::class];
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
