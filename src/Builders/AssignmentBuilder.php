<?php

namespace Seisigma\AccessProfiles\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Guards\NoEscalation;
use Seisigma\AccessProfiles\Guards\NoSelfAssignment;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\AssignmentScope;
use Seisigma\AccessProfiles\Models\Profile;

/**
 * Who acts on an assignment, and the guards that depend on it.
 */
abstract class AssignmentBuilder
{
    protected ?Model $grantor = null;

    protected bool $system = false;

    protected ?string $reason = null;

    public function by(Model $grantor): static
    {
        $this->grantor = $grantor;
        $this->system = false;

        return $this;
    }

    /**
     * A write the package host makes on its own (seed, backfill, cleanup).
     * It skips the escalation and self checks; every other guard still runs.
     */
    public function bySystem(string $reason): static
    {
        $this->grantor = null;
        $this->system = true;
        $this->reason = $reason;

        return $this;
    }

    public function because(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    /**
     * @param  list<Model>  $scopes  empty = the whole tenant
     */
    protected function guardActor(?Model $holder, Profile $profile, Model $tenant, array $scopes, bool $escalation): void
    {
        if ($this->system) {
            return;
        }

        if ($this->grantor === null) {
            if (config('access-profiles.guards.no_escalation') || config('access-profiles.guards.no_self_assignment')) {
                throw new GrantRejected(GrantRejected::MISSING_GRANTOR);
            }

            return;
        }

        if ($escalation) {
            app(NoEscalation::class)->check($this->grantor, $profile, $tenant, $scopes);
        }

        app(NoSelfAssignment::class)->check($this->grantor, $holder);
    }

    protected static function checkWindow(?CarbonInterface $from, ?CarbonInterface $until): void
    {
        if ($from !== null && $until !== null && $until->lessThanOrEqualTo($from)) {
            throw new GrantRejected(GrantRejected::INVALID_WINDOW);
        }
    }

    /**
     * Re-reads the row under a lock, so the state it acts on is the stored one.
     */
    protected static function lockLive(Assignment $assignment): Assignment
    {
        $row = Assignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

        if ($row->getAttribute('revoked_at') !== null) {
            throw new GrantRejected(GrantRejected::ALREADY_REVOKED);
        }

        return $row;
    }

    protected static function tenantOf(Assignment $row): Model
    {
        return $row->tenant()->getResults() ?? throw new GrantRejected(GrantRejected::MISSING_TENANT);
    }

    /** @param list<Model> $scopes */
    protected static function writeScopes(Assignment $row, array $scopes): void
    {
        AssignmentScope::query()->where('assignment_id', $row->getKey())->delete();

        foreach ($scopes as $scope) {
            AssignmentScope::create([
                'assignment_id' => $row->getKey(),
                'scope_type' => $scope->getMorphClass(),
                'scope_id' => $scope->getKey(),
            ]);
        }
    }
}
