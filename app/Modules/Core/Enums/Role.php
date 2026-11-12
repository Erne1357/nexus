<?php

namespace App\Modules\Core\Enums;

use App\Modules\Core\Authorization\OrgTree;

/**
 * Puestos de R1 (PROJECT.md, "Actores y roles"). El valor es el de `role_assignments.role`. El de `alumno` llega antes
 * que R3 (AC-10): lo da la migración del SII a cada alumno, sin alcance y solo con funciones personales. R3 añade
 * `servicios_escolares`.
 */
enum Role: string
{
    case Admin = 'admin';
    case Subdireccion = 'subdireccion';
    case JefeDepartamento = 'jefe_departamento';
    case JefeProyectoDocente = 'jefe_proyecto_docente';
    case Coordinador = 'coordinador';
    case Docente = 'docente';
    case Alumno = 'alumno';

    /**
     * Los puestos que son una jefatura de departamento. Desde 3.P8 se nombran y terminan como cualquier nombramiento,
     * en la ficha del docente, en «Personas y puestos» y en el panel de la unidad del Organigrama (AZ-S11), y la baja del
     * docente los termina.
     *
     * @var list<self>
     */
    public const HEADSHIPS = [self::JefeDepartamento, self::JefeProyectoDocente];

    /**
     * Nombre para la interfaz (la credencial y la página de inicio), como lo nombran PROJECT.md y el
     * glosario.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administración',
            self::Subdireccion => 'Subdirección Académica',
            self::JefeDepartamento => 'Jefe de departamento académico',
            self::JefeProyectoDocente => 'Jefe de proyecto docente',
            self::Coordinador => 'Coordinador de carrera',
            self::Docente => 'Docente',
            self::Alumno => 'Alumno',
        };
    }

    /**
     * Lo que el puesto hace en Nexus, para la página de inicio.
     */
    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Tienes acceso a todo Nexus, en todos los campus. Tus acciones quedan en la bitácora como las de cualquier usuario.',
            self::Subdireccion => 'Si el campus lo tiene configurado, das el visto bueno final antes de publicar la carga académica.',
            self::JefeDepartamento => 'Validas los catálogos de tu departamento, asignas docentes y salones a los grupos de sus materias y cierras su carga.',
            self::JefeProyectoDocente => 'Junto con el jefe de departamento, asignas docentes a los grupos según la prioridad de base.',
            self::Coordinador => 'Analizas el avance de los alumnos de tu carrera, propones qué materias y cuántos grupos abrir y armas la propuesta de horarios por semestre.',
            self::Docente => 'Consultas tu propia carga: los grupos que impartes y sus horarios.',
            self::Alumno => 'Consultas tus datos escolares en «Mis datos», y ahí mismo puedes cambiar tu contraseña cuando quieras.',
        };
    }

    /**
     * El nombre del puesto para quien lo ocupa sobre `$units` (AZ-02, confirmado el 5 de noviembre de 2026: la credencial
     * nombra su unidad). El jefe de departamento de una sola unidad que no es departamento académico la nombra: «Jefe
     * del Departamento de Servicios Escolares» (si el nombre de la unidad no empieza con «Departamento», se lo
     * antepone). Con varias unidades que no lo son, o mezcladas con departamentos académicos, «Jefe de departamento»
     * (supuesto por confirmar). En los demás casos, label().
     *
     * @param  list<array{kind: OrgUnitKind, code: string, name: string}>  $units
     */
    public function titleOn(array $units): string
    {
        $outside = array_filter($units, fn (array $unit): bool => $unit['kind'] !== OrgUnitKind::DepartamentoAcademico);

        if ($this !== self::JefeDepartamento || $outside === []) {
            return $this->label();
        }

        $names = array_values(array_unique(array_column($units, 'name')));

        if (count($names) > 1) {
            return 'Jefe de departamento';
        }

        return 'Jefe del '.(mb_stripos($names[0], 'departamento ') === 0 ? $names[0] : "Departamento de {$names[0]}");
    }

    /**
     * Lo que el puesto hace en Nexus para quien lo ocupa sobre `$units`, para Inicio (AZ-02): el jefe del Departamento de
     * Servicios Escolares solo valida las materias de la migración del SII que esperan semestre (D19); el de otra
     * unidad que no es departamento académico, lo de su unidad. Si alguna de sus unidades es un departamento
     * académico, o el puesto no es el de jefe de departamento, description().
     *
     * @param  list<array{kind: OrgUnitKind, code: string, name: string}>  $units
     */
    public function descriptionOn(array $units): string
    {
        if ($this !== self::JefeDepartamento || $units === [] || array_any($units, fn (array $unit): bool => $unit['kind'] === OrgUnitKind::DepartamentoAcademico)) {
            return $this->description();
        }

        return array_all($units, fn (array $unit): bool => $unit['code'] === OrgTree::SCHOOL_SERVICES)
            ? 'Asignas semestre a las materias de la migración del SII que esperan validación, en la pestaña «Por validar» de cada plan de estudios.'
            : 'Atiendes en Nexus lo que corresponde a tu unidad; sus pantallas aparecen en el menú.';
    }

    /**
     * El nombre del puesto en una lista para elegirlo, sin persona ni unidad (el filtro de «Personas y puestos», «¿Quién
     * ocupa un puesto?» y «Nombrar» en la ficha de la persona): el jefe de departamento es «Jefe de departamento»,
     * porque lo ocupan los jefes de departamentos académicos y el de Servicios Escolares (AZ-02, AZ-S16); los demás,
     * label(). Con una persona o una unidad, titleOn().
     */
    public function listLabel(): string
    {
        return $this === self::JefeDepartamento ? 'Jefe de departamento' : $this->label();
    }

    /**
     * La pista del formulario «Nombrar»: qué alcance pide el puesto y si tiene un solo titular (AZ-S7). Nulo para el
     * docente y el alumno, que no se nombran ahí (AZ-S11, AC-10).
     */
    public function appointHint(): ?string
    {
        return match ($this) {
            self::Admin => 'Este puesto no tiene alcance: vale en todo el sistema.',
            self::Subdireccion => 'Este puesto pide la Subdirección Académica. Tiene un solo titular vigente.',
            self::JefeDepartamento => 'Este puesto pide el departamento académico o Servicios Escolares. Tiene un solo jefe vigente.',
            self::JefeProyectoDocente => 'Este puesto pide campus y departamento académico.',
            self::Coordinador => 'Este puesto pide campus y carrera.',
            self::Docente, self::Alumno => null,
        };
    }

    /**
     * Cómo se llama la unidad sobre la que se nombra el puesto, para el formulario «Nombrar» y la ficha de la persona:
     * la subdirección; el departamento del jefe, académico o Servicios Escolares (D19); el departamento académico del
     * jefe de proyecto docente. Nulo si el puesto no lleva unidad.
     */
    public function unitLabel(): ?string
    {
        return match ($this) {
            self::Subdireccion => 'Subdirección',
            self::JefeDepartamento => 'Departamento',
            self::JefeProyectoDocente => 'Departamento académico',
            default => null,
        };
    }

    /**
     * Si el puesto es del personal: todos menos el de alumno, que no ve el calendario del periodo en Inicio (su
     * portada es mínima mientras no haya pantallas de alumno, AC-15).
     */
    public function isStaff(): bool
    {
        return $this !== self::Alumno;
    }

    /**
     * Columnas de `role_assignments` que un nombramiento de este puesto debe llenar para contar en Actor
     * (columna "Alcance" de PROJECT.md). Admin no necesita alcance y el docente es de todo el Tec. El alumno tampoco
     * lleva alcance: sus permisos son personales (AC-10). La Subdirección
     * va sobre su unidad (Subdirección Académica, AZ-S3) y el jefe de departamento sobre la de su departamento, los
     * dos en todo el Tec: no piden campus, y sin campus valen en todos los activos. El jefe de proyecto docente y el
     * coordinador sí piden campus. Un nombramiento con un campus que su puesto no pide sigue valiendo solo en ese
     * campus.
     *
     * @return list<'campus_id'|'org_unit_id'|'career_id'>
     */
    public function scopeColumns(): array
    {
        return match ($this) {
            self::Admin, self::Docente, self::Alumno => [],
            self::Subdireccion, self::JefeDepartamento => ['org_unit_id'],
            self::JefeProyectoDocente => ['campus_id', 'org_unit_id'],
            self::Coordinador => ['campus_id', 'career_id'],
        };
    }

    /**
     * Si el puesto es uno solo por unidad en cada fecha (AZ-S7): lo garantizan las exclusiones
     * `role_assignments_one_jefe` y `role_assignments_one_subdireccion`.
     */
    public function isExclusive(): bool
    {
        return match ($this) {
            self::JefeDepartamento, self::Subdireccion => true,
            default => false,
        };
    }

    /**
     * El tipo de unidad del organigrama sobre la que se nombra el puesto: la Subdirección, sobre una subdirección (y
     * solo sobre Subdirección Académica, AZ-S10); el jefe y el jefe de proyecto docente, sobre un departamento
     * académico. Los demás puestos no llevan unidad.
     */
    public function unitKind(): ?OrgUnitKind
    {
        return match ($this) {
            self::Subdireccion => OrgUnitKind::Subdireccion,
            self::JefeDepartamento, self::JefeProyectoDocente => OrgUnitKind::DepartamentoAcademico,
            default => null,
        };
    }
}
