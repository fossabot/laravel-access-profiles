<?php

namespace Seisigma\AccessProfiles\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Seisigma\AccessProfiles\AccessManager;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Guards\TenantConsistency;
use Seisigma\AccessProfiles\Models\Assignment;

class ShowCommand extends Command
{
    protected $signature = 'access-profiles:show {holder : Holder morph-type:primary-key} {tenant : Tenant morph-type:primary-key}';

    protected $description = 'Show active assignments and effective keys at the tenant and assigned scopes';

    public function handle(AccessManager $access, TenantConsistency $consistency): int
    {
        try {
            $holder = ModelReference::resolve((string) $this->argument('holder'));
            $tenant = ModelReference::resolve((string) $this->argument('tenant'));
            $consistency->tenant($tenant);
        } catch (InvalidArgumentException|GrantRejected $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $targets = [ModelReference::format($tenant) => $tenant];
        $rows = [];
        foreach (Assignment::query()->forHolder($holder)->inTenant($tenant)->active(now())->with(['profile', 'scopes.scope'])->get() as $assignment) {
            $scopes = [];
            foreach ($assignment->scopes as $scope) {
                $scopes[] = $scope->getAttribute('scope_type').':'.$scope->getAttribute('scope_id');
                if ($scope->scope !== null) {
                    $targets[ModelReference::format($scope->scope)] = $scope->scope;
                }
            }
            $rows[] = [
                $assignment->getKey(), $assignment->profile()->getResults()?->getAttribute('key') ?? '(deleted profile)', $assignment->getAttribute('title') ?? '',
                $assignment->getAttribute('covers_tenant') ? 'whole tenant' : implode(', ', $scopes),
                $assignment->valid_from?->toDateTimeString() ?? 'unbounded',
                $assignment->valid_until?->toDateTimeString() ?? 'unbounded',
            ];
        }
        $this->table(['Assignment', 'Profile', 'Title', 'Scopes', 'Valid from', 'Valid until'], $rows);
        foreach ($targets as $reference => $target) {
            $keys = $access->for($holder)->permissionsIn($target);
            $this->line('Effective keys '.$reference.': '.($keys === [] ? '(none)' : implode(', ', $keys)));
        }

        return self::SUCCESS;
    }
}
