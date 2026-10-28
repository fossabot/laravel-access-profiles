<?php

namespace Seisigma\AccessProfiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Catalogue\Catalogue;

class CensusCommand extends Command
{
    protected $signature = 'access-profiles:census';

    protected $description = 'Check stored permission keys and report unused catalogue keys';

    public function handle(Catalogue $catalogue): int
    {
        $stored = DB::table(config('access-profiles.tables.profile_permissions'))->distinct()->pluck('permission')->all();
        $unknown = array_diff($stored, $catalogue->all());

        foreach ($unknown as $key) {
            $this->error("Unknown permission: {$key}");
        }

        foreach (array_diff($catalogue->all(), $stored) as $key) {
            $this->warn("Unused permission: {$key}");
        }

        return $unknown === [] ? self::SUCCESS : self::FAILURE;
    }
}
