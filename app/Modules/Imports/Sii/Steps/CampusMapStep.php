<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Facilities\Actions\SyncCampusMap;
use App\Modules\Imports\Sii\SiiBatchReport;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;

/**
 * Mapa del campus (1.24.2): vuelve a normalizar edificios, salones y departamentos con el mapa del Campus I
 * (SyncCampusMap) después de `aulas`, para que una corrida nueva de la migración no deshaga la normalización: el curado
 * `aulas.csv` aún puede traer la clave vieja de un edificio y el paso `aulas` la crea otra vez.
 *
 * No pasa por el motor de importación: escribe por su cuenta (con las Actions de Facilities y Academic) y entrega un
 * reporte (plantilla `campus_map`): creados son los salones que salen del mapa; cambios, los que se mueven o se ligan al
 * mapa. En un ensayo (`dryRun`) SyncCampusMap revierte todo y el reporte dice lo que haría. Los salones del SII que no
 * tienen lugar en el mapa no son error: van como nota con su número, sin listar las claves.
 */
final class CampusMapStep implements SiiStep
{
    public function __construct(private readonly SyncCampusMap $sync) {}

    public function name(): string
    {
        return 'mapa';
    }

    /**
     * @return iterable<SiiBatchReport>
     */
    public function batches(SiiMigration $migration): iterable
    {
        $report = $this->sync->handle($migration->dryRun());
        $changed = $report['rooms_moved'] + $report['rooms_linked'];
        $rows = $report['rooms_created'] + $changed;
        $unmatched = count($report['rooms_unmatched']);

        $notes = [
            "Edificios: {$report['buildings_created']} creados, {$report['buildings_updated']} renombrados o reactivados, {$report['buildings_deactivated']} desactivados.",
            "Campus II: {$report['c2_buildings_created']} edificios creados y {$report['c2_rooms_moved']} salones movidos.",
            "Departamentos con ubicación y contacto actualizados: {$report['departments_updated']}.",
        ];

        if ($unmatched > 0) {
            $notes[] = "{$unmatched} salones del SII sin lugar en el mapa se dejaron como estaban.";
        }

        yield new SiiBatchReport('campus_map', 'campus-map-c1.php', $rows, $rows, 0, 0, $report['rooms_created'], $changed, notes: $notes);
    }
}
