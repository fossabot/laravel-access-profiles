<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Closure;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;

class Rejection
{
    /**
     * The reason code a builder rejected with, or null when it saved.
     */
    public static function of(Closure $save): ?string
    {
        try {
            $save();
        } catch (GrantRejected $rejected) {
            return $rejected->reason;
        }

        return null;
    }
}
