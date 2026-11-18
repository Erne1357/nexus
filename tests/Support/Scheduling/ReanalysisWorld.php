<?php

namespace Tests\Support\Scheduling;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Actions\Transitions\TransitionLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/**
 * Una carga presencial de Ciencias Básicas en Ago-Dic 2027 (20273) con grupos reales en Campus I y Campus II, para las
 * pruebas de «Re-análisis de la carga» (tarea 8.2, pantalla). Los grupos son completos (docente propio, salón propio, el
 * lunes de 7:00 a 9:00) para que la carga se cierre y se publique con PublishLoad: así la foto publicada es igual al estado
 * de los grupos y cancelar sin cambios funciona (RA-10). Nombres y claves ficticios.
 *
 * - published(): cierra y publica la versión 1 (hace 5 días), con la proyección de cada carrera y materia.
 * - gradesArrived(): lo que habilita el re-análisis (RA-02): un lote de kárdex de hace 2 días, una calificación de
 *   Ene-Jun 2027 (20271) de un alumno activo de Presencial y la proyección recalculada hace un día.
 * - inReanalysis(): la deja en re-análisis, como StartReanalysis, sin pasar por su guarda.
 *
 * El diff: Cálculo Integral en C2 con ISIC faltante (58 esperados, 40 lugares) e IIND ajustada y marcada para revisar (31
 * contra 30); Cálculo Integral en C1 con ISIC sobrante (30 contra 45); y Cálculo Diferencial en C2, de primer semestre,
 * con 30 de nuevo ingreso para ISIC (RA-09).
 */
final class ReanalysisWorld
{
    private int $sequence = 0;

    /** @var array<string, StudyPlan> el plan vigente de cada carrera, por id */
    private array $plans = [];

    private function __construct(
        public readonly Campus $c1,
        public readonly Campus $c2,
        public readonly Modality $modality,
        public readonly Term $term,
        public readonly Department $department,
        public readonly AcademicLoad $load,
        public readonly Career $isc,
        public readonly Career $iind,
        public readonly Subject $integral,
        public readonly Subject $differential,
        public readonly User $admin,
    ) {}

    public static function create(): self
    {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
        $modality = Modality::query()->where('code', 'PRES')->sole();
        $term = Term::factory()->create(['code' => '20273', 'name' => 'Ago-Dic 2027', 'starts_on' => '2027-08-16', 'ends_on' => '2027-12-10', 'is_current' => true]);
        Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11', 'is_current' => false]);
        $department = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
        $load = AcademicLoad::factory()->inStatus(LoadStatus::Closed)->create(['term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $department->id]);

        $world = new self(
            c1: $c1,
            c2: $c2,
            modality: $modality,
            term: $term,
            department: $department,
            load: $load,
            isc: Career::factory()->create(['code' => 'ISIC', 'name' => 'Ingeniería en Sistemas Computacionales']),
            iind: Career::factory()->create(['code' => 'IIND', 'name' => 'Ingeniería Industrial']),
            integral: Subject::factory()->create(['code' => 'ACF-0902', 'name' => 'Cálculo Integral', 'department_id' => $department->id, 'theory_hours' => 2, 'practice_hours' => 0]),
            differential: Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $department->id, 'theory_hours' => 2, 'practice_hours' => 0]),
            admin: User::factory()->admin()->create(),
        );

        foreach ([$world->isc, $world->iind] as $career) {
            $world->plans[$career->id] = StudyPlan::factory()->for($career)->create(['is_current' => true]);
            placeInPlan($world->plans[$career->id], $world->integral, 2);
            placeInPlan($world->plans[$career->id], $world->differential, 1);
        }

        $world->groups = [
            'A' => $world->group($world->integral, $world->isc, 40, $c2, 2),
            'B' => $world->group($world->integral, $world->iind, 30, $c2, 2),
            'C' => $world->group($world->differential, $world->isc, 35, $c2, 1),
            'D' => $world->group($world->integral, $world->isc, 45, $c1, 2),
        ];

        return $world;
    }

    /** @var array<string, Group> los grupos por letra */
    public array $groups = [];

    /**
     * Un grupo completo de la carga con el cupo de su carrera dueña, el lunes de 7:00 a 9:00 si no se dice otro día y
     * otra hora.
     */
    public function group(Subject $subject, Career $career, int $seats, Campus $campus, int $semester, int $day = 1, string $startsAt = '07:00', string $endsAt = '09:00'): Group
    {
        $group = Group::factory()->withQuota($seats)->create([
            'term_id' => $this->term->id,
            'campus_id' => $campus->id,
            'modality_id' => $this->modality->id,
            'subject_id' => $subject->id,
            'owner_career_id' => $career->id,
            'study_plan_id' => $this->plans[$career->id]->id,
            'semester' => $semester,
            'code' => chr(65 + $this->sequence++),
            'capacity' => $seats,
            'teacher_id' => $this->teacher()->id,
        ]);
        CareerCampus::query()->firstOrCreate(['career_id' => $career->id, 'campus_id' => $campus->id], ['is_active' => true]);
        $room = Room::factory()->capacity(50)->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id]);
        GroupSession::factory()->for($group)->inRoom($room)->on($day, $startsAt, $endsAt)->create();

        return $group;
    }

    /**
     * Cierra (ya está cerrada) y publica la versión 1, fechada hace 5 días, con la proyección de cada carrera y materia.
     */
    public function published(): self
    {
        app(PublishLoad::class)->handle($this->load->fresh(), $this->admin, 'Carga lista');
        $this->load->refresh()->update(['published_at' => now()->subDays(5)]);

        $this->forecast($this->integral, $this->isc, $this->c2, 58);
        $this->forecast($this->integral, $this->iind, $this->c2, 31, ['needs_review' => true]);
        $this->forecast($this->integral, $this->isc, $this->c1, 30);
        DemandForecast::factory()->firstSemester(30)->create([...$this->forecastKey($this->differential, $this->isc, $this->c2), 'expected_students' => 5, 'computed_at' => now()->subDays(6)]);

        return $this;
    }

    /**
     * Lo que habilita el re-análisis (RA-02): el lote de kárdex, una calificación del semestre actual y la proyección
     * recalculada después del lote.
     */
    public function gradesArrived(bool $forecastRecomputed = true): self
    {
        $student = Student::factory()->inModality($this->modality)->create();
        KardexRecord::factory()->create(['student_id' => $student->id, 'subject_id' => Subject::factory()->create()->id, 'term_code' => '20271']);
        KardexRecord::factory()->inProgress()->create(['student_id' => $student->id, 'subject_id' => Subject::factory()->create()->id, 'term_code' => '20271']);
        ImportBatch::factory()->create(['template' => 'kardex', 'status' => BatchStatus::Applied, 'applied_at' => now()->subDays(2)]);
        DemandForecast::query()->where('term_id', $this->term->id)->update(['computed_at' => $forecastRecomputed ? now()->subDay() : now()->subDays(3)]);

        return $this;
    }

    /**
     * La deja en re-análisis, como StartReanalysis (sin su guarda), iniciado por el jefe.
     */
    public function inReanalysis(User $by): self
    {
        app(TransitionLoad::class)->handle($this->load->fresh(), LoadStatus::Reanalysis, $by, 'Llegaron las calificaciones', fn (): array => []);
        $this->load->refresh();

        return $this;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function forecast(Subject $subject, Career $career, Campus $campus, int $expected, array $overrides = []): DemandForecast
    {
        return DemandForecast::factory()->create([
            ...$this->forecastKey($subject, $career, $campus),
            'expected_students' => $expected,
            'suggested_groups' => (int) ceil($expected / 35),
            'computed_at' => now()->subDays(6),
            ...$overrides,
        ]);
    }

    /**
     * El jefe del departamento (de todo el Tec).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function head(array $attributes = []): User
    {
        return User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->department->id])->create(['name' => 'Sergio Valdez', ...$attributes]);
    }

    /**
     * El coordinador de una carrera en Presencial (sin campus: toda la modalidad).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function coordinator(Career $career, array $attributes = []): User
    {
        return User::factory()->withRole(Role::Coordinador, ['career_id' => $career->id])->create(['name' => 'Alma Ruiz', ...$attributes]);
    }

    /**
     * @return array<string, string>
     */
    private function forecastKey(Subject $subject, Career $career, Campus $campus): array
    {
        return [
            'term_id' => $this->term->id,
            'modality_id' => $this->modality->id,
            'campus_id' => $campus->id,
            'career_id' => $career->id,
            'subject_id' => $subject->id,
        ];
    }

    private function teacher(): Teacher
    {
        $teacher = Teacher::factory()->create();
        TeacherPosition::factory()->for($teacher)->asignatura(20)->create();

        return $teacher;
    }
}
