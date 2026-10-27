<?php

namespace App\Modules\Core\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Busca por llave un id que llega del cliente, para todos los módulos (ADR-0014). Las llaves son
 * UUIDv7 y PostgreSQL rechaza con un error un valor que no es uuid, así que ese valor se trata como
 * un registro que no existe en lugar de llegar a la base.
 *
 *     FindByKey::orFail(Campus::query(), $id)
 */
final class FindByKey
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     */
    public static function orFail(Builder $query, string $id): Model
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel($query->getModel()::class, [$id]);
        }

        return $query->findOrFail($id);
    }
}
