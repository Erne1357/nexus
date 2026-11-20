<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;
use Illuminate\Database\Seeder;

/**
 * Organigrama oficial del ITCJ (marzo de 2026) bajo la Dirección y la Subdirección Académica que crea la migración
 * (AZ-S1, R-V2-5, confirmado el 19 de noviembre de 2026). Servicios Escolares cuelga de la Subdirección de Planeación
 * y Vinculación, Educación a Distancia se siembra inactiva y la Coordinación General Campus II cuelga de la
 * Académica; una instalación que ya tiene una unidad en otro lugar la mueve el admin en el Organigrama.
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
     * @var list<array{code: string, name: string, kind: OrgUnitKind, parent: string, active?: bool}>
     */
    private const array UNITS = [
        ['code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación y Vinculación', 'kind' => OrgUnitKind::Subdireccion, 'parent' => OrgTree::ROOT],
        ['code' => 'SUB-ADMINISTRATIVA', 'name' => 'Subdirección de Servicios Administrativos', 'kind' => OrgUnitKind::Subdireccion, 'parent' => OrgTree::ROOT],
        ['code' => 'DIV-PROFESIONAL', 'name' => 'División de Estudios Profesionales', 'kind' => OrgUnitKind::Division, 'parent' => OrgTree::ACADEMIC],
        ['code' => 'DIV-POSGRADO', 'name' => 'División de Estudios de Posgrado e Investigación', 'kind' => OrgUnitKind::Division, 'parent' => OrgTree::ACADEMIC],
        ['code' => 'DESARROLLO-ACADEMICO', 'name' => 'Departamento de Desarrollo Académico', 'kind' => OrgUnitKind::Departamento, 'parent' => OrgTree::ACADEMIC],
        ['code' => 'EDUCACION-DISTANCIA', 'name' => 'Departamento de Educación a Distancia', 'kind' => OrgUnitKind::Departamento, 'parent' => OrgTree::ACADEMIC, 'active' => false],
        ['code' => 'COORDINACION-CAMPUS-II', 'name' => 'Coordinación General Campus II', 'kind' => OrgUnitKind::Oficina, 'parent' => OrgTree::ACADEMIC],
        ['code' => 'VINCULACION', 'name' => 'Departamento de Gestión Tecnológica y Vinculación', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-PLANEACION'],
        ['code' => 'EXTRAESCOLARES', 'name' => 'Departamento de Actividades Extraescolares', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-PLANEACION'],
        ['code' => 'PLANEACION', 'name' => 'Departamento de Planeación, Programación y Presupuestación', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-PLANEACION'],
        ['code' => 'COMUNICACION', 'name' => 'Departamento de Comunicación y Difusión', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-PLANEACION'],
        ['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-PLANEACION'],
        ['code' => 'CENTRO-INFORMACION', 'name' => 'Departamento de Centro de Información', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-PLANEACION'],
        ['code' => 'RECURSOS-HUMANOS', 'name' => 'Departamento de Recursos Humanos', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-ADMINISTRATIVA'],
        ['code' => 'RECURSOS-FINANCIEROS', 'name' => 'Departamento de Recursos Financieros', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-ADMINISTRATIVA'],
        ['code' => 'RECURSOS-MATERIALES', 'name' => 'Departamento de Recursos Materiales y Servicios', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-ADMINISTRATIVA'],
        ['code' => 'MANTENIMIENTO', 'name' => 'Departamento de Mantenimiento de Equipo', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-ADMINISTRATIVA'],
        ['code' => 'CENTRO-COMPUTO', 'name' => 'Departamento de Centro de Cómputo', 'kind' => OrgUnitKind::Departamento, 'parent' => 'SUB-ADMINISTRATIVA'],
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
                'is_active' => $unit['active'] ?? true,
            ]);
        }
    }
}
