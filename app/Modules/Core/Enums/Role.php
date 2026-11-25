<?php

namespace App\Modules\Core\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\OrgTree;

/**
 * Puestos de R1 (PROJECT.md, "Actores y roles"). El valor es el de `role_assignments.role`. El de `alumno` llega antes
 * que R3 (AC-10): lo da la migración del SII a cada alumno, sin alcance y solo con funciones personales. R3 añade
 * `servicios_escolares`. El de `directivo` (AZ-03, R-V2-4) es del Director y de las subdirecciones que no son la
 * Académica: lee todo lo académico del Tec y no escribe nada. El de `personal` («Personal de área», CO-01 y CO-02, confirmado
 * el 24 de noviembre de 2026) es del personal de una unidad que no es departamento académico, varios por unidad: no
 * tiene permisos propios fuera de lo que cada permiso declara para su unidad (hoy, los de Comunicación y Difusión).
 */
enum Role: string
{
    /** Los atajos de Inicio a las pantallas de Comunicación y Difusión; Inicio solo muestra los que el menú deja ver (CO-07). */
    private const COMMUNICATION_SHORTCUTS = ['communication.credential-requests', 'communication.student-photos', 'communication.staff-photos'];

    case Admin = 'admin';
    case Subdireccion = 'subdireccion';
    case Directivo = 'directivo';
    case JefeDepartamento = 'jefe_departamento';
    case JefeProyectoDocente = 'jefe_proyecto_docente';
    case Coordinador = 'coordinador';
    case Personal = 'personal';
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
            self::Directivo => 'Directivo',
            self::JefeDepartamento => 'Jefe de departamento académico',
            self::JefeProyectoDocente => 'Jefe de proyecto docente',
            self::Coordinador => 'Coordinador de carrera',
            self::Personal => 'Personal de área',
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
            self::Admin => 'Tienes acceso a todo Nexus. Tus acciones quedan en la bitácora como las de cualquier usuario.',
            self::Subdireccion => 'Cuando se pide, das el visto bueno final antes de publicar la carga académica.',
            self::Directivo => 'Consultas en modo lectura todo lo académico del Tec.',
            self::JefeDepartamento => 'Validas los catálogos de tu departamento, asignas docentes y salones a los grupos de sus materias y cierras su carga.',
            self::JefeProyectoDocente => 'Junto con el jefe de departamento, asignas docentes a los grupos de tu modalidad según la prioridad de base; si tu nombramiento es de un campus, solo los de ese campus.',
            self::Coordinador => 'Analizas el avance de los alumnos de tu carrera en tu modalidad, propones qué materias y cuántos grupos abrir y armas la propuesta de horarios por semestre; si tu nombramiento es de un campus, ves toda la propuesta y cambias lo de tu campus.',
            self::Personal => 'Trabajas en Nexus lo que tu área tiene a su cargo.',
            self::Docente => 'Consultas tu propia carga: los grupos que impartes y sus horarios.',
            self::Alumno => 'Consultas tus datos escolares en «Mis datos», y ahí mismo puedes cambiar tu contraseña cuando quieras.',
        };
    }

    /**
     * El nombre del puesto para quien lo ocupa sobre `$units` (AZ-02, confirmado el 5 de noviembre de 2026: la credencial
     * nombra su unidad). El jefe de departamento de una sola unidad que no es departamento académico la nombra (R-V2-4,
     * los formatos son supuesto por confirmar): «Jefe del Departamento de Servicios Escolares» (si el nombre no
     * empieza con «Departamento», se lo antepone), «Jefe de la División de Estudios Profesionales» (igual con
     * «División») y «Titular de la Coordinación General Campus II» para una oficina. Con varias unidades que no lo son, o mezcladas con departamentos académicos, «Jefe de departamento»
     * (supuesto por confirmar). El directivo es el nombre de su unidad: «Dirección», «Subdirección de Planeación y
     * Vinculación» (AZ-03); con varias unidades, label() (supuesto por confirmar). El personal de área, con el mismo
     * patrón que el jefe de una unidad no académica: «Personal del Departamento de Comunicación y Difusión», «Personal de
     * la División de …» y, para una oficina, «Personal de la Coordinación General Campus II» (CO-01); con varias unidades,
     * label(). En los demás casos, label().
     *
     * @param  list<array{kind: OrgUnitKind, code: string, name: string}>  $units
     */
    public function titleOn(array $units): string
    {
        if ($this === self::Directivo) {
            $names = array_values(array_unique(array_column($units, 'name')));

            return count($names) === 1 ? $names[0] : $this->label();
        }

        if ($this === self::Personal) {
            $names = array_values(array_unique(array_column($units, 'name')));

            return count($names) === 1 ? 'Personal '.$this->ofUnit($units[0]['kind'], $names[0]) : $this->label();
        }

        $outside = array_values(array_filter($units, fn (array $unit): bool => $unit['kind'] !== OrgUnitKind::DepartamentoAcademico));

        if ($this !== self::JefeDepartamento || $outside === []) {
            return $this->label();
        }

        $names = array_values(array_unique(array_column($units, 'name')));

        if (count($names) > 1) {
            return 'Jefe de departamento';
        }

        $name = $names[0];

        return $outside[0]['kind'] === OrgUnitKind::Oficina ? "Titular de la {$name}" : 'Jefe '.$this->ofUnit($outside[0]['kind'], $name);
    }

    /**
     * «de la División de …», «del Departamento de …» (si el nombre no empieza con la palabra, se la antepone) y, para
     * una oficina, una subdirección o la Dirección, «de la <nombre>»: lo que sigue al puesto en titleOn().
     */
    private function ofUnit(OrgUnitKind $kind, string $name): string
    {
        return match ($kind) {
            OrgUnitKind::Division => 'de la '.(mb_stripos($name, 'división ') === 0 ? $name : "División de {$name}"),
            OrgUnitKind::Oficina, OrgUnitKind::Subdireccion, OrgUnitKind::Direccion => "de la {$name}",
            default => 'del '.(mb_stripos($name, 'departamento ') === 0 ? $name : "Departamento de {$name}"),
        };
    }

    /**
     * Lo que el puesto hace en Nexus para quien lo ocupa sobre `$units`, para Inicio (AZ-02): el jefe del Departamento de
     * Servicios Escolares valida las materias, los planes y las carreras de todo el Tec (CV15) y las materias de la
     * migración del SII que esperan semestre (D19); el jefe y el personal de Comunicación y Difusión manejan lo que se
     * ve al entrar, las fotos y las credenciales (CO-01); el jefe o el personal de otra unidad que no es departamento
     * académico no tiene funciones todavía (R-V2-4), ni el personal de Servicios Escolares, cuyo permiso es del jefe. Si
     * alguna de sus unidades es un departamento académico, o el puesto no es el de jefe de departamento ni el de personal
     * de área, description().
     *
     * @param  list<array{kind: OrgUnitKind, code: string, name: string}>  $units
     */
    public function descriptionOn(array $units): string
    {
        if (! in_array($this, [self::JefeDepartamento, self::Personal], true) || $units === [] || array_any($units, fn (array $unit): bool => $unit['kind'] === OrgUnitKind::DepartamentoAcademico)) {
            return $this->description();
        }

        $only = fn (string $code): bool => array_all($units, fn (array $unit): bool => $unit['code'] === $code);

        return match (true) {
            $only(OrgTree::COMMUNICATION) => 'Manejas lo que se ve al entrar a Nexus, las fotos y las credenciales.',
            $this === self::JefeDepartamento && $only(OrgTree::SCHOOL_SERVICES) => 'Validas cada periodo las materias, los planes de estudio y las carreras de todo el Tec, y asignas semestre a las materias de la migración del SII que esperan validación, en la pestaña «Por validar» de cada plan de estudios.',
            default => 'Tu área todavía no tiene funciones en Nexus.',
        };
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
            self::Directivo => 'Este puesto pide la Dirección o una subdirección que no sea la Académica. Tiene un solo titular vigente.',
            self::JefeDepartamento => 'Este puesto pide un departamento (académico o no), una división o una oficina. Tiene un solo jefe vigente; si la unidad no es académica, solo lleva su título.',
            self::JefeProyectoDocente => 'Este puesto pide modalidad y departamento académico. El campus es opcional: sin campus, trabaja todos los de su modalidad.',
            self::Coordinador => 'Pide una modalidad y una o varias carreras; el campus es opcional: sin campus, trabaja todos los de su modalidad. Se crea un nombramiento por carrera.',
            self::Personal => 'Este puesto pide un departamento que no sea académico, una división o una oficina. Admite varias personas por unidad.',
            self::Docente, self::Alumno => null,
        };
    }

    /**
     * Cómo se llama la unidad sobre la que se nombra el puesto, para el formulario «Nombrar» y la ficha de la persona:
     * la del directivo (la Dirección o una subdirección); la subdirección; el departamento del jefe, académico o
     * Servicios Escolares (D19); la del personal de área (CO-01); el departamento académico del jefe de proyecto docente.
     * Nulo si el puesto no lleva unidad.
     */
    public function unitLabel(): ?string
    {
        return match ($this) {
            self::Subdireccion => 'Subdirección',
            self::Directivo, self::JefeDepartamento, self::Personal => 'Unidad',
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
     * Si Inicio le muestra el calendario del periodo: al personal (isStaff()), salvo al docente, cuyo Inicio es solo lo
     * suyo (IN-01, confirmado el 14 de noviembre de 2026).
     */
    public function seesTermCalendarOnHome(): bool
    {
        return $this->isStaff() && $this !== self::Docente;
    }

    /**
     * Si su bloque de Inicio lleva su acceso: usuario, correo institucional y «Cambiar contraseña» (IN-01, el docente).
     */
    public function showsAccessOnHome(): bool
    {
        return $this === self::Docente;
    }

    /**
     * Las entradas del menú, por nombre de ruta, a las que lleva su bloque de Inicio, si el usuario las ve en su menú
     * (IN-02): el del jefe de departamento, a «Materias por colocar», que solo ve el de Servicios Escolares.
     *
     * @return list<string>
     */
    public function homeShortcuts(): array
    {
        return match ($this) {
            self::JefeDepartamento => ['academic.placements', ...self::COMMUNICATION_SHORTCUTS],
            self::Personal => self::COMMUNICATION_SHORTCUTS,
            default => [],
        };
    }

    /**
     * Columnas de `role_assignments` que un nombramiento de este puesto debe llenar para contar en Actor
     * (columna "Alcance" de PROJECT.md). Admin no necesita alcance y el docente es de todo el Tec. El alumno tampoco
     * lleva alcance: sus permisos son personales (AC-10). La Subdirección
     * va sobre su unidad (Subdirección Académica, AZ-S3) y el jefe de departamento sobre la de su departamento, los
     * dos en todo el Tec y en todas las modalidades: no piden campus ni modalidad, y sin ellos valen en todos los
     * activos. El jefe de proyecto docente va sobre su departamento y una modalidad, y el coordinador sobre su carrera
     * y una modalidad (AZ-S17; M5 y M9): el campus es opcional para los dos (optionalScopeColumns()). Un nombramiento
     * con un campus que su puesto no pide sigue valiendo solo en ese campus. El directivo va sobre su unidad (la
     * Dirección o una subdirección, AZ-03), que nombra su credencial y no acota lo que lee (readsWholeTec()). El
     * personal de área va sobre su unidad, como el jefe (CO-01).
     *
     * @return list<'org_unit_id'|'career_id'|'modality_id'>
     */
    public function scopeColumns(): array
    {
        return match ($this) {
            self::Admin, self::Docente, self::Alumno => [],
            self::Directivo, self::Subdireccion, self::JefeDepartamento, self::Personal => ['org_unit_id'],
            self::JefeProyectoDocente => ['modality_id', 'org_unit_id'],
            self::Coordinador => ['modality_id', 'career_id'],
        };
    }

    /**
     * Columnas de `role_assignments` que el puesto admite sin pedirlas (AZ-S17): el campus del coordinador y del jefe de
     * proyecto docente. Con campus, el nombramiento trabaja solo ese campus; sin él, todos los de su modalidad.
     * AppointRole rechaza cualquier otra columna que el puesto no pida.
     *
     * @return list<'campus_id'>
     */
    public function optionalScopeColumns(): array
    {
        return match ($this) {
            self::JefeProyectoDocente, self::Coordinador => ['campus_id'],
            default => [],
        };
    }

    /**
     * CF-04: las dimensiones del alcance que el puesto acota, en el orden de Dimension: las que lleva en su nombramiento
     * (scopeColumns() y optionalScopeColumns()) y en las que es general por regla. El jefe de departamento, el personal de
     * área y la Subdirección son de todo el Tec en campus y modalidad (AZ-S17, CO-01). El admin es general en todas, pero no se edita
     * (CF-S1). El directivo es general en todas para leer (AZ-03): solo recibe permisos de lectura
     * (PermissionGrantRules), que lee en todo el Tec. El docente y el alumno no acotan ninguna: solo reciben permisos
     * personales.
     *
     * @return list<Dimension>
     */
    public function boundedDimensions(): array
    {
        $general = match ($this) {
            self::Admin, self::Directivo => Dimension::cases(),
            self::Subdireccion, self::JefeDepartamento, self::Personal => [Dimension::Campus, Dimension::Modality],
            default => [],
        };
        $columns = [...$this->scopeColumns(), ...$this->optionalScopeColumns()];

        return array_values(array_filter(
            Dimension::cases(),
            fn (Dimension $dimension): bool => in_array($dimension, $general, true) || in_array($dimension->column(), $columns, true),
        ));
    }

    /**
     * Si el puesto es uno solo por unidad en cada fecha (AZ-S7, AZ-03): lo garantizan las exclusiones
     * `role_assignments_one_jefe`, `role_assignments_one_subdireccion` y `role_assignments_one_directivo`.
     */
    public function isExclusive(): bool
    {
        return match ($this) {
            self::Directivo, self::JefeDepartamento, self::Subdireccion => true,
            default => false,
        };
    }

    /**
     * R-V2-4 (AZ-03): si el puesto lee en todo el Tec. Actor no acota por unidad ni por carrera sus permisos de lectura
     * (Permission::isReadOnly()), y el panel solo le da permisos de lectura (PermissionGrantRules). Su unidad nombra
     * su credencial. Solo el directivo.
     */
    public function readsWholeTec(): bool
    {
        return $this === self::Directivo;
    }

    /**
     * Los tipos de unidad del organigrama sobre los que se nombra el puesto: el directivo, sobre la Dirección o una
     * subdirección (que no sea la Académica, AZ-03); la Subdirección, sobre una subdirección (y solo sobre Subdirección
     * Académica, AZ-S10); el jefe, sobre un departamento (académico o no), una división o una oficina (R-V2-4); el personal
     * de área, sobre las mismas sin el departamento académico (CO-01): ni sobre la Dirección ni sobre una subdirección,
     * porque una unidad aporta los permisos que aplican a sus descendientes (AZ-S16) y el personal de Planeación y
     * Vinculación tendría los de Comunicación y Difusión; el jefe de proyecto docente, sobre un departamento académico.
     * Los demás puestos no llevan unidad: vacío.
     *
     * @return list<OrgUnitKind>
     */
    public function unitKinds(): array
    {
        return match ($this) {
            self::Subdireccion => [OrgUnitKind::Subdireccion],
            self::Directivo => [OrgUnitKind::Direccion, OrgUnitKind::Subdireccion],
            self::JefeDepartamento => [OrgUnitKind::DepartamentoAcademico, OrgUnitKind::Departamento, OrgUnitKind::Division, OrgUnitKind::Oficina],
            self::Personal => [OrgUnitKind::Departamento, OrgUnitKind::Division, OrgUnitKind::Oficina],
            self::JefeProyectoDocente => [OrgUnitKind::DepartamentoAcademico],
            default => [],
        };
    }
}
