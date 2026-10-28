<?php

namespace Seisigma\AccessProfiles\Resolver;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\ProfileConditions;
use Seisigma\AccessProfiles\Contracts\Resolver;
use Seisigma\AccessProfiles\Contracts\ScopeOwnership;
use Seisigma\AccessProfiles\Enums\Effect;
use Seisigma\AccessProfiles\Events\AccessChecked;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\AssignmentScope;
use Seisigma\AccessProfiles\Models\ProfileRule;
use Seisigma\AccessProfiles\Queries\ScopeSet;

/**
 * The decision rules of DESIGN §2.1. Grant sets come from the request memo,
 * then the versioned cache, then the database (at most three queries).
 */
class DatabaseResolver implements Resolver
{
    public function __construct(
        private readonly ScopeOwnership $ownership,
        private readonly ProfileConditions $conditions,
        private readonly RequestMemo $memo,
        private readonly CachedGrantSets $cache,
    ) {}

    public function decide(Model $holder, string $permission, Model $target): Decision
    {
        $tenant = $this->ownership->tenantOf($target);

        $decision = $this->evaluate($this->grants($holder, $tenant), $holder, $permission, $target, $tenant);

        if (config('access-profiles.events.access_checked', false)) {
            event(new AccessChecked($holder, $target, $decision));
        }

        return $decision;
    }

    public function permissionsIn(Model $holder, Model $target): array
    {
        $tenant = $this->ownership->tenantOf($target);
        $allow = [];
        $deny = [];

        foreach ($this->entriesFor($this->grants($holder, $tenant), $target, $tenant) as $entry) {
            $allow = [...$allow, ...$entry['allow']];
            $deny = [...$deny, ...$entry['deny']];
        }

        $keys = array_filter(
            array_unique(array_diff($allow, $deny)),
            fn (string $permission): bool => $this->tokenAllows($holder, $permission),
        );
        // sort() also re-indexes, so the result is a list.
        sort($keys);

        return $keys;
    }

    public function scopesWhere(Model $holder, string $permission, Model $target): ScopeSet
    {
        if (! $this->tokenAllows($holder, $permission)) {
            return ScopeSet::none();
        }

        $tenant = $this->ownership->tenantOf($target);

        return ScopeSet::fromEntries($this->conditionOn($this->grants($holder, $tenant)->active(now()), $tenant), $permission);
    }

    public function grants(Model $holder, Model $tenant): GrantSet
    {
        return $this->memo->remember($holder, $tenant, fn (): GrantSet => $this->cache->get(
            $holder, $tenant, fn (): GrantSet => $this->load($holder, $tenant),
        ));
    }

    public function evaluate(GrantSet $set, Model $holder, string $permission, Model $target, Model $tenant): Decision
    {
        $matches = [];
        $deniedBy = null;

        foreach ($this->entriesFor($set, $target, $tenant) as $entry) {
            // Deny is checked first: a profile that lists a key as deny never allows it.
            if (in_array($permission, $entry['deny'], true)) {
                $matches[] = ['assignment' => $entry['assignment'], 'profile' => $entry['profile'], 'effect' => Effect::Deny];
                $deniedBy ??= $entry['assignment'];
            } elseif (in_array($permission, $entry['allow'], true)) {
                $matches[] = ['assignment' => $entry['assignment'], 'profile' => $entry['profile'], 'effect' => Effect::Allow];
            }
        }

        if ($deniedBy !== null) {
            return new Decision(false, $permission, Decision::DENIED, $matches, $deniedBy);
        }

        if ($matches === []) {
            return new Decision(false, $permission, Decision::NO_MATCH);
        }

        if (! $this->tokenAllows($holder, $permission)) {
            return new Decision(false, $permission, Decision::TOKEN, $matches);
        }

        return new Decision(true, $permission, Decision::ALLOWED, $matches);
    }

    /**
     * Active entries that cover the target and whose condition is on.
     *
     * @return list<array{assignment: int, profile: string, covers_tenant: bool, scopes: list<string>, allow: list<string>, deny: list<string>, valid_from: int|null, valid_until: int|null, condition: string|null}>
     */
    private function entriesFor(GrantSet $set, Model $target, Model $tenant): array
    {
        return $this->conditionOn($set->covering($target, $target->is($tenant), now()), $tenant);
    }

    /**
     * The entries whose profile has no condition or a condition that is on.
     *
     * @param  list<array{assignment: int, profile: string, covers_tenant: bool, scopes: list<string>, allow: list<string>, deny: list<string>, valid_from: int|null, valid_until: int|null, condition: string|null}>  $entries
     * @return list<array{assignment: int, profile: string, covers_tenant: bool, scopes: list<string>, allow: list<string>, deny: list<string>, valid_from: int|null, valid_until: int|null, condition: string|null}>
     */
    private function conditionOn(array $entries, Model $tenant): array
    {
        $conditions = [];

        return array_values(array_filter(
            $entries,
            function (array $entry) use ($tenant, &$conditions): bool {
                $condition = $entry['condition'];

                return $condition === null
                    || ($conditions[$condition] ??= $this->conditions->isOn($condition, $tenant));
            },
        ));
    }

    /**
     * Tokens only narrow. A stored token (a model, like Sanctum's personal
     * access token) must also allow the key; a transient token or none is ignored.
     */
    private function tokenAllows(Model $holder, string $permission): bool
    {
        if (! config('access-profiles.token_intersection') || ! method_exists($holder, 'currentAccessToken')) {
            return true;
        }

        if (! $holder->currentAccessToken() instanceof Model) {
            return true;
        }

        return method_exists($holder, 'tokenCan') && $holder->tokenCan($permission) === true;
    }

    /**
     * Assignments that are not revoked and not yet expired (future ones are
     * kept: the window is checked per decision). Profiles must be live and be
     * a template or a copy of this same tenant.
     */
    private function load(Model $holder, Model $tenant): GrantSet
    {
        $now = now();
        $assignments = config('access-profiles.tables.assignments');
        $profiles = config('access-profiles.tables.profiles');

        $rows = Assignment::query()
            ->join($profiles, "{$profiles}.id", '=', "{$assignments}.profile_id")
            ->forHolder($holder)
            ->inTenant($tenant)
            ->whereNull("{$assignments}.revoked_at")
            ->where(fn (Builder $query) => $query->whereNull("{$assignments}.valid_until")->orWhere("{$assignments}.valid_until", '>', $now))
            ->whereNull("{$profiles}.deleted_at")
            ->where(fn (Builder $query) => $query->whereNull("{$profiles}.tenant_type")->orWhere(fn (Builder $query) => $query
                ->whereColumn("{$profiles}.tenant_type", "{$assignments}.tenant_type")
                ->whereColumn("{$profiles}.tenant_id", "{$assignments}.tenant_id")))
            ->orderBy("{$assignments}.id")
            ->get([
                "{$assignments}.id", "{$assignments}.profile_id", "{$assignments}.covers_tenant",
                "{$assignments}.valid_from", "{$assignments}.valid_until",
                "{$profiles}.key as profile_key", "{$profiles}.requires_condition",
            ]);

        if ($rows->isEmpty()) {
            return GrantSet::fromArray([]);
        }

        // getAttribute() instead of magic properties: same casts, and Larastan
        // does not need the configured table name to analyse it.
        $rules = [];
        $ruleRows = ProfileRule::query()
            ->whereIn('profile_id', $rows->map(fn (Assignment $row): mixed => $row->getAttribute('profile_id'))->unique()->all())
            ->toBase()
            ->get(['profile_id', 'permission', 'effect']);

        foreach ($ruleRows as $rule) {
            $rules[(string) $rule->profile_id][(string) $rule->effect][] = (string) $rule->permission;
        }

        $scopes = [];
        $limited = $rows->reject(fn (Assignment $row): bool => (bool) $row->getAttribute('covers_tenant'))->modelKeys();

        if ($limited !== []) {
            $scopeRows = AssignmentScope::query()
                ->whereIn('assignment_id', $limited)
                ->toBase()
                ->get(['assignment_id', 'scope_type', 'scope_id']);

            foreach ($scopeRows as $scope) {
                $scopes[(string) $scope->assignment_id][] = $scope->scope_type.'|'.$scope->scope_id;
            }
        }

        $entries = [];

        foreach ($rows as $row) {
            $profile = (string) $row->getAttribute('profile_id');
            $validFrom = $row->getAttribute('valid_from');
            $validUntil = $row->getAttribute('valid_until');
            $condition = $row->getAttribute('requires_condition');

            $entries[] = [
                'assignment' => (int) $row->getKey(),
                'profile' => (string) $row->getAttribute('profile_key'),
                'covers_tenant' => (bool) $row->getAttribute('covers_tenant'),
                'scopes' => $scopes[(string) $row->getKey()] ?? [],
                'allow' => $rules[$profile][Effect::Allow->value] ?? [],
                'deny' => $rules[$profile][Effect::Deny->value] ?? [],
                'valid_from' => $validFrom instanceof CarbonInterface ? $validFrom->getTimestamp() : null,
                'valid_until' => $validUntil instanceof CarbonInterface ? $validUntil->getTimestamp() : null,
                'condition' => is_string($condition) ? $condition : null,
            ];
        }

        return GrantSet::fromArray($entries);
    }
}
