<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\StartCareerProposal;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\CareerProposalStarted;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| StartCareerProposal arma la propuesta de una carrera en un campus y un periodo (reglas-de-negocio.md,
| "Propuesta de carrera"): una fila por materia activa de la retícula de los planes activos (HC-13), con los
| elegibles del momento, esperados = elegibles y los grupos sugeridos (35, o 25 con laboratorio; confirmado el
| 2 de noviembre de 2026). Solo en un periodo abierto en el campus y para una carrera ofrecida en él, y una sola
| vez: después devuelve la que ya existe (supuestos por confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->term = Term::factory()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $this->career = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->campus->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2010-224']);
});

function startProposal(?Term $term = null, ?Campus $campus = null, ?Career $career = null): CareerProposal
{
    return app(StartCareerProposal::class)->handle(
        $term ?? test()->term,
        $campus ?? test()->campus,
        $career ?? test()->career,
        test()->actor,
    );
}

/**
 * `$count` alumnos activos del plan en el campus que ya pueden cursar `$subjects`.
 *
 * @param  list<Subject>  $subjects
 */
function eligibleInProposal(StudyPlan $plan, Campus $campus, array $subjects, int $count): void
{
    Student::factory()->count($count)->for($plan)->create(['campus_id' => $campus->id])
        ->each(fn (Student $student) => StudentProgress::factory()->for($student)->create([
            'subjects_available' => array_map(fn (Subject $subject): string => $subject->id, $subjects),
        ]));
}

/**
 * Las filas de la propuesta por clave de materia: [plan, semestre, elegibles, esperados, grupos].
 *
 * @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: int}>
 */
function proposalRowsOf(CareerProposal $proposal): array
{
    return $proposal->items()->with(['subject', 'studyPlan'])->get()
        ->sortBy(fn (ProposalItem $item): string => $item->subject->code)
        ->mapWithKeys(fn (ProposalItem $item): array => [$item->subject->code => [
            $item->studyPlan->code, $item->semester, $item->eligible_students, $item->expected_students, $item->requested_groups,
        ]])
        ->all();
}

it('arma la propuesta en borrador con una fila por materia, sus elegibles y la precarga 35 / 25', function () {
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    $redes = Subject::factory()->create(['code' => 'SCD-1015', 'needs_lab' => true]);
    $etica = Subject::factory()->create(['code' => 'ACA-0907']);
    placeInPlan($this->plan, $calculo, 1);
    placeInPlan($this->plan, $redes, 5);
    placeInPlan($this->plan, $etica, 2);
    eligibleInProposal($this->plan, $this->campus, [$calculo, $redes], 36);
    eligibleInProposal($this->plan, $this->campus, [$calculo], 35);

    $proposal = startProposal();

    expect($proposal->status)->toBe(ProposalStatus::Draft)
        ->and($proposal->term_id)->toBe($this->term->id)
        ->and($proposal->campus_id)->toBe($this->campus->id)
        ->and($proposal->career_id)->toBe($this->career->id)
        ->and(proposalRowsOf($proposal))->toBe([
            'ACA-0907' => ['ISIC-2010-224', 2, 0, 0, 0],
            'ACF-0901' => ['ISIC-2010-224', 1, 71, 71, 3],
            'SCD-1015' => ['ISIC-2010-224', 5, 36, 36, 2],
        ])
        ->and($proposal->eligible_students)->toBe(71);
});

it('HC-13: deja fuera las materias inactivas o borradas, las quitadas de la retícula y las de planes inactivos, borrados o de otra carrera', function () {
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'A-ACTIVA']));
    placeInPlan($this->plan, Subject::factory()->inactive()->create(['code' => 'B-INACTIVA']));
    $deleted = Subject::factory()->create(['code' => 'C-BORRADA']);
    placeInPlan($this->plan, $deleted);
    $deleted->delete();
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'D-QUITADA']))->delete();
    placeInPlan(StudyPlan::factory()->inactive()->create(['career_id' => $this->career->id]), Subject::factory()->create(['code' => 'E-PLAN-INACTIVO']));
    $deletedPlan = StudyPlan::factory()->create(['career_id' => $this->career->id]);
    placeInPlan($deletedPlan, Subject::factory()->create(['code' => 'F-PLAN-BORRADO']));
    $deletedPlan->delete();
    placeInPlan(StudyPlan::factory()->create(), Subject::factory()->create(['code' => 'G-OTRA-CARRERA']));

    expect(array_keys(proposalRowsOf(startProposal())))->toBe(['A-ACTIVA']);
});

it('deja fuera residencia, servicio social y actividades complementarias, y las materias sin horas de clase, tengan o no tipo', function () {
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'A-CLASE', 'kind' => SubjectKind::Basica, 'theory_hours' => 3, 'practice_hours' => 2]));
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'B-SIN-TIPO', 'kind' => null, 'theory_hours' => 2, 'practice_hours' => 0]));
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'RP', 'kind' => SubjectKind::Residencia, 'theory_hours' => 0, 'practice_hours' => 0]));
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'SS', 'kind' => SubjectKind::ServicioSocial, 'theory_hours' => 0, 'practice_hours' => 0]));
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'AC', 'kind' => SubjectKind::Complementaria, 'theory_hours' => 0, 'practice_hours' => 0]));
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'X-CON-TIPO-Y-HORAS', 'kind' => SubjectKind::Residencia, 'theory_hours' => 0, 'practice_hours' => 4]));
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'Z-SIN-HORAS', 'kind' => null, 'theory_hours' => 0, 'practice_hours' => 0]));
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'Z-CON-TIPO-SIN-HORAS', 'kind' => SubjectKind::Basica, 'theory_hours' => 0, 'practice_hours' => 0]));

    expect(array_keys(proposalRowsOf(startProposal())))->toBe(['A-CLASE', 'B-SIN-TIPO']);
});

it('una materia en dos planes activos es una sola fila, con el plan y el semestre del primer plan por clave', function () {
    $later = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2020-301']);
    $earlier = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2004-296']);
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->plan, $calculo, 2);
    placeInPlan($later, $calculo, 1);
    placeInPlan($earlier, $calculo, 3);

    expect(proposalRowsOf(startProposal()))->toBe(['ACF-0901' => ['ISIC-2004-296', 3, 0, 0, 0]]);
});

it('cuenta solo los elegibles de la carrera en el campus', function () {
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->plan, $calculo);
    eligibleInProposal($this->plan, $this->campus, [$calculo], 2);
    eligibleInProposal($this->plan, Campus::factory()->create(), [$calculo], 5);

    expect(proposalRowsOf(startProposal()))->toBe(['ACF-0901' => ['ISIC-2010-224', 1, 2, 2, 1]]);
});

it('precarga los grupos con el tamaño objetivo del parámetro global', function () {
    Setting::query()->create(['scope' => SettingScope::Global, 'key' => 'demand.target_group_size', 'value' => ['default' => 40, 'lab' => 20]]);
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    $redes = Subject::factory()->create(['code' => 'SCD-1015', 'needs_lab' => true]);
    placeInPlan($this->plan, $calculo);
    placeInPlan($this->plan, $redes);
    eligibleInProposal($this->plan, $this->campus, [$calculo, $redes], 41);

    expect(proposalRowsOf(startProposal()))->toBe([
        'ACF-0901' => ['ISIC-2010-224', 1, 41, 41, 2],
        'SCD-1015' => ['ISIC-2010-224', 1, 41, 41, 3],
    ]);
});

it('es idempotente: la segunda vez devuelve la misma propuesta sin tocar sus filas ni volver a contar', function () {
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->plan, $calculo);
    $first = startProposal();
    ProposalItem::query()->update(['requested_groups' => 4]);
    eligibleInProposal($this->plan, $this->campus, [$calculo], 3);
    placeInPlan($this->plan, Subject::factory()->create(['code' => 'ACA-0907']));
    Event::fake([CareerProposalStarted::class]);

    $second = startProposal();

    expect($second->id)->toBe($first->id)
        ->and(CareerProposal::query()->count())->toBe(1)
        ->and(proposalRowsOf($second))->toBe(['ACF-0901' => ['ISIC-2010-224', 1, 0, 0, 4]])
        ->and(Activity::query()->where('subject_id', $first->id)->count())->toBe(1);
    Event::assertNotDispatched(CareerProposalStarted::class);
});

it('devuelve la propuesta que ya existe aunque el periodo o la oferta ya no la dejarían armar', function () {
    $existing = CareerProposal::factory()->submitted()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'career_id' => $this->career->id]);
    CareerCampus::query()->update(['is_active' => false]);

    expect(startProposal()->id)->toBe($existing->id);
});

it('rechaza un periodo que no está abierto en el campus y no escribe nada', function () {
    $closed = Term::factory()->create(['code' => '20273']);
    TermOpening::factory()->create(['term_id' => $closed->id]);

    expect(fn () => startProposal(term: $closed))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toBe(['term' => ['El periodo 20273 no está abierto en C2.']]),
    );

    expect(CareerProposal::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

it('rechaza una carrera que no se ofrece en el campus y no escribe nada', function (Closure $offering) {
    $other = Career::factory()->create(['code' => 'IGE']);
    $offering($other, $this->campus);

    expect(fn () => startProposal(career: $other))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toBe(['career' => ['IGE no se ofrece en C2.']]),
    );

    expect(CareerProposal::query()->count())->toBe(0);
})->with([
    'sin oferta' => [fn () => fn (Career $career, Campus $campus) => null],
    'oferta inactiva' => [fn () => fn (Career $career, Campus $campus) => CareerCampus::factory()->inactive()->create(['career_id' => $career->id, 'campus_id' => $campus->id])],
    'oferta borrada' => [fn () => fn (Career $career, Campus $campus) => CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id])->delete()],
    'ofrecida en otro campus' => [fn () => fn (Career $career, Campus $campus) => CareerCampus::factory()->create(['career_id' => $career->id])],
]);

it('deja bitácora de la propuesta armada con quién la armó y sus alumnos posibles', function () {
    $calculo = Subject::factory()->create();
    placeInPlan($this->plan, $calculo);
    placeInPlan($this->plan, Subject::factory()->create());
    eligibleInProposal($this->plan, $this->campus, [$calculo], 3);

    $proposal = startProposal();

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($proposal->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('created')
        ->and($activity->attribute_changes['attributes'])->toBe([
            'term_id' => $this->term->id,
            'campus_id' => $this->campus->id,
            'career_id' => $this->career->id,
            'status' => 'draft',
            'eligible_students' => 3,
        ])
        ->and($activity->properties['items'])->toBe(2)
        ->and($activity->description)->toBe('Armó la propuesta de ISC en C2 para el periodo 20271');
});

it('avisa con CareerProposalStarted solo después del commit', function () {
    Event::fake([CareerProposalStarted::class]);

    $proposal = startProposal();

    Event::assertDispatched(CareerProposalStarted::class, fn (CareerProposalStarted $event): bool => $event->proposal->is($proposal));
});

it('no avisa si la transacción externa se revierte', function () {
    Event::fake([CareerProposalStarted::class]);

    try {
        DB::transaction(function () {
            startProposal();

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(CareerProposalStarted::class);
    expect(CareerProposal::query()->count())->toBe(0);
});

it('si otra sesión la arma entre la revisión y la escritura, devuelve la suya y no le agrega filas', function () {
    placeInPlan($this->plan, Subject::factory()->create());
    $rivalInserted = false;
    DB::listen(function (QueryExecuted $query) use (&$rivalInserted) {
        if (! $rivalInserted && str_contains($query->sql, 'career_campus')) {
            $rivalInserted = true;
            DB::table('career_proposals')->insert(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'career_id' => $this->career->id]);
        }
    });

    $proposal = startProposal();

    expect(CareerProposal::query()->count())->toBe(1)
        ->and($proposal->id)->toBe(CareerProposal::query()->sole()->id)
        ->and(ProposalItem::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});
