<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\OrgUnitKind;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Consulta del organigrama: si una unidad cubre a otra (ella misma o un descendiente) y si su subárbol tiene una unidad
 * a la que aplica un permiso (fits(), D20).
 *
 * Se resuelve desde un mapa `unidad => [ella y todos sus descendientes]`, con el tipo y la clave de cada unidad,
 * guardado en caché. La llave lleva una huella de lo que define el mapa: el md5 de `id>parent_id:kind:code` de todas
 * las filas, borradas incluidas. No hay invalidación manual: cualquier alta, baja física, movimiento o cambio de tipo
 * o de clave cambia la huella, y la siguiente petición arma un mapa nuevo. No depende de la precisión de `updated_at`
 * (dos movimientos en el mismo segundo se ven) y la tabla tiene decenas de filas, así que recorrerla en cada petición
 * es trivial. El mapa incluye unidades borradas porque la jerarquía es estructural; solo `idOf()` las ignora. El mapa
 * vence al día para que las formas viejas del árbol no se acumulen; el armado salta unidades ya vistas, así que un
 * ciclo que se colara (trigger apagado) no cuelga la petición.
 *
 * Se registra como `scoped`: la huella, el mapa, lo que respondió fits() y los ids de idOf() se memorizan en la
 * instancia para que una petición consulte la base una sola vez (idOf(), una por clave), y Octane no los comparte con
 * la siguiente.
 */
final class OrgTree
{
    public const string ROOT = 'DIRECCION';

    public const string ACADEMIC = 'SUB-ACADEMICA';

    /**
     * El Departamento de Servicios Escolares: su jefe valida las materias de la migración del SII que esperan
     * semestre (D19), con el puesto de jefe de departamento aunque no sea un departamento académico.
     */
    public const string SCHOOL_SERVICES = 'SERVICIOS-ESCOLARES';

    /** @var array{descendants: array<string, list<string>>, units: array<string, array{kind: string, code: string}>}|null */
    private ?array $map = null;

    /** @var array<string, bool> fits() por `unidad|UnitApplicability::key()` */
    private array $fits = [];

    /** @var array<string, string|null> idOf() por clave */
    private array $ids = [];

    public function __construct(private readonly Repository $cache) {}

    /**
     * La unidad y todos sus descendientes; vacío si la unidad no existe.
     *
     * @return list<string>
     */
    public function descendantsOrSelf(string $unitId): array
    {
        return $this->map()['descendants'][$unitId] ?? [];
    }

    public function covers(string $ancestorId, string $unitId): bool
    {
        return in_array($unitId, $this->descendantsOrSelf($ancestorId), true);
    }

    /**
     * D20: si la unidad o alguna de sus descendientes es de las que declara `$applicability` (UnitApplicability::matches():
     * por tipo, o por clave si no es un departamento académico). Nunca por un ancestro: Servicios Escolares, debajo de
     * la Subdirección Académica, no cumple lo académico; la Subdirección sí. Las unidades borradas cuentan, como en el
     * resto del mapa. Una unidad que no existe no cumple.
     */
    public function fits(string $unitId, UnitApplicability $applicability): bool
    {
        $key = $unitId.'|'.$applicability->key();

        if (! array_key_exists($key, $this->fits)) {
            $units = $this->map()['units'];

            $this->fits[$key] = array_any(
                $this->descendantsOrSelf($unitId),
                fn (string $id): bool => isset($units[$id]) && $applicability->matches(OrgUnitKind::from($units[$id]['kind']), $units[$id]['code']),
            );
        }

        return $this->fits[$key];
    }

    /**
     * Id de la unidad estructural (no departamento académico) sin borrar con esa clave: un
     * departamento puede repetir la clave de una unidad estructural y no cuenta aquí. Se consulta una vez por clave en
     * cada petición.
     */
    public function idOf(string $code): ?string
    {
        if (! array_key_exists($code, $this->ids)) {
            $id = DB::table('org_units')->where('code', $code)->where('kind', '<>', 'departamento_academico')->whereNull('deleted_at')->value('id');
            $this->ids[$code] = is_string($id) ? $id : null;
        }

        return $this->ids[$code];
    }

    /**
     * @return array{descendants: array<string, list<string>>, units: array<string, array{kind: string, code: string}>}
     */
    private function map(): array
    {
        return $this->map ??= $this->cache->remember(
            'authz:org-tree:v2:'.$this->fingerprint(),
            now()->addDay(),
            fn (): array => $this->build(),
        );
    }

    private function fingerprint(): string
    {
        $row = DB::selectOne(
            "select md5(coalesce(string_agg(id::text || '>' || coalesce(parent_id::text, '') || ':' || kind || ':' || code, ',' order by id), '')) as hash from org_units",
        );

        return $row->hash;
    }

    /**
     * @return array{descendants: array<string, list<string>>, units: array<string, array{kind: string, code: string}>}
     */
    private function build(): array
    {
        /** @var array<string, list<string>> $children */
        $children = [];
        $ids = [];
        $units = [];

        foreach (DB::table('org_units')->select('id', 'parent_id', 'kind', 'code')->get() as $row) {
            $ids[] = $row->id;
            $units[$row->id] = ['kind' => $row->kind, 'code' => $row->code];

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

        return ['descendants' => $map, 'units' => $units];
    }
}
