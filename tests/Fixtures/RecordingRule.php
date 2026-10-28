<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\AssignmentRule;
use Seisigma\AccessProfiles\Models\Profile;

/**
 * A host rule that records every call and answers $allows.
 */
class RecordingRule implements AssignmentRule
{
    /** @var list<array{holder: Model, profile: string, tenant: Model, scopes: list<Model>}> */
    public static array $calls = [];

    public static bool $allows = true;

    public function name(): string
    {
        return 'board_seat';
    }

    public function allows(Model $holder, Profile $profile, Model $tenant, array $scopes): bool
    {
        self::$calls[] = ['holder' => $holder, 'profile' => $profile->key, 'tenant' => $tenant, 'scopes' => $scopes];

        return self::$allows;
    }
}
