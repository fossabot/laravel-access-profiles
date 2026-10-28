<?php

namespace Seisigma\AccessProfiles\Contracts;

use Seisigma\AccessProfiles\Templates\TemplateDefinition;

interface TemplateDefinitions
{
    /** @return iterable<TemplateDefinition> */
    public static function templates(): iterable;
}
