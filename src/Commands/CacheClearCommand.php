<?php

namespace Seisigma\AccessProfiles\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Guards\TenantConsistency;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Support\TenantVersion;

class CacheClearCommand extends Command
{
    protected $signature = 'access-profiles:cache-clear {--tenant= : Tenant morph-type:primary-key; omit for all stored tenants}';

    protected $description = 'Invalidate access caches by bumping tenant versions';

    public function handle(TenantVersion $versions, TenantConsistency $consistency): int
    {
        if ($this->option('tenant') !== null) {
            try {
                $tenant = ModelReference::resolve((string) $this->option('tenant'));
                $consistency->tenant($tenant);
            } catch (InvalidArgumentException|GrantRejected $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $versions->bump($tenant);
            $this->info('Cleared '.ModelReference::format($tenant));

            return self::SUCCESS;
        }

        $references = Assignment::query()->select('tenant_type', 'tenant_id')->distinct()->get()
            ->concat(Profile::withTrashed()->whereNotNull('tenant_type')->select('tenant_type', 'tenant_id')->distinct()->get())
            ->unique(fn ($row) => $row->getAttribute('tenant_type').':'.$row->getAttribute('tenant_id'));
        $count = 0;

        foreach ($references as $row) {
            // A deleted host tenant has no cache consumers left to invalidate.
            $tenant = $row->tenant()->getResults();
            if ($tenant !== null) {
                $versions->bump($tenant);
                $count++;
            }
        }

        $this->info("Cleared {$count} tenants");

        return self::SUCCESS;
    }
}
