<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Queries\TextSearch;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Support\Str;

/**
 * «Buscar otro docente» (HC-21): los docentes activos de cualquier departamento cuyo nombre o RFC contiene lo escrito,
 * sin importar mayúsculas ni acentos, para asignarlos a un grupo sin llenar el selector con todos. Solo busca con
 * `MIN_LENGTH` caracteres o más y devuelve a lo más `LIMIT` (supuestos por confirmar); primero el RFC exacto y luego
 * por nombre. Cada resultado trae lo que el jefe necesita para decidir (TeacherSearchResult): adscripciones, lugar en
 * el ranking del grupo, horas del periodo y si choca con el horario del grupo (HC-01, solo lectura: lo que decide
 * asignarlo sigue siendo AssignTeacher). No incluye al docente que ya tiene el grupo. No revisa permisos: quien la
 * llama autoriza al actor sobre el grupo.
 */
final class TeacherSearchQuery
{
    public const int MIN_LENGTH = 3;

    public const int LIMIT = 10;

    public function __construct(private readonly ScheduleValidator $validator) {}

    /**
     * @return list<TeacherSearchResult>
     */
    public function search(Group $group, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $pattern = '%'.TextSearch::escape(Str::lower(Str::ascii($term))).'%';
        $rfc = Str::upper($term);

        $teachers = Teacher::query()
            ->where('teachers.is_active', true)
            ->when($group->teacher_id !== null, fn ($query) => $query->where('teachers.id', '!=', $group->teacher_id))
            ->where(fn ($query) => $query
                ->whereRaw("translate(lower(teachers.name), 'áéíóúüñ', 'aeiouun') like ?", [$pattern])
                ->orWhereRaw('upper(teachers.rfc) like ?', ['%'.TextSearch::escape($rfc).'%']))
            ->orderByRaw('(upper(teachers.rfc) = ?) desc', [$rfc])
            ->orderByRaw("translate(lower(teachers.name), 'áéíóúüñ', 'aeiouun')")
            ->orderBy('teachers.id')
            ->limit(self::LIMIT)
            ->get();

        if ($teachers->isEmpty()) {
            return [];
        }

        $ids = $teachers->modelKeys();
        $adscriptions = TeacherDepartment::query()
            ->with('department:id,name')
            ->whereIn('teacher_id', $ids)
            ->orderByDesc('is_primary')
            ->get()
            ->groupBy('teacher_id');
        $places = StaffingRanking::query()->where('group_id', $group->getKey())->whereIn('teacher_id', $ids)->pluck('rank', 'teacher_id');

        $this->validator->forget();
        $clashes = $this->validator->checkTeachers($group, $teachers);
        $hours = $this->validator->teacherHours($group, $teachers);

        return $teachers->map(function (Teacher $teacher) use ($group, $adscriptions, $places, $clashes, $hours): TeacherSearchResult {
            $mine = $adscriptions->get($teacher->id, collect());
            $clash = ($clashes[$teacher->id] ?? null)?->forRule('HC-01')[0] ?? null;

            return new TeacherSearchResult(
                $teacher->id,
                $teacher->name,
                $teacher->rfc,
                $mine->map(fn (TeacherDepartment $adscription): string => (string) $adscription->department?->name)->unique()->values()->all(),
                $mine->doesntContain('department_id', $group->department_id),
                isset($places[$teacher->id]) ? (int) $places[$teacher->id] : null,
                $hours[$teacher->id]['before']['assigned_hours'] ?? null,
                $hours[$teacher->id]['before']['base_hours'] ?? null,
                $clash === null ? null : Str::after($clash->message, ' ya da '),
            );
        })->all();
    }
}
