<?php

namespace Seisigma\AccessProfiles\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Assignment extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('access-profiles.tables.assignments');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'profile_version' => 'integer',
            'covers_tenant' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query, CarbonInterface $at): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $at))
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $at));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForHolder(Builder $query, Model $holder): Builder
    {
        return $query->whereMorphedTo('holder', $holder);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInTenant(Builder $query, Model $tenant): Builder
    {
        return $query->whereMorphedTo('tenant', $tenant);
    }

    /** @return MorphTo<Model, $this> */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function holder(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function grantedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function revokedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @return HasMany<AssignmentScope, $this> */
    public function scopes(): HasMany
    {
        return $this->hasMany(AssignmentScope::class);
    }
}
