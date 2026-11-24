<?php

namespace Tests\Support\Staffing;

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Carbon\CarbonImmutable;

/**
 * Una selección de carga en curso (tarea 1.33) con su carga incluida en asignación, para las pruebas de tomar, soltar y
 * terminar: docentes con plaza, adscripción, usuario y renglón en la cola, grupos con sesiones y salones de un campus.
 * Nombres y claves ficticios.
 */
final class SelectionWorld
{
    private function __construct(
        public readonly Term $term,
        public readonly Campus $campus,
        public readonly Building $building,
        public readonly Department $department,
        public readonly AcademicLoad $load,
        public readonly Selection $selection,
        public readonly User $admin,
    ) {}

    public static function create(SelectionStatus $status = SelectionStatus::Running): self
    {
        $term = Term::factory()->create();
        $campus = Campus::factory()->create();
        $building = Building::factory()->create(['campus_id' => $campus->id]);
        $department = Department::factory()->create();
        $modality = modalityOf('PRES');
        $load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create([
            'term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $department->id,
        ]);
        $selection = Selection::factory()->create([
            'term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $department->id, 'status' => $status,
        ]);
        $selection->loads()->attach($load->id);

        return new self($term, $campus, $building, $department, $load, $selection, User::factory()->admin()->create());
    }

    /**
     * Un docente con plaza de `$base` horas, usuario, adscripción y renglón en la cola. Su turno empieza en
     * `$slotStartsAt` (por omisión hace 5 minutos) y dura 30 minutos.
     *
     * @param  array<string, mixed>  $entry
     */
    public function teacher(int $base = 20, ?CarbonImmutable $slotStartsAt = null, int $position = 1, array $entry = [], string $name = 'Docente Uno'): Teacher
    {
        $teacher = Teacher::factory()->create(['name' => $name, 'user_id' => User::factory()->create()->id]);
        TeacherPosition::factory()->for($teacher)->asignatura($base)->create();
        TeacherDepartment::factory()->create(['teacher_id' => $teacher->id, 'department_id' => $this->department->id]);
        $starts = $slotStartsAt ?? CarbonImmutable::now()->subMinutes(5);

        SelectionEntry::factory()->for($this->selection)->create([
            'teacher_id' => $teacher->id,
            'base_hours' => $base,
            'position' => $position,
            'slot_index' => $position - 1,
            'slot_starts_at' => $starts,
            'slot_ends_at' => $starts->addMinutes(30),
            'status' => SelectionEntryStatus::Waiting,
            ...$entry,
        ]);

        return $teacher;
    }

    public function entryOf(Teacher $teacher): SelectionEntry
    {
        return SelectionEntry::query()->where('selection_id', $this->selection->id)->where('teacher_id', $teacher->id)->sole();
    }

    /**
     * Un grupo del departamento en el campus, con una materia de `$theory` + `$practice` horas y sus sesiones: [día,
     * inicio, fin].
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $sessions
     * @param  array<string, mixed>  $attributes
     */
    public function group(string $code, array $sessions = [[1, '09:00', '10:00']], int $theory = 3, int $practice = 2, ?RequiredSpace $space = RequiredSpace::Aula, array $attributes = []): Group
    {
        $subject = Subject::factory()->create([
            'department_id' => $this->department->id, 'theory_hours' => $theory, 'practice_hours' => $practice, 'required_space' => $space,
        ]);
        $group = Group::factory()->create([
            'term_id' => $this->term->id,
            'campus_id' => $this->campus->id,
            'modality_id' => $this->selection->modality_id,
            'subject_id' => $subject->id,
            'department_id' => $this->department->id,
            'code' => $code,
            'capacity' => 30,
            ...$attributes,
        ]);

        foreach ($sessions as [$day, $startsAt, $endsAt]) {
            GroupSession::factory()->for($group)->on($day, $startsAt, $endsAt)->create();
        }

        return $group;
    }

    public function room(string $code, RoomType $type = RoomType::Aula, int $capacity = 35): Room
    {
        return Room::factory()->create(['building_id' => $this->building->id, 'code' => $code, 'type' => $type, 'capacity' => $capacity]);
    }
}
