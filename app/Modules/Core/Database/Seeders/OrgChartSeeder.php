<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;
use Illuminate\Database\Seeder;

/**
 * Organigrama tipo del ITCJ bajo la Dirección y la Subdirección Académica que crea la migración.
 * Es el supuesto AZ-S1 (por confirmar), tomado del directorio de cdjuarez.tecnm.mx/nosotros consultado
 * el 2 de noviembre de 2026. Servicios Escolares pertenece a la Subdirección Académica (AZ-02, confirmado el 5 de
 * noviembre de 2026); una instalación que ya la tiene en otra subdirección la mueve el admin en el Organigrama.
 *
 * Solo crea las claves que faltan (también entre las borradas: una unidad que el admin quitó no se
 * resucita) y nunca actualiza una existente, así que volver a sembrar no mueve lo que el admin
 * reubicó. Busca por clave solo entre las unidades estructurales y cuelga cada una de un padre
 * vigente: si el padre falta o el admin lo borró, se salta esa unidad (y con ella sus hijas). Escribe datos de referencia del catálogo, no datos de proceso: por eso corre en
 * producción (sin RefusesProduction) y no deja entrada en la bitácora.
 */
class OrgChartSeeder extends Seeder
{
    /**
     * Ordenado de modo que cada padre aparece antes que sus hijos.
     *
     * @var list<array{code: string, name: string, kind: OrgUnitKind, parent: string}>
     */
    private const array UNITS = [
        ['code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación y Vinculación', 'kind' => OrgUnitKind::Subdireccion, 'parent' => OrgTree::ROOT],
        ['code' => 'SUB-ADMINISTRATIVA', 'name' => 'Subdirección de Servicios Administrativos', 'kind' => OrgUnitKind::Subdireccion, 'parent' => OrgTree::ROOT],
        ['code' => 'DIV-PROFESIONAL', 'name' => 'División de Estudios Profesionales', 'kind' => OrgUnitKind::Division, 'parent' => OrgTree::ACADEMIC],
        ['code' => 'DIV-POSGRADO', 'name' => 'División de Estudios de Posgrado e Investigación', 'kind' => OrgUnitKind::Division, 'parent' => OrgTree::ACADEMIC],
        ['code' => 'DESARROLLO-ACADEMICO', 'name' => 'Departamento de Desarrollo Académico', 'kind' => OrgUnitKind::Departamento, 'parent' => OrgTree::ACADEMIC],
        ['code' => 'EDUCACION-DISTANCIA', 'name' => 'Departamento de Educación a Distancia', 'kind' => OrgUnitKind::Departamento, 'parent' => OrgTree::ACADEMIC],
        ['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento, 'parent' => OrgTree::ACADEMIC],
        ['code' => 'VINCULACION', 'name' => 'Departamento de Gestión Tecnológica y Vinculación', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-PLANEACION'],
        ['code' => 'EXTRAESCOLARES', 'name' => 'Departamento de Actividades Extraescolares', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-PLANEACION'],
    ];

    public function run(): void
    {
        foreach (self::UNITS as $unit) {
            if (OrgUnit::withTrashed()->where('code', $unit['code'])->where('kind', '<>', OrgUnitKind::DepartamentoAcademico)->exists()) {
                continue;
            }

            $parent = OrgUnit::query()->where('code', $unit['parent'])->where('kind', '<>', OrgUnitKind::DepartamentoAcademico)->first();

            if ($parent === null) {
                continue;
            }

            OrgUnit::create([
                'parent_id' => $parent->id,
                'code' => $unit['code'],
                'name' => $unit['name'],
                'kind' => $unit['kind'],
                'is_active' => true,
            ]);
        }
    }
}
