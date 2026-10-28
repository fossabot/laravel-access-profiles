<?php

namespace Seisigma\AccessProfiles\Events;

use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;

final class Snapshot
{
    /** @return array<string, mixed> */
    public static function assignment(Assignment $assignment): array
    {
        return [...$assignment->attributesToArray(), 'scopes' => $assignment->scopes()->orderBy('id')->get(['scope_type', 'scope_id'])->toArray()];
    }

    /** @return array<string, mixed> */
    public static function profile(Profile $profile): array
    {
        return [...$profile->attributesToArray(), 'rules' => $profile->rules()->orderBy('permission')->toBase()->pluck('effect', 'permission')->all()];
    }
}
