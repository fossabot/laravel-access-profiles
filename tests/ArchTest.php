<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('it only depends on php and illuminate at runtime')
    ->expect('Seisigma\AccessProfiles')
    ->not->toUse(['Spatie', 'Silber', 'Laratrust', 'Laravel\Sanctum', 'Laravel\Jetstream']);
