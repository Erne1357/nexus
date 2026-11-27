<?php

namespace App\Modules\Core\Queries;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Búsqueda de texto de los listados, para todos los módulos: "contiene", sin distinguir mayúsculas
 * (`ilike` en PostgreSQL) y con `%`, `_` y `\` tomados literalmente.
 *
 *     Campus::query()->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
 */
final class TextSearch
{
    /**
     * Deja las filas en que alguna de `$columns` contiene `$term`. Un término vacío o de puros
     * espacios no filtra.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $columns
     * @return TBuilder
     */
    public static function apply(Builder $query, array $columns, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $pattern = '%'.self::escape($term).'%';

        return $query->where(function (Builder $query) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $query->orWhereLike($column, $pattern);
            }
        });
    }

    /**
     * Escapa los comodines de LIKE con `\`, el carácter de escape por omisión de PostgreSQL.
     */
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
