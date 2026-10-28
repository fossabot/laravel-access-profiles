<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

/**
 * A holder shaped like a Sanctum user: currentAccessToken() + tokenCan().
 */
class TokenUser extends User
{
    protected $table = 'users';

    public ?object $accessToken = null;

    public function currentAccessToken(): ?object
    {
        return $this->accessToken;
    }

    public function tokenCan(string $ability): bool
    {
        return $this->accessToken !== null && $this->accessToken->can($ability);
    }
}
