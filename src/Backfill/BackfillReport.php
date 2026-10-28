<?php

namespace Seisigma\AccessProfiles\Backfill;

final class BackfillReport
{
    public int $drafts = 0;

    public int $wouldCreate = 0;

    public int $alreadyPresent = 0;

    public int $created = 0;

    public bool $rolledBack = false;

    /** @var list<string> source reference and reason code */
    public array $rejected = [];
}
