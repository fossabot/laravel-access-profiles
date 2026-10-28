<?php

namespace Seisigma\AccessProfiles\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

abstract class WriteEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    public function __construct(
        public readonly ?Model $actor,
        public readonly bool $system,
        public readonly ?string $reason,
        public readonly ?array $before,
        public readonly ?array $after,
    ) {}
}
