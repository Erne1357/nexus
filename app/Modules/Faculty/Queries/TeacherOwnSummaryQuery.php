<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Lo del propio docente para su Resumen (`/resumen`, tarea 1.85, IN-03): su ficha, su experiencia académica y su
 * disponibilidad, solo de lectura (las capturan los jefes; IN-01, confirmado el 27 de noviembre de 2026). Recibe el docente
 * ya resuelto (el ligado al usuario de la sesión), nunca un id del navegador, y lee solo lo de ese docente. Sin N+1.
 * Staffing la compone; Faculty no lee Staffing.
 */
final class TeacherOwnSummaryQuery
{
    public function __construct(private readonly AvailabilityGridQuery $availability) {}

    /**
     * La ficha de hoy: nombre, si está activo, el departamento principal (el primero por clave si ninguno lo es) y
     * cuántos otros tiene, las plazas vigentes hoy con sus horas base, el grado y la fecha de ingreso. El departamento
     * borrado se nombra igual.
     *
     * @return array{name: string, active: bool, department: ?array{code: string, name: string}, other_departments: int, positions: list<array{kind: string, base_hours: int}>, degree: ?string, hired_on: ?CarbonImmutable}
     */
    public function identity(Teacher $teacher): array
    {
        $adscriptions = $teacher->adscriptions()
            ->with(['department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'code', 'name'])])
            ->get()
            ->sortBy([
                fn (TeacherDepartment $a, TeacherDepartment $b): int => (int) $b->is_primary <=> (int) $a->is_primary,
                fn (TeacherDepartment $a, TeacherDepartment $b): int => strcmp((string) $a->department?->code, (string) $b->department?->code),
            ])
            ->values();
        $department = $adscriptions->first()?->department;
        $positions = $teacher->positions()->currentOn(CarbonImmutable::now())->orderBy('valid_from')->orderBy('id')->get();

        return [
            'name' => $teacher->name,
            'active' => $teacher->is_active,
            'department' => $department === null ? null : ['code' => $department->code, 'name' => $department->name],
            'other_departments' => max(0, $adscriptions->count() - 1),
            'positions' => $positions->map(fn (TeacherPosition $position): array => ['kind' => $position->kind->label(), 'base_hours' => $position->base_hours])->all(),
            'degree' => $teacher->degree?->label(),
            'hired_on' => $teacher->hired_on,
        ];
    }

    /**
     * Su experiencia académica (S17): todas las materias que puede dar, las preferentes primero y luego por nombre sin distinguir acentos
     * (la vista corta a las que quepan). Las quitadas no cuentan; la materia borrada se nombra igual.
     *
     * @return array{total: int, subjects: list<array{subject_id: string, code: string, name: string, level: string}>}
     */
    public function experience(Teacher $teacher): array
    {
        $subjects = $teacher->qualifications()
            ->with(['subject' => fn (BelongsTo $subject) => $subject->withTrashed()->select(['id', 'code', 'name'])])
            ->get()
            ->sort(fn (TeacherSubjectQualification $a, TeacherSubjectQualification $b): int => [$a->level === QualificationLevel::Preferente ? 0 : 1, Str::lower(Str::ascii((string) $a->subject?->name)), $a->id]
                <=> [$b->level === QualificationLevel::Preferente ? 0 : 1, Str::lower(Str::ascii((string) $b->subject?->name)), $b->id])
            ->map(fn (TeacherSubjectQualification $qualification): array => [
                'subject_id' => $qualification->subject_id,
                'code' => (string) $qualification->subject?->code,
                'name' => (string) $qualification->subject?->name,
                'level' => $qualification->level->label(),
            ])
            ->values();

        return ['total' => $subjects->count(), 'subjects' => $subjects->all()];
    }

    /**
     * Su disponibilidad en `$term`, un renglón por día con rangos guardados, con la cuadrícula y los rangos unidos de
     * AvailabilityGridQuery (S12): `ranges` lleva cada rango como texto «7:00–15:00» y su tipo («No disponible»
     * o «Preferido»; por omisión todo bloque está disponible, así que lo guardado son excepciones y preferencias). Los
     * días sin rangos no salen: vacío es sin disponibilidad registrada.
     *
     * @return list<array{day: int, name: string, ranges: list<array{label: string, kind: string, kind_label: string}>}>
     */
    public function availability(Teacher $teacher, Term $term): array
    {
        return $this->availabilityBox($teacher, $term)['days'];
    }

    /**
     * La caja «Tu disponibilidad» del Resumen: las horas disponibles y el total de la cuadrícula (como el resumen de la
     * ficha: disponibles son todas menos las no disponibles) y, por día, los rangos de availability(). Una sola lectura
     * de la cuadrícula.
     *
     * @return array{available: int|float, total: int|float, days: list<array{day: int, name: string, ranges: list<array{label: string, kind: string, kind_label: string}>}>}
     */
    public function availabilityBox(Teacher $teacher, Term $term): array
    {
        $grid = $this->availability->for($teacher, $term, $this->availability->profile());
        $days = [];

        foreach ($grid['days'] as $index => $day) {
            $ranges = array_map(fn (array $range): array => [
                'label' => $range['label'],
                'kind' => $range['kind']->value,
                'kind_label' => $range['kind']->label(),
            ], $grid['ranges'][$index]);

            if ($ranges !== []) {
                $days[] = ['day' => $day['day'], 'name' => $day['name'], 'ranges' => $ranges];
            }
        }

        return ['available' => $grid['summary']['available'], 'total' => $grid['summary']['total'], 'days' => $days];
    }
}
