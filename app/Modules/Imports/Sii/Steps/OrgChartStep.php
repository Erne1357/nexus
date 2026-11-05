<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiCuratedMismatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;

/**
 * Organigrama y departamentos (D14, reglas-de-negocio.md "Reglas de datos"). El organigrama del SII no se importa
 * tal cual: `Curated/organigrama.csv` decide qué hace cada área (`departamento`, `unidad` u `omitir`) y a qué
 * departamento pertenece (`departamento_de`).
 *
 * Contrato: cada `clave_area` del SII tiene exactamente una fila curada y cada fila curada existe en el SII; si no,
 * el paso se detiene antes de escribir (SiiCuratedMismatch). Entrega dos lotes, en este orden: `departments` (los
 * departamentos nacen bajo la Subdirección Académica por el trigger de la base; este paso no les da padre) y
 * `org_units` (las coordinaciones vacías, inactivas, bajo su departamento).
 *
 * Recuerda `department_for_area`: `clave_area` del SII → código del departamento de Nexus (o null), que usan los
 * pasos de carreras, materias y docentes.
 */
final class OrgChartStep implements SiiStep
{
    public function name(): string
    {
        return 'organigrama';
    }

    public function batches(SiiMigration $migration): iterable
    {
        $curated = $this->curated($migration);
        $this->checkContract($migration, $curated);

        $migration->remember('department_for_area', fn (): array => array_map(
            fn (array $row): ?string => $row['departamento_de'] === '' ? null : $row['departamento_de'],
            $curated,
        ));

        yield new SiiBatch('departments', 'departamentos.csv', $this->rows($curated, 'departamento', fn (array $row): array => [
            'code' => $row['codigo'],
            'name' => $row['nombre'],
            'active' => $this->flag($row['activo']),
        ]));

        yield new SiiBatch('org_units', 'unidades.csv', $this->rows($curated, 'unidad', fn (array $row): array => [
            'code' => $row['codigo'],
            'name' => $row['nombre'],
            'kind' => $row['tipo'],
            'parent' => $row['padre'],
            'active' => $this->flag($row['activo']),
        ]));
    }

    /**
     * Las filas curadas por clave del SII.
     *
     * @return array<string, array<string, string>>
     */
    private function curated(SiiMigration $migration): array
    {
        $byKey = [];

        foreach ($migration->curated('organigrama') as $row) {
            $byKey[$row['clave_sii']] = $row;
        }

        return $byKey;
    }

    /**
     * @param  array<string, array<string, string>>  $curated
     *
     * @throws SiiCuratedMismatch
     */
    private function checkContract(SiiMigration $migration, array $curated): void
    {
        $siiKeys = [];

        foreach ($migration->export()->reader(SiiFile::Organigrama)->rows() as $row) {
            $siiKeys[$row['clave_area']] = true;
        }

        $missing = array_values(array_diff(array_map('strval', array_keys($siiKeys)), array_map('strval', array_keys($curated))));
        $extra = array_values(array_diff(array_map('strval', array_keys($curated)), array_map('strval', array_keys($siiKeys))));

        if ($missing !== [] || $extra !== []) {
            throw new SiiCuratedMismatch('organigrama', $missing, $extra);
        }
    }

    /**
     * @param  array<string, array<string, string>>  $curated
     * @param  callable(array<string, string>): array<string, string>  $map
     * @return list<array<string, string>>
     */
    private function rows(array $curated, string $destination, callable $map): array
    {
        return array_values(array_map($map, array_filter($curated, fn (array $row): bool => $row['destino'] === $destination)));
    }

    private function flag(string $value): string
    {
        return strtolower($value) === 'si' ? 'Sí' : 'No';
    }
}
