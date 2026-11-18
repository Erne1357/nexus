<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\ApproveLoad;
use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Actions\Transitions\StartReanalysis;
use App\Modules\AcademicLoad\Actions\Transitions\TransitionLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Listeners\NotifyReanalysisAvailableOnForecastsComputed;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\AcademicLoad\Notifications\LoadReopened;
use App\Modules\AcademicLoad\Notifications\ReanalysisAvailable;
use App\Modules\AcademicLoad\Queries\ReanalysisAvailabilityQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Events\DemandForecastsComputed;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Tarea 8.1: el re-análisis de una carga publicada (RA-01 a RA-08). `published` → `reanalysis` (el jefe, solo con las
| calificaciones del semestre actual importadas y la proyección recalculada, RA-02) → `closed` → `approved` (si la
| modalidad exige el visto bueno, RA-03) → `published` con la versión n + 1. Devolver una carga re-analizada la regresa a
| `reanalysis` (RA-08). Mientras tanto la última versión publicada sigue siendo la pública.
*/

/**
 * Una carga publicada del periodo 20273 en la modalidad dada (la versión 1, hace 5 días) con todo lo que habilita el
 * re-análisis: un lote de kárdex aplicado hace 2 días, las calificaciones de 20271 de un alumno activo de la modalidad y
 * la proyección recalculada hace un día.
 *
 * @return array{load: AcademicLoad, student: Student, batch: ImportBatch, forecast: DemandForecast}
 */
function reanalysisScenario(?Modality $modality = null): array
{
    $modality ??= modalityOf('PRES');
    $term = Term::factory()->create(['code' => '20273']);
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create([
        'term_id' => $term->id,
        'modality_id' => $modality->id,
        'department_id' => departmentKey(10),
        'current_version' => 1,
        'published_at' => now()->subDays(5),
    ]);
    LoadSnapshot::factory()->for($load)->create(['version' => 1]);

    $student = Student::factory()->inModality($modality)->create();
    KardexRecord::factory()->create(['student_id' => $student->id, 'subject_id' => Subject::factory()->create()->id, 'term_code' => '20271']);
    $batch = ImportBatch::factory()->create(['template' => 'kardex', 'status' => BatchStatus::Applied, 'applied_at' => now()->subDays(2)]);
    $forecast = DemandForecast::factory()->create(['term_id' => $term->id, 'modality_id' => $modality->id, 'computed_at' => now()->subDay()]);

    return [
        'load' => $load,
        'student' => $student,
        'batch' => $batch,
        'forecast' => $forecast,
    ];
}

/**
 * El jefe del departamento 10 (solo hay uno por departamento): quien inicia el re-análisis.
 */
function reanalysisHead(): User
{
    return User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();
}

function approvalModality(): Modality
{
    return Modality::factory()->create(['settings' => [...ModalityProfile::DEFAULTS, 'requires_subdireccion_approval' => true]]);
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('RA-02: con el lote de kárdex, las calificaciones del periodo anterior y la proyección recalculada, el re-análisis está habilitado', function () {
    $s = reanalysisScenario();

    expect(app(ReanalysisAvailabilityQuery::class)->reasons($s['load']))->toBe([])
        ->and(app(ReanalysisAvailabilityQuery::class)->isAvailable($s['load']))->toBeTrue();
});

it('RA-02: con alumnos que todavía cursan, el re-análisis sigue habilitado: el jefe decide viendo el avance', function () {
    $s = reanalysisScenario();
    KardexRecord::factory()->inProgress()->create(['student_id' => $s['student']->id, 'subject_id' => Subject::factory()->create()->id, 'term_code' => '20271']);

    expect(app(ReanalysisAvailabilityQuery::class)->isAvailable($s['load']))->toBeTrue();
});

it('RA-02: el avance cuenta calificaciones (aprobadas y reprobadas) contra el total del periodo anterior, redondeando hacia abajo', function () {
    $s = reanalysisScenario();
    $other = fn (string $term = '20271') => ['student_id' => $s['student']->id, 'subject_id' => Subject::factory()->create()->id, 'term_code' => $term];
    KardexRecord::factory()->create([...$other(), 'result' => KardexResult::Reprobada]);
    KardexRecord::factory()->inProgress()->create($other());
    KardexRecord::factory()->create($other('20253'));

    expect(app(ReanalysisAvailabilityQuery::class)->progress($s['load']))->toBe(['graded' => 2, 'total' => 3, 'percent' => 66]);
});

it('RA-02: el avance es cero sin filas y no cuenta otra modalidad ni alumnos inactivos', function () {
    $s = reanalysisScenario();
    KardexRecord::query()->delete();

    expect(app(ReanalysisAvailabilityQuery::class)->progress($s['load']))->toBe(['graded' => 0, 'total' => 0, 'percent' => 0]);

    $foreign = Student::factory()->inModality(Modality::factory()->create())->create();
    $inactive = Student::factory()->inModality($s['load']->modality)->create(['is_active' => false]);
    foreach ([$foreign, $inactive] as $student) {
        KardexRecord::factory()->create(['student_id' => $student->id, 'subject_id' => Subject::factory()->create()->id, 'term_code' => '20271']);
    }

    expect(app(ReanalysisAvailabilityQuery::class)->progress($s['load']))->toBe(['graded' => 0, 'total' => 0, 'percent' => 0]);
});

it('RA-02: cada condición que falta lo deshabilita, con su motivo', function (Closure $break, string $reason) {
    $s = reanalysisScenario();
    $break($s);

    expect(implode(' | ', app(ReanalysisAvailabilityQuery::class)->reasons($s['load']->fresh())))->toContain($reason);
})->with([
    'no se ha importado kárdex después de la publicación' => [
        fn (array $s) => $s['batch']->update(['applied_at' => now()->subDays(6)]),
        'No se ha importado un kárdex después de la versión 1 de la carga.',
    ],
    'el lote es de otra plantilla' => [
        fn (array $s) => $s['batch']->update(['template' => 'students']),
        'No se ha importado un kárdex después de la versión 1 de la carga.',
    ],
    'el lote no se aplicó' => [
        fn (array $s) => $s['batch']->update(['status' => BatchStatus::Failed]),
        'No se ha importado un kárdex después de la versión 1 de la carga.',
    ],
    'no hay calificaciones del periodo anterior' => [
        fn (array $s) => KardexRecord::query()->where('term_code', '20271')->update(['term_code' => '20253']),
        'No hay calificaciones de 20271 en Presencial: importa las del semestre actual.',
    ],
    'la proyección no se recalculó después del lote' => [
        fn (array $s) => $s['forecast']->update(['computed_at' => now()->subDays(3)]),
        'La proyección de demanda todavía no se recalcula con el kárdex importado el',
    ],
    'una fila de la proyección quedó vieja' => [
        fn (array $s) => DemandForecast::factory()->create(['term_id' => $s['load']->term_id, 'modality_id' => $s['load']->modality_id, 'computed_at' => now()->subDays(3)]),
        'La proyección de demanda todavía no se recalcula con el kárdex importado el',
    ],
    'no hay proyección' => [
        fn (array $s) => DemandForecast::query()->delete(),
        'La proyección de demanda de 20273 en Presencial no se ha calculado.',
    ],
    'la carga no está publicada' => [
        fn (array $s) => $s['load']->update(['status' => LoadStatus::Closed]),
        'La carga está en Cerrada: solo una carga publicada pasa a re-análisis.',
    ],
]);

it('RA-02: no cuentan los alumnos de baja, de otra modalidad ni lo que cursan en otro periodo', function () {
    $s = reanalysisScenario();
    $baja = Student::factory()->inModality(modalityOf('PRES'))->create(['status' => StudentStatus::BajaTemporal]);
    $inactive = Student::factory()->inModality(modalityOf('PRES'))->inactive()->create();
    $other = Student::factory()->inModality(Modality::factory()->create())->create();
    $current = Student::factory()->inModality(modalityOf('PRES'))->create();

    foreach ([$baja, $inactive, $other] as $student) {
        KardexRecord::factory()->inProgress()->create(['student_id' => $student->id, 'subject_id' => Subject::factory()->create()->id, 'term_code' => '20271']);
    }

    KardexRecord::factory()->inProgress()->create(['student_id' => $current->id, 'subject_id' => Subject::factory()->create()->id, 'term_code' => '20273']);

    expect(app(ReanalysisAvailabilityQuery::class)->reasons($s['load']))->toBe([]);
});

it('RA-01: inicia el re-análisis, con su historial, su bitácora y el evento, sin tocar la versión publicada', function () {
    Event::fake([LoadTransitioned::class]);
    $s = reanalysisScenario();
    $s['jefe'] = reanalysisHead();

    app(StartReanalysis::class)->handle($s['load'], $s['jefe'], '  Ya llegaron las calificaciones  ');

    $load = $s['load']->fresh();
    expect($load->status)->toBe(LoadStatus::Reanalysis)
        ->and($load->current_version)->toBe(1)
        ->and(LoadSnapshot::query()->where('academic_load_id', $load->id)->count())->toBe(1)
        ->and(LoadSnapshot::query()->latestVersion()->where('academic_load_id', $load->id)->sole()->version)->toBe(1);

    $row = LoadTransition::query()->sole();
    expect($row->from_status)->toBe(LoadStatus::Published)
        ->and($row->to_status)->toBe(LoadStatus::Reanalysis)
        ->and($row->user_id)->toBe($s['jefe']->id)
        ->and($row->comment)->toBe('Ya llegaron las calificaciones');
    expect(Activity::query()->where('event', 'transitioned')->sole()->attribute_changes['attributes'])->toBe(['status' => 'reanalysis']);

    Event::assertDispatched(LoadTransitioned::class, fn (LoadTransitioned $event): bool => $event->from === LoadStatus::Published && $event->to === LoadStatus::Reanalysis);
});

it('RA-02: no inicia el re-análisis sin lo que lo habilita, y la carga sigue publicada', function () {
    $s = reanalysisScenario();
    $s['batch']->update(['applied_at' => now()->subDays(6)]);

    expect(fn () => app(StartReanalysis::class)->handle($s['load'], $this->admin))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['No se ha importado un kárdex después de la versión 1 de la carga.']),
    );

    expect($s['load']->fresh()->status)->toBe(LoadStatus::Published)
        ->and(LoadTransition::query()->count())->toBe(0);
});

it('RA-01: solo una carga publicada pasa a re-análisis: cerrada y aprobada, que el diagrama deja devolver, lo piden publicada', function (LoadStatus $status) {
    $s = reanalysisScenario();
    $s['load']->update(['status' => $status]);

    expect(fn () => app(StartReanalysis::class)->handle($s['load'], $this->admin))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(["La carga está en {$status->label()}: solo una carga publicada pasa a re-análisis."]),
    );

    expect($s['load']->fresh()->status)->toBe($status);
})->with([LoadStatus::Closed, LoadStatus::Approved]);

it('RA-01: una carga que todavía se está armando no pasa a re-análisis', function (LoadStatus $status) {
    $s = reanalysisScenario();
    $s['load']->update(['status' => $status]);

    expect(fn () => app(StartReanalysis::class)->handle($s['load'], $this->admin))->toThrow(ValidationException::class, "La carga está en {$status->label()}: no puede pasar a Re-análisis.");
})->with([LoadStatus::Draft, LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting]);

it('RA-01: el calendario no decide: se inicia sin ninguna etapa de re-análisis en el calendario del periodo', function () {
    $s = reanalysisScenario();

    app(StartReanalysis::class)->handle($s['load'], reanalysisHead());

    expect($s['load']->fresh()->status)->toBe(LoadStatus::Reanalysis);
});

it('RA-01: solo el jefe del departamento de la carga y el admin pueden iniciarlo', function (?Role $role, ?int $department, bool $expected) {
    $s = reanalysisScenario();
    $user = $role === null
        ? User::factory()->admin()->create()
        : User::factory()->withRole($role, array_filter([
            'department_id' => $department === null ? null : departmentKey($department),
            'career_id' => $role === Role::Coordinador ? careerKey(20) : null,
        ]))->create();

    expect($user->can('startReanalysis', $s['load']))->toBe($expected);
})->with([
    'admin' => [null, null, true],
    'jefe del departamento' => [Role::JefeDepartamento, 10, true],
    'jefe de otro departamento' => [Role::JefeDepartamento, 11, false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, 10, false],
    'subdirección (ve y devuelve, no inicia)' => [Role::Subdireccion, null, false],
    'coordinador (solo ve, RA-07)' => [Role::Coordinador, null, false],
    'docente' => [Role::Docente, null, false],
]);

it('RA-01: el jefe de otra modalidad no puede iniciar el re-análisis', function () {
    $s = reanalysisScenario();
    modalityOf('EAD')->update(['is_active' => true]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['modality_id' => modalityOf('EAD')->id, 'department_id' => departmentKey(10)])->create();

    expect($jefe->can('startReanalysis', $s['load']))->toBeFalse();
});

it('RA-01 y RA-08: Subdirección sí puede devolver la carga re-analizada (reopen)', function () {
    $s = reanalysisScenario();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    expect($subdireccion->can('reopen', $s['load']))->toBeTrue();
});

it('RA-03: sin visto bueno, el re-análisis se cierra y se publica como la versión 2, y la versión 1 se conserva', function () {
    $s = reanalysisScenario();
    $load = $s['load'];

    $jefe = reanalysisHead();

    app(StartReanalysis::class)->handle($load, $jefe);
    app(CloseLoad::class)->handle($load, $jefe);

    expect($load->fresh()->status)->toBe(LoadStatus::Closed)
        ->and(LoadSnapshot::query()->latestVersion()->where('academic_load_id', $load->id)->sole()->version)->toBe(1);

    app(PublishLoad::class)->handle($load, $jefe);

    expect($load->fresh()->status)->toBe(LoadStatus::Published)
        ->and($load->fresh()->current_version)->toBe(2)
        ->and(LoadSnapshot::query()->latestVersion()->where('academic_load_id', $load->id)->sole()->version)->toBe(2)
        ->and(LoadSnapshot::query()->where('academic_load_id', $load->id)->pluck('version')->sort()->values()->all())->toBe([1, 2]);

    $steps = LoadTransition::query()->orderBy('created_at')->orderBy('id')->get()->map(fn (LoadTransition $row): string => "{$row->from_status->value}>{$row->to_status->value}")->all();
    expect($steps)->toBe(['published>reanalysis', 'reanalysis>closed', 'closed>published']);
});

it('RA-03: con visto bueno, el re-análisis cerrado pasa por Subdirección y recién entonces se publica', function () {
    $s = reanalysisScenario(approvalModality());
    $load = $s['load'];
    $jefe = reanalysisHead();

    app(StartReanalysis::class)->handle($load, $jefe);
    app(CloseLoad::class)->handle($load, $jefe);

    expect(fn () => app(PublishLoad::class)->handle($load, $jefe))
        ->toThrow(ValidationException::class, 'La carga está en Cerrada: no puede pasar a Publicada.');

    app(ApproveLoad::class)->handle($load, $this->admin);
    app(PublishLoad::class)->handle($load, $this->admin);

    expect($load->fresh()->status)->toBe(LoadStatus::Published)
        ->and($load->fresh()->current_version)->toBe(2)
        ->and(LoadTransition::query()->orderBy('created_at')->orderBy('id')->get()->map(fn (LoadTransition $row): string => "{$row->from_status->value}>{$row->to_status->value}")->all())
        ->toBe(['published>reanalysis', 'reanalysis>closed', 'closed>approved', 'approved>published']);
});

it('RA-03: del re-análisis no se publica directo ni se salta el cierre', function (LoadStatus $to) {
    $s = reanalysisScenario();
    app(StartReanalysis::class)->handle($s['load'], $this->admin);

    expect(fn () => app(TransitionLoad::class)->handle($s['load'], $to, $this->admin, null, fn (): array => []))
        ->toThrow(ValidationException::class, "La carga está en Re-análisis: no puede pasar a {$to->label()}.");
})->with([LoadStatus::Published, LoadStatus::Approved, LoadStatus::Adjusting, LoadStatus::Staffing]);

it('RA-01: cerrar desde el re-análisis corre las mismas guardas de HC-15, y un grupo sin horario ni docente lo detiene', function () {
    $s = reanalysisScenario();
    $jefe = reanalysisHead();
    app(StartReanalysis::class)->handle($s['load'], $jefe);
    Group::factory()->create([
        'term_id' => $s['load']->term_id,
        'modality_id' => $s['load']->modality_id,
        'department_id' => $s['load']->department_id,
        'campus_id' => loadCampus()->id,
        'subject_id' => Subject::factory()->create(['department_id' => departmentKey(10)])->id,
    ]);

    expect(fn () => app(CloseLoad::class)->handle($s['load'], $jefe))->toThrow(ValidationException::class);

    expect($s['load']->fresh()->status)->toBe(LoadStatus::Reanalysis)
        ->and(LoadTransition::query()->count())->toBe(1);
});

it('RA-08: devolver una carga re-analizada, cerrada o aprobada, la regresa a re-análisis y no a ajustes, con su motivo', function (LoadStatus $state) {
    $s = reanalysisScenario(approvalModality());
    $load = $s['load'];
    app(StartReanalysis::class)->handle($load, $this->admin);
    app(CloseLoad::class)->handle($load, $this->admin);

    if ($state === LoadStatus::Approved) {
        app(ApproveLoad::class)->handle($load, $this->admin);
    }

    app(ReopenLoad::class)->handle($load, $this->admin, 'Falta cerrar el grupo de Cálculo');

    $row = LoadTransition::query()->orderByDesc('created_at')->orderByDesc('id')->first();
    expect($load->fresh()->status)->toBe(LoadStatus::Reanalysis)
        ->and($row->from_status)->toBe($state)
        ->and($row->to_status)->toBe(LoadStatus::Reanalysis)
        ->and($row->comment)->toBe('Falta cerrar el grupo de Cálculo')
        ->and($load->fresh()->current_version)->toBe(1)
        ->and(LoadSnapshot::query()->latestVersion()->where('academic_load_id', $load->id)->sole()->version)->toBe(1);

    // Y puede seguir otro ciclo: cerrar y publicar la versión 2.
    app(CloseLoad::class)->handle($load, $this->admin);
    app(ApproveLoad::class)->handle($load, $this->admin);
    app(PublishLoad::class)->handle($load, $this->admin);

    expect($load->fresh()->current_version)->toBe(2);
})->with([LoadStatus::Closed, LoadStatus::Approved]);

it('RA-08: la devolución de un cierre normal sigue yendo a ajustes, y una carga publicada también', function () {
    $normal = AcademicLoad::factory()->inStatus(LoadStatus::Closed)->create();
    $published = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create();

    app(ReopenLoad::class)->handle($normal, $this->admin, 'Corregir');
    app(ReopenLoad::class)->handle($published, $this->admin, 'Corregir');

    expect($normal->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and($published->fresh()->status)->toBe(LoadStatus::Adjusting);
});

it('RA-08: un cierre normal después de un re-análisis ya publicado vuelve a ajustes al devolverse', function () {
    $s = reanalysisScenario();
    $load = $s['load'];
    app(StartReanalysis::class)->handle($load, $this->admin);
    app(CloseLoad::class)->handle($load, $this->admin);
    app(PublishLoad::class)->handle($load, $this->admin);

    // Reabierta desde publicada (a ajustes), vuelve por asignación y se cierra de nuevo: su último cierre ya no es de re-análisis.
    app(ReopenLoad::class)->handle($load, $this->admin, 'Corrección');
    app(TransitionLoad::class)->handle($load, LoadStatus::Staffing, $this->admin, null, fn (): array => []);
    app(CloseLoad::class)->handle($load, $this->admin);
    app(ReopenLoad::class)->handle($load, $this->admin, 'Otra corrección');

    expect($load->fresh()->status)->toBe(LoadStatus::Adjusting);
});

it('RA-01: un re-análisis no se reabre: la devolución es solo desde cerrada o aprobada', function () {
    $s = reanalysisScenario();
    app(StartReanalysis::class)->handle($s['load'], $this->admin);

    expect(fn () => app(ReopenLoad::class)->handle($s['load'], $this->admin, 'Corregir'))->toThrow(ValidationException::class);
    expect($s['load']->fresh()->status)->toBe(LoadStatus::Reanalysis);
});

it('RA-01 y RA-02: al recalcularse la proyección, avisa por la campana al jefe de la carga que ya puede re-analizarse, y a nadie más', function () {
    Notification::fake();
    $s = reanalysisScenario();
    $jefe = reanalysisHead();
    $otherDepartment = departmentKey(11);
    AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['term_id' => $s['load']->term_id, 'modality_id' => $s['load']->modality_id, 'department_id' => $otherDepartment]);
    $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $otherDepartment])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['career_id' => careerKey(20)])->create();

    app(NotifyReanalysisAvailableOnForecastsComputed::class)->handle(new DemandForecastsComputed($s['load']->term_id, $s['load']->modality_id, null, []));

    Notification::assertSentTo($jefe, ReanalysisAvailable::class, fn (ReanalysisAvailable $notice): bool => $notice->message($jefe)->title === 'Ya puedes re-analizar la carga de '.$s['load']->department->name
        && $notice->message($jefe)->route === 'staffing.closing'
        && $notice->message($jefe)->modalityId === $s['load']->modality_id);
    Notification::assertNotSentTo([$otherHead, $subdireccion, $coordinador, $this->admin], ReanalysisAvailable::class);
    Notification::assertCount(1);
});

it('RA-02: no avisa si la carga no cumple la guarda (proyección vieja, otra modalidad, otro periodo o ya re-analizada)', function (Closure $break) {
    Notification::fake();
    $s = reanalysisScenario();
    $jefe = reanalysisHead();
    $break($s);

    app(NotifyReanalysisAvailableOnForecastsComputed::class)->handle(new DemandForecastsComputed($s['load']->term_id, $s['load']->modality_id, null, []));

    Notification::assertNotSentTo($jefe, ReanalysisAvailable::class);
})->with([
    'la proyección no se recalculó' => [fn (array $s) => $s['forecast']->update(['computed_at' => now()->subDays(3)])],
    'la carga se publicó después del lote' => [fn (array $s) => $s['load']->update(['published_at' => now()->subDay()])],
    'la carga ya está en re-análisis' => [fn (array $s) => $s['load']->update(['status' => LoadStatus::Reanalysis])],
]);

it('RA-02: el aviso de otra modalidad no llega al jefe de esta, y un recálculo de otra modalidad no avisa por esta', function () {
    Notification::fake();
    $s = reanalysisScenario();
    reanalysisHead();

    app(NotifyReanalysisAvailableOnForecastsComputed::class)->handle(new DemandForecastsComputed($s['load']->term_id, Modality::factory()->create()->id, null, []));

    Notification::assertNothingSent();
});

it('RA-02: no repite el aviso a quien ya lo recibió después del último kárdex, aunque la proyección se recalcule por campus', function () {
    $s = reanalysisScenario();
    $jefe = reanalysisHead();
    $event = new DemandForecastsComputed($s['load']->term_id, $s['load']->modality_id, null, []);

    app(NotifyReanalysisAvailableOnForecastsComputed::class)->handle($event);
    app(NotifyReanalysisAvailableOnForecastsComputed::class)->handle($event);

    expect($jefe->notifications()->where('type', ReanalysisAvailable::class)->count())->toBe(1);

    // Otro lote de kárdex, después de ese aviso, vuelve a habilitar y a avisar.
    ImportBatch::factory()->create(['template' => 'kardex', 'status' => BatchStatus::Applied, 'applied_at' => now()->addMinute()]);
    DemandForecast::query()->update(['computed_at' => now()->addMinutes(2)]);

    app(NotifyReanalysisAvailableOnForecastsComputed::class)->handle($event);

    expect($jefe->notifications()->where('type', ReanalysisAvailable::class)->count())->toBe(2);
});

it('RA-08: al devolver una carga re-analizada a re-análisis, la campana avisa al jefe con el motivo', function () {
    $s = reanalysisScenario();
    $jefe = reanalysisHead();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    app(StartReanalysis::class)->handle($s['load'], $jefe);
    app(CloseLoad::class)->handle($s['load'], $jefe);
    Notification::fake();

    app(ReopenLoad::class)->handle($s['load'], $subdireccion, 'Falta cerrar el grupo de Cálculo');

    Notification::assertSentTo($jefe, LoadReopened::class, fn (LoadReopened $notice): bool => $notice->message($jefe)->body === 'Falta cerrar el grupo de Cálculo');
    Notification::assertNotSentTo($subdireccion, LoadReopened::class);
});
