<?php

namespace Seisigma\AccessProfiles\Backfill;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

final class AssignmentDraft
{
    /** @param list<Model> $scopes required when ! $coversTenant */
    public function __construct(
        public readonly Model $holder,
        public readonly string $profileKey,
        public readonly bool $coversTenant,
        public readonly array $scopes = [],
        public readonly ?string $title = null,
        public readonly ?CarbonInterface $validFrom = null,
        public readonly ?CarbonInterface $validUntil = null,
        public readonly string $sourceRef = '',
    ) {}
}
