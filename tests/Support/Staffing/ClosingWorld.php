<?php

namespace Tests\Support\Staffing;

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Carbon\CarbonInterface;

/**
 * Un departamento con su carga presencial (M1: una por departamento y modalidad, con los grupos de todos sus campus) y
 * sus grupos en Campus II (y, si se pide, en otro campus) en el periodo actual, para las pruebas del cierre de la carga
 * (tareas 4.1 y 4.2): grupos completos por omisión (el lunes de 7:00 a 9:00, con su propio salón
 * y su propio docente, de una carrera propia ofrecida en el campus, así que no chocan entre sí), o sin horario, sin
 * docente o sin salón. Nombres y claves ficticios.
 */
final class ClosingWorld
{
    private int $sequence = 0;

    /** @var array<string, Campus> el campus de los grupos de cada carga pedida con loadIn(), por id de carga */
    private array $campusOfLoad = [];

    private function __construct(
        public readonly Campus $campus,
        public readonly Term $term,
        public readonly Department $department,
        public readonly AcademicLoad $load,
        public readonly Subject $subject,
    ) {}

    /**
     * @param  array<string, mixed>  $settings  el perfil de Presencial, la modalidad de la carga, sobre los valores de omisión
     */
    public static function create(LoadStatus $status = LoadStatus::Staffing, array $settings = []): self
    {
        $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
        $presencial = Modality::query()->where('code', 'PRES')->sole();

        if ($settings !== []) {
            $presencial->update(['settings' => [...ModalityProfile::DEFAULTS, ...array_intersect_key($settings, ModalityProfile::DEFAULTS)]]);
        }

        $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
        $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
        $load = AcademicLoad::factory()->inStatus($status)->create(['term_id' => $term->id, 'modality_id' => $presencial->id, 'department_id' => $department->id]);
        $subject = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación', 'department_id' => $department->id, 'theory_hours' => 2, 'practice_hours' => 0]);

        return new self($campus, $term, $department, $load, $subject);
    }

    /**
     * La carga del departamento (u otro) en la modalidad (la de la carga del mundo, Presencial, si no se da) y el mismo
     * periodo, cuyos grupos (group() con `load:`) van en ese campus: el campus es solo el lugar (MS3). Si ya existe (M1:
     * el mismo departamento en la misma modalidad es la misma carga), la misma, sin cambiar su estado.
     */
    public function loadIn(Campus $campus, LoadStatus $status = LoadStatus::Staffing, ?Department $department = null, ?Modality $modality = null): AcademicLoad
    {
        $departmentId = ($department ?? $this->department)->id;
        $modalityId = $modality?->id ?? $this->load->modality_id;
        $load = AcademicLoad::query()->ofDepartment($this->term->id, $modalityId, $departmentId)->first()
            ?? AcademicLoad::factory()->inStatus($status)->create(['term_id' => $this->term->id, 'modality_id' => $modalityId, 'department_id' => $departmentId]);
        $this->campusOfLoad[$load->id] = $campus;

        return $load;
    }

    /**
     * Un grupo activo de la carga (o de `$load`), de 2 h, el lunes de 7:00 a 9:00. Completo por omisión: con salón propio
     * y docente propio. `$teacher` false lo deja sin docente; un Teacher, con ese.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function group(array $attributes = [], ?AcademicLoad $load = null, bool $scheduled = true, bool $withRoom = true, bool|Teacher $teacher = true): Group
    {
        $campus = $load === null ? $this->campus : ($this->campusOfLoad[$load->id] ?? ($load->is($this->load) ? $this->campus : loadCampus()));
        $load ??= $this->load;
        $subject = $load->department_id === $this->department->id
            ? $this->subject
            : Subject::factory()->create(['department_id' => $load->department_id, 'theory_hours' => 2, 'practice_hours' => 0]);

        $group = Group::factory()->create([
            'term_id' => $load->term_id,
            'campus_id' => $campus->id,
            'modality_id' => $load->modality_id,
            'subject_id' => $subject->id,
            'code' => sprintf('%d%s', 1 + intdiv($this->sequence, 26), chr(65 + $this->sequence++ % 26)),
            'capacity' => 30,
            'teacher_id' => match (true) {
                $teacher === true => $this->teacher()->id,
                $teacher instanceof Teacher => $teacher->id,
                default => null,
            },
            ...$attributes,
        ]);
        CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $group->campus_id]);

        if ($scheduled) {
            $session = GroupSession::factory()->for($group)->on(1, '07:00', '09:00');
            ($withRoom ? $session->inRoom($this->room($group->campus_id)) : $session)->create();
        }

        return $group;
    }

    public function teacher(bool $active = true, ?string $name = null): Teacher
    {
        $teacher = ($active ? Teacher::factory() : Teacher::factory()->inactive())->create($name === null ? [] : ['name' => $name]);
        TeacherPosition::factory()->for($teacher)->asignatura(20)->create();

        return $teacher;
    }

    public function room(?string $campusId = null, int $capacity = 40): Room
    {
        return Room::factory()->capacity($capacity)->create(['building_id' => Building::factory()->create(['campus_id' => $campusId ?? $this->campus->id])->id]);
    }

    /**
     * Deja la justificación de HC-15 en el grupo, como JustifyGroup.
     */
    public function justify(Group $group, string $text, User $by, ?CarbonInterface $at = null): Group
    {
        $group->forceFill(['close_justification' => $text, 'close_justified_by' => $by->id, 'close_justified_at' => $at ?? now()])->save();

        return $group;
    }

    /**
     * Una fila del historial de la carga.
     */
    public function transition(LoadStatus $from, LoadStatus $to, User $by, ?string $comment = null, ?CarbonInterface $at = null, ?AcademicLoad $load = null): LoadTransition
    {
        return LoadTransition::factory()->create([
            'academic_load_id' => ($load ?? $this->load)->id,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $by->id,
            'comment' => $comment,
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * El jefe del departamento (de todo el Tec, como lo nombra el organigrama).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function head(array $attributes = []): User
    {
        return User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->department->id])->create(['name' => 'Laura Ramos', ...$attributes]);
    }
}
