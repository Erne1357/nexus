<?php

namespace App\Modules\Core\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Búsqueda por llave de un id que llega de fuera (el navegador o una sesión), para todos los
 * módulos (ADR-0014). Las llaves son UUIDv7 y PostgreSQL rechaza con un error un valor que no es
 * uuid, así que ese valor se trata como un registro que no existe en lugar de llegar a la base.
 *
 *     KeyLookup::findOrFail(Campus::query(), $id)
 */
final class KeyLookup
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    public static function find(Builder $query, mixed $id): ?Model
    {
        return Str::isUuid($id) ? $query->find($id) : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     */
    public static function findOrFail(Builder $query, string $id): Model
    {
        return self::find($query, $id) ?? throw (new ModelNotFoundException)->setModel($query->getModel()::class, [$id]);
    }
}
