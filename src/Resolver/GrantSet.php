<?php

namespace Seisigma\AccessProfiles\Resolver;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything one holder can get in one tenant: the assignments that are not
 * revoked and not yet expired, with their profile's rules. The validity window
 * and the condition are checked per decision, so a cached set stays correct
 * while the clock moves.
 *
 * @phpstan-type Entry array{assignment: int, profile: string, covers_tenant: bool, scopes: list<string>, allow: list<string>, deny: list<string>, valid_from: int|null, valid_until: int|null, condition: string|null}
 */
final class GrantSet
{
    /** @param list<Entry> $entries */
    private function __construct(private readonly array $entries) {}

    /** @param list<Entry> $entries */
    public static function fromArray(array $entries): self
    {
        return new self($entries);
    }

    /** @return list<Entry> plain arrays, safe for every cache driver */
    public function toArray(): array
    {
        return $this->entries;
    }

    public static function scopeKey(Model $scope): string
    {
        return $scope->getMorphClass().'|'.$scope->getKey();
    }

    /**
     * Entries active at $now that cover the target. A tenant-level target is
     * covered only by covers_tenant entries; a scope by covers_tenant entries
     * and by entries that list it.
     *
     * @return list<Entry>
     */
    public function covering(Model $target, bool $tenantLevel, CarbonInterface $now): array
    {
        $scope = self::scopeKey($target);

        return array_values(array_filter($this->active($now), fn (array $entry): bool => $entry['covers_tenant']
            || (! $tenantLevel && in_array($scope, $entry['scopes'], true))));
    }

    /**
     * Entries active at $now, whatever they cover.
     *
     * @return list<Entry>
     */
    public function active(CarbonInterface $now): array
    {
        $at = $now->getTimestamp();

        return array_values(array_filter($this->entries, fn (array $entry): bool => self::isActive($entry, $at)));
    }

    /**
     * The first validity boundary after $now (a start or an end), or null.
     */
    public function nextBoundary(CarbonInterface $now): ?int
    {
        $at = $now->getTimestamp();
        $next = null;

        foreach ($this->entries as $entry) {
            foreach ([$entry['valid_from'], $entry['valid_until']] as $boundary) {
                if ($boundary !== null && $boundary > $at && ($next === null || $boundary < $next)) {
                    $next = $boundary;
                }
            }
        }

        return $next;
    }

    /** @param Entry $entry */
    private static function isActive(array $entry, int $at): bool
    {
        // Same window as Assignment::active(): start inclusive, end exclusive.
        return ($entry['valid_from'] === null || $entry['valid_from'] <= $at)
            && ($entry['valid_until'] === null || $entry['valid_until'] > $at);
    }
}
