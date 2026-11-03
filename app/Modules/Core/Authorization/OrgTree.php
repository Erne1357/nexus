<?php

namespace App\Modules\Core\Authorization;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Consulta del organigrama: si una unidad cubre a otra (ella misma o un descendiente).
 *
 * Se resuelve desde un mapa `unidad => [ella y todos sus descendientes]` guardado en caché.
 * La llave lleva una huella de la tabla (`count(*)` y `max(updated_at)` sobre todas las filas,
 * borradas incluidas), así que no hay invalidación manual: cualquier alta, movimiento, cambio de
 * nombre o borrado cambia la huella y la siguiente petición arma un mapa nuevo; los triggers de
 * departamentos también tocan `updated_at`. El mapa incluye unidades borradas porque la
 * jerarquía es estructural; solo `idOf()` las ignora.
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
     * Id de la unidad sin borrar con esa clave.
     */
    public function idOf(string $code): ?string
    {
        $id = DB::table('org_units')->where('code', $code)->whereNull('deleted_at')->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function map(): array
    {
        return $this->map ??= $this->cache->rememberForever(
            'authz:org-tree:'.$this->fingerprint(),
            fn (): array => $this->build(),
        );
    }

    private function fingerprint(): string
    {
        $row = DB::table('org_units')->selectRaw('count(*) as total, max(updated_at) as latest')->first();

        return $row->total.':'.$row->latest;
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
            $pending = [$id];

            while ($pending !== []) {
                $current = array_pop($pending);
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
