<?php

namespace Seisigma\AccessProfiles\Queries;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Seisigma\AccessProfiles\Contracts\ProfileConditions;
use Seisigma\AccessProfiles\Contracts\ScopeOwnership;
use Seisigma\AccessProfiles\Enums\Effect;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Models\ProfileRule;

/**
 * "Who holds permission P on target T", as one SQL query: the holder has a
 * covering allow row and no covering deny row. Both sides use the same
 * filters as the resolver (DESIGN §2.1): tenant, cover, validity window with
 * the app clock, revocation, live profile of this tenant, condition on.
 *
 * API tokens are not applied: a token belongs to a request, not to a row.
 */
final class HoldersQuery
{
    public function __construct(
        private readonly ScopeOwnership $ownership,
        private readonly ProfileConditions $conditions,
    ) {}

    /**
     * @param  class-string<Model>|null  $holderClass  null = config('auth.providers.users.model')
     * @return Builder<Model>
     */
    public function query(string $permission, Model $target, ?string $holderClass = null): Builder
    {
        $tenant = $this->ownership->tenantOf($target);
        $holderClass ??= config('auth.providers.users.model');
        /** @var Model $holder */
        $holder = new $holderClass;
        $on = $this->conditionsOn($permission, $tenant);
        $now = now();

        $rows = fn (Effect $effect) => fn (QueryBuilder $query) => $this->coveringRows(
            $query, $effect, $permission, $holder, $target, $tenant, $on, $now,
        );

        return $holder->newQuery()
            ->whereExists($rows(Effect::Allow))
            ->whereNotExists($rows(Effect::Deny));
    }

    /**
     * Assignment rows of one holder that cover the target with a rule of this
     * effect for the permission. The allow and the deny side share this method,
     * so a deny can never be filtered differently from an allow.
     *
     * @param  list<string>  $on  conditions that are on in the tenant
     */
    private function coveringRows(QueryBuilder $query, Effect $effect, string $permission, Model $holder, Model $target, Model $tenant, array $on, CarbonInterface $now): void
    {
        $assignments = config('access-profiles.tables.assignments');
        $profiles = config('access-profiles.tables.profiles');
        $rules = config('access-profiles.tables.profile_permissions');
        $scopes = config('access-profiles.tables.assignment_scopes');

        $query->selectRaw('1')
            ->from($assignments)
            ->join($profiles, "{$profiles}.id", '=', "{$assignments}.profile_id")
            ->join($rules, "{$rules}.profile_id", '=', "{$profiles}.id")
            ->where("{$rules}.permission", $permission)
            ->where("{$rules}.effect", $effect->value)
            ->where("{$assignments}.holder_type", $holder->getMorphClass())
            ->whereColumn("{$assignments}.holder_id", $holder->getQualifiedKeyName())
            ->where("{$assignments}.tenant_type", $tenant->getMorphClass())
            ->where("{$assignments}.tenant_id", $tenant->getKey())
            // Same window as Assignment::active(): start inclusive, end exclusive.
            ->whereNull("{$assignments}.revoked_at")
            ->where(fn (QueryBuilder $query) => $query->whereNull("{$assignments}.valid_from")->orWhere("{$assignments}.valid_from", '<=', $now))
            ->where(fn (QueryBuilder $query) => $query->whereNull("{$assignments}.valid_until")->orWhere("{$assignments}.valid_until", '>', $now))
            ->whereNull("{$profiles}.deleted_at")
            ->where(fn (QueryBuilder $query) => $query->whereNull("{$profiles}.tenant_type")->orWhere(fn (QueryBuilder $query) => $query
                ->whereColumn("{$profiles}.tenant_type", "{$assignments}.tenant_type")
                ->whereColumn("{$profiles}.tenant_id", "{$assignments}.tenant_id")))
            ->where(fn (QueryBuilder $query) => $query->whereNull("{$profiles}.requires_condition")->orWhereIn("{$profiles}.requires_condition", $on))
            ->where(function (QueryBuilder $query) use ($assignments, $scopes, $target, $tenant): void {
                $query->where("{$assignments}.covers_tenant", true);

                // A tenant-level target is covered only by covers_tenant rows.
                if (! $target->is($tenant)) {
                    $query->orWhereExists(fn (QueryBuilder $query) => $query->selectRaw('1')
                        ->from($scopes)
                        ->whereColumn("{$scopes}.assignment_id", "{$assignments}.id")
                        ->where("{$scopes}.scope_type", $target->getMorphClass())
                        ->where("{$scopes}.scope_id", $target->getKey()));
                }
            });
    }

    /**
     * Asks the host about each condition of a live profile, usable in this
     * tenant, with a rule for the permission. One query for the names.
     *
     * @return list<string>
     */
    private function conditionsOn(string $permission, Model $tenant): array
    {
        $names = Profile::query()
            ->whereNotNull('requires_condition')
            ->whereHas('rules', fn (Builder $query) => $query->where((new ProfileRule)->qualifyColumn('permission'), $permission))
            ->where(fn (Builder $query) => $query->whereNull('tenant_type')->orWhere(fn (Builder $query) => $query
                ->where('tenant_type', $tenant->getMorphClass())
                ->where('tenant_id', $tenant->getKey())))
            ->distinct()
            ->orderBy('requires_condition')
            ->pluck('requires_condition');

        return array_values(array_filter(
            $names->map(fn (mixed $name): string => (string) $name)->all(),
            fn (string $name): bool => $this->conditions->isOn($name, $tenant),
        ));
    }
}
