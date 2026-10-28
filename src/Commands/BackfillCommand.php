<?php

namespace Seisigma\AccessProfiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Seisigma\AccessProfiles\AccessManager;
use Seisigma\AccessProfiles\Backfill\AssignmentDraft;
use Seisigma\AccessProfiles\Backfill\BackfillReport;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Catalogue\UnknownPermission;
use Seisigma\AccessProfiles\Contracts\BackfillSource;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Guards\HostRules;
use Seisigma\AccessProfiles\Guards\TenantConsistency;
use Seisigma\AccessProfiles\Models\Profile;

class BackfillCommand extends Command
{
    protected $signature = 'access-profiles:backfill {source} {--dry-run} {--tenant= : Tenant morph-type:primary-key}';

    protected $description = 'Backfill system grants from a registered host source, one transaction per tenant';

    public function handle(): int
    {
        try {
            $source = $this->source((string) $this->argument('source'));
            $filter = $this->option('tenant') === null ? null : ModelReference::resolve((string) $this->option('tenant'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $failed = false;
        if ($dryRun) {
            $this->info('Dry run');
        }

        foreach ($source->tenants() as $tenant) {
            if ($filter !== null && ! $filter->is($tenant)) {
                continue;
            }

            $report = new BackfillReport;
            if ($dryRun) {
                $this->process($source, $tenant, $report, true);
            } else {
                try {
                    DB::transaction(function () use ($source, $tenant, $report): void {
                        $this->process($source, $tenant, $report, false);
                        if ($report->rejected !== []) {
                            throw new GrantRejected('tenant_rollback');
                        }
                    });
                } catch (GrantRejected) {
                    $report->created = 0;
                    $report->rolledBack = true;
                }
            }

            $failed = $failed || $report->rejected !== [] || $report->rolledBack;
            $this->line(ModelReference::format($tenant)." drafts={$report->drafts} would-create={$report->wouldCreate} already-present={$report->alreadyPresent} rejected=".count($report->rejected)." created={$report->created}".($report->rolledBack ? ' rolled-back' : ''));
            foreach ($report->rejected as $rejection) {
                $this->error($rejection);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function source(string $name): BackfillSource
    {
        foreach (config('access-profiles.backfill.sources', []) as $class) {
            $source = app($class);
            if (! $source instanceof BackfillSource) {
                throw new InvalidArgumentException('Sources must implement '.BackfillSource::class);
            }
            if ($source->name() === $name) {
                return $source;
            }
        }

        throw new InvalidArgumentException('Unknown backfill source: '.$name);
    }

    private function process(BackfillSource $source, Model $tenant, BackfillReport $report, bool $dryRun): void
    {
        $pending = [];
        foreach ($source->drafts($tenant) as $draft) {
            $report->drafts++;
            try {
                $profile = $this->validate($draft, $tenant);
                $identity = ModelReference::format($draft->holder).':'.$profile->getKey();
                // Future live grants also block duplicates in the grant builder.
                app(TenantConsistency::class)->noDuplicate($draft->holder, $profile, $tenant, now());
                if (isset($pending[$identity])) {
                    throw new GrantRejected(GrantRejected::DUPLICATE_ASSIGNMENT);
                }
                $report->wouldCreate++;

                if (! $dryRun) {
                    $builder = app(AccessManager::class)->grant($profile)->to($draft->holder)->in($tenant)
                        ->validFrom($draft->validFrom)->validUntil($draft->validUntil)
                        ->bySystem('backfill:'.$source->name().' '.$draft->sourceRef);
                    if (! $draft->coversTenant) {
                        $builder->onlyScopes($draft->scopes);
                    }
                    if ($draft->title !== null) {
                        $builder->titled($draft->title);
                    }
                    $builder->save();
                    $report->created++;
                }

                if ($draft->validUntil === null || $draft->validUntil->greaterThan(now())) {
                    $pending[$identity] = true;
                }
            } catch (GrantRejected $exception) {
                if ($exception->reason === GrantRejected::DUPLICATE_ASSIGNMENT) {
                    $report->alreadyPresent++;
                } else {
                    $report->rejected[] = $draft->sourceRef.': '.$exception->reason;
                }
            } catch (UnknownPermission $exception) {
                $report->rejected[] = $draft->sourceRef.': unknown_permission ('.$exception->getMessage().')';
            }
        }
    }

    /** Read-only preflight; reuse the write guards without saving a draft. */
    private function validate(AssignmentDraft $draft, Model $tenant): Profile
    {
        $consistency = app(TenantConsistency::class);
        $consistency->tenant($tenant);
        $profile = Profile::query()->where('key', $draft->profileKey)
            ->where(fn (Builder $query) => $query->whereNull('tenant_type')
                ->orWhere(fn (Builder $query) => $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', $tenant->getKey())))
            ->orderByRaw('tenant_type is null')->first()
            ?? throw new GrantRejected(GrantRejected::UNKNOWN_PROFILE);
        $consistency->profile($profile, $tenant);
        if ($draft->validFrom !== null && $draft->validUntil !== null && $draft->validUntil->lessThanOrEqualTo($draft->validFrom)) {
            throw new GrantRejected(GrantRejected::INVALID_WINDOW);
        }
        if (! $draft->coversTenant && $draft->scopes === []) {
            throw new GrantRejected(GrantRejected::NO_SCOPES);
        }
        $scopes = $draft->coversTenant ? [] : $draft->scopes;
        $consistency->scopes($scopes, $tenant);
        foreach ($profile->rules as $rule) {
            if (! app(Catalogue::class)->has($rule->getAttribute('permission'))) {
                throw new UnknownPermission('Unknown permission: '.$rule->getAttribute('permission'));
            }
        }
        app(HostRules::class)->check($draft->holder, $profile, $tenant, $scopes);

        return $profile;
    }
}
