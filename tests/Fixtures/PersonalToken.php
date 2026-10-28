<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored API token (a model, like Sanctum's PersonalAccessToken).
 */
class PersonalToken extends Model
{
    /** @param list<string> $abilities */
    public static function allowing(array $abilities): self
    {
        $token = new self;
        $token->setAttribute('abilities', $abilities);

        return $token;
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->getAttribute('abilities'), true);
    }
}
