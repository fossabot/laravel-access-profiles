<?php

namespace Seisigma\AccessProfiles\Builders;

use Illuminate\Database\Eloquent\Model;

trait RecordsActor
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
}
