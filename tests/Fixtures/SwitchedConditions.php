<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Contracts\ProfileConditions;

/**
 * Conditions that are on when they are in the list. Records every question.
 */
class SwitchedConditions implements ProfileConditions
{
    /** @var list<string> */
    public array $asked = [];

    /** @param list<string> $on */
    public function __construct(public array $on = []) {}

    /** @param list<string> $on */
    public static function bind(array $on): self
    {
        $conditions = new self($on);
        app()->instance(ProfileConditions::class, $conditions);

        return $conditions;
    }

    public function isOn(string $condition, Model $tenant): bool
    {
        $this->asked[] = $condition;

        return in_array($condition, $this->on, true);
    }
}
