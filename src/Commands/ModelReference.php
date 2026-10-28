<?php

namespace Seisigma\AccessProfiles\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/** CLI references are morph-type:primary-key; the type may also be a FQCN. */
final class ModelReference
{
    public static function format(Model $model): string
    {
        return $model->getMorphClass().':'.$model->getKey();
    }

    public static function resolve(string $reference): Model
    {
        [$type, $id] = array_pad(explode(':', $reference, 2), 2, '');
        $class = Relation::getMorphedModel($type) ?? $type;

        if ($id === '' || ! is_a($class, Model::class, true)) {
            throw new InvalidArgumentException('Expected morph-type:primary-key, got '.$reference);
        }

        return $class::query()->find($id)
            ?? throw new InvalidArgumentException('Model not found: '.$reference);
    }
}
