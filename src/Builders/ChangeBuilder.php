<?php

namespace Seisigma\AccessProfiles\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Guards\HostRules;
use Seisigma\AccessProfiles\Guards\ProtectedProfiles;
use Seisigma\AccessProfiles\Guards\TenantConsistency;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\AssignmentScope;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Support\TenantVersion;

/**
 * `Access::change($assignment)->validUntil(...)->onlyScopes([...])->titled('...')->by($admin)->save()`.
 * Only what is called changes. The guards check the reach the row has after the change.
 */
class ChangeBuilder extends AssignmentBuilder
{
    /** @var list<Model>|null null = keep the current scopes */
    private ?array $scopes = null;

    private ?string $title = null;

    private bool $untilGiven = false;

    private ?CarbonInterface $until = null;

    public function __construct(private readonly Assignment $assignment) {}

    /** @param list<Model> $scopes */
    public function onlyScopes(array $scopes): static
    {
        $this->scopes = $scopes;

        return $this;
    }

    public function titled(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /** null clears the end date. */
    public function validUntil(?CarbonInterface $until): static
    {
        $this->untilGiven = true;
        $this->until = $until;

        return $this;
    }

    /** The reason given with because(); a change row has no column for it. */
    public function reason(): ?string
    {
        return $this->reason;
    }

    public function save(): Assignment
    {
        return DB::transaction(function (): Assignment {
            $row = self::lockLive($this->assignment);
            $tenant = self::tenantOf($row);
            $holder = $row->holder()->getResults() ?? throw new GrantRejected(GrantRejected::MISSING_HOLDER);
            $profile = Profile::withTrashed()->whereKey($row->getAttribute('profile_id'))->firstOrFail();
            $until = $this->untilGiven ? $this->until : $row->getAttribute('valid_until');
            self::checkWindow($row->getAttribute('valid_from'), $until);

            if ($this->scopes === []) {
                throw new GrantRejected(GrantRejected::NO_SCOPES);
            }

            $scopes = $this->scopes ?? $this->currentScopes($row);
            app(TenantConsistency::class)->scopes($scopes, $tenant);
            $this->guardActor($holder, $profile, $tenant, $scopes, escalation: true);
            app(HostRules::class)->check($holder, $profile, $tenant, $scopes);

            // Strict: any end date on the last active holder of a protected profile is a lock-out to come.
            if ($until !== null && $this->untilGiven) {
                app(ProtectedProfiles::class)->check($row, now());
            }

            $row->setAttribute('valid_until', $until);
            $row->setAttribute('title', $this->title ?? $row->getAttribute('title'));

            if ($this->scopes !== null) {
                $row->setAttribute('covers_tenant', false);
                self::writeScopes($row, $scopes);
            }

            $row->save();

            DB::afterCommit(fn () => app(TenantVersion::class)->bump($tenant));

            return $row;
        });
    }

    /**
     * The scope records the row lists now; empty for a covers-tenant row.
     * A scope record that was deleted gives no reach, so it is left out.
     *
     * @return list<Model>
     */
    private function currentScopes(Assignment $row): array
    {
        $scopes = [];

        foreach (AssignmentScope::query()->where('assignment_id', $row->getKey())->orderBy('id')->get() as $link) {
            $scope = $link->scope()->getResults();

            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }
}
