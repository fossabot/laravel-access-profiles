<?php

namespace Seisigma\AccessProfiles\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Resolver\Decision;

class AccessChecked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Model $actor,
        public readonly Model $target,
        public readonly Decision $decision,
        public readonly bool $system = false,
        public readonly ?string $reason = null,
    ) {}
}
