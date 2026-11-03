<?php

namespace App\Modules\Core\Authorization;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Consulta del organigrama: si una unidad cubre a otra (ella misma o un descendiente).
 *
 * Se resuelve desde un mapa `unidad => [ella y todos sus descendientes]` guardado en caché.
 * La llave lleva una huella de los enlaces que definen el mapa: el md5 de los pares
 * `id>parent_id` de todas las filas, borradas incluidas. No hay invalidación manual: cualquier
 * alta, baja física o movimiento cambia los pares, y la siguiente petición arma un mapa nuevo.
 * No depende de la precisión de `updated_at` (dos movimientos en el mismo segundo se ven) y la
 * tabla tiene decenas de filas, así que recorrerla en cada petición es trivial. El mapa incluye
 * unidades borradas porque la jerarquía es estructural; solo `idOf()` las ignora. El mapa vence al
 * día para que las formas viejas del árbol no se acumulen; el armado salta unidades ya vistas, así
 * que un ciclo que se colara (trigger apagado) no cuelga la petición.
 *
 * Se registra como `scoped`: la huella y el mapa se memorizan en la instancia para que una
 * petición consulte la base una sola vez, y Octane no los comparte con la siguiente.
 */
final class OrgTree
{
    public const string ROOT = 'DIRECCION';

    public const string ACADEMIC = 'SUB-ACADEMICA';

    /** @var array<string, list<string>>|null */
    private ?array $map = null;

    public function __construct(private readonly Repository $cache) {}

    /**
     * La unidad y todos sus descendientes; vacío si la unidad no existe.
     *
     * @return list<string>
     */
    public function descendantsOrSelf(string $unitId): array
    {
        return $this->map()[$unitId] ?? [];
    }

    public function covers(string $ancestorId, string $unitId): bool
    {
        return in_array($unitId, $this->descendantsOrSelf($ancestorId), true);
    }

    /**
     * Id de la unidad estructural (no departamento académico) sin borrar con esa clave: un
     * departamento puede repetir la clave de una unidad estructural y no cuenta aquí.
     */
    public function idOf(string $code): ?string
    {
        $id = DB::table('org_units')->where('code', $code)->where('kind', '<>', 'departamento_academico')->whereNull('deleted_at')->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function map(): array
    {
        return $this->map ??= $this->cache->remember(
            'authz:org-tree:'.$this->fingerprint(),
            now()->addDay(),
            fn (): array => $this->build(),
        );
    }

    private function fingerprint(): string
    {
        $row = DB::selectOne(
            "select md5(coalesce(string_agg(id::text || '>' || coalesce(parent_id::text, ''), ',' order by id), '')) as hash from org_units",
        );

        return $row->hash;
    }

    /**
     * @return array<string, list<string>>
     */
    private function build(): array
    {
        /** @var array<string, list<string>> $children */
        $children = [];
        $ids = [];

        foreach (DB::table('org_units')->select('id', 'parent_id')->get() as $row) {
            $ids[] = $row->id;

            if ($row->parent_id !== null) {
                $children[$row->parent_id][] = $row->id;
            }
        }

        $map = [];

        foreach ($ids as $id) {
            $found = [];
            $seen = [];
            $pending = [$id];

            while ($pending !== []) {
                $current = array_pop($pending);

                if (isset($seen[$current])) {
                    continue;
                }

                $seen[$current] = true;
                $found[] = $current;

                foreach ($children[$current] ?? [] as $child) {
                    $pending[] = $child;
                }
            }

            $map[$id] = $found;
        }

        return $map;
    }
}
