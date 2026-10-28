<?php

namespace Seisigma\AccessProfiles\Resolver;

use Seisigma\AccessProfiles\Enums\Effect;

final class Decision
{
    public const ALLOWED = 'allowed';

    public const DENIED = 'denied';

    public const NO_MATCH = 'no_match';

    public const TOKEN = 'token';

    /**
     * @param  list<array{assignment: int, profile: string, effect: Effect}>  $matches  covering rules for the permission
     * @param  int|null  $deniedBy  the first assignment whose deny won
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $permission,
        public readonly string $reason,
        public readonly array $matches = [],
        public readonly ?int $deniedBy = null,
    ) {}
}
