<?php

namespace Seisigma\AccessProfiles\Contracts;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Backfill\AssignmentDraft;

interface BackfillSource
{
    /** Name used on the command line: `access-profiles:backfill <name>`. */
    public function name(): string;

    /** @return iterable<Model> every tenant to migrate */
    public function tenants(): iterable;

    /** @return iterable<AssignmentDraft> */
    public function drafts(Model $tenant): iterable;
}
