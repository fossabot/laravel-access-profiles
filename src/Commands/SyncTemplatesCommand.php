<?php

namespace Seisigma\AccessProfiles\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Seisigma\AccessProfiles\Templates\TemplateSync;

class SyncTemplatesCommand extends Command
{
    protected $signature = 'access-profiles:sync-templates {--dry-run}';

    protected $description = 'Sync system templates from code and report changes';

    public function handle(TemplateSync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $changes = $sync->sync($dryRun);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run');
        }

        foreach ($changes as $change) {
            $this->line($change);
        }

        return self::SUCCESS;
    }
}
