<?php

namespace Seisigma\AccessProfiles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Profile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('access-profiles.tables.profiles');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'version' => 'integer'];
    }

    /** @return MorphTo<Model, $this> */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Profile, $this> */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }

    /** @return HasMany<ProfileRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ProfileRule::class);
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
