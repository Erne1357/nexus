<?php

use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\ChangeRequests\ApplyChosenAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ChooseChangeAlternative;
use App\Modules\Staffing\Database\Seeders\ChangeRequestsDemoSeeder;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Jobs\ComputeChangeAlternatives;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeRequestMovedTeacherGroups;
use App\Modules\Staffing\Support\ChangeAlternatives;
use Database\Seeders\DemoSeeder;
use Database\Seeders\MassiveSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Staffing\ChangeRequestsWorld;

/*
| Las solicitudes de cambio de la fase 8 (8.6 y 8.7) en el punto `reanalisis-casos`: el paso de Staffing reabre a ajustes
| la carga que ReanalysisDemoSeeder reserva (CASE_CHANGE_REQUESTS) y deja ahí una solicitud en cada estado de la
| pantalla, por las Actions reales. Con el volumen demo no hay carga reservada (son 4 cargas), así que la prueba la fuerza
| como en el masivo: publica las cargas sin grupos de EE y MM y SC queda sexta, la reservada. Como con el masivo hay
| docentes con clases en los dos campus (lo que da el aviso de traslado, SC-09), un docente de SC da además una hora en
| Campus I en un grupo de otro departamento, sin choques. Corre la demo real hasta `reanalisis` y solo el paso nuevo.
|
| Este archivo: la solicitud en cada estado y la siembra mezclada. Las pruebas van en cuatro archivos (ChangeRequestsDemo*Test) porque cada una corre la demo hasta `reanalisis`: juntas tardaban unos 8 minutos en un proceso (docs/arquitectura/testing.md, «Shards»). Los atajos están en tests/Support/Staffing/ChangeRequestsWorld.php.
*/

it('deja en la carga reservada una solicitud de cambio en cada estado de la pantalla, con sus alternativas vigentes, y repetirlo no duplica nada', function () {
    $w = ChangeRequestsWorld::world();
    $request = $w->request;
    $status = fn (string $key): ?ChangeRequestStatus => $request($key)?->status;
    $alternatives = fn (string $key): ?ChangeAlternativesStatus => ChangeAlternativesStatus::of($request($key)->alternatives);

    expect(($w->cases)())->toBe(ChangeRequestsWorld::expectedCases())
        ->and($w->step->label)->toContain('Solicitudes de cambio')
        ->and(($w->check->passes)($w->demo))->toStartWith('SC en PRES: la carga está en Publicada, r1 sin pedir');

    ($w->run)();

    expect(($w->check->passes)($w->demo))->toBeTrue()
        ->and(($w->cases)())->toBe(ChangeRequestsWorld::expectedCases());

    // La carga: reabierta a ajustes por Subdirección, de vuelta a asignación al aplicar `y` y otra vez en ajustes al aprobar.
    expect($w->sc->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and($w->sc->transitions()->where('from_status', LoadStatus::Published->value)->where('to_status', LoadStatus::Adjusting->value)->value('comment'))->toBe(ChangeRequestsDemoSeeder::REOPEN_REASON)
        ->and($w->sc->transitions()->where('from_status', LoadStatus::Adjusting->value)->where('to_status', LoadStatus::Staffing->value)->exists())->toBeTrue();

    // Las decididas llevan el comentario del jefe.
    foreach (ChangeRequestsDemoSeeder::SCENARIOS as $key => $scenario) {
        if ($scenario['comment'] !== null && $status($key) !== ChangeRequestStatus::Pending) {
            expect($request($key)->decision_comment)->toBe($scenario['comment'], "Comentario de {$key}");
        }
    }

    // Pendientes sin alternativas: sin choques, con solo el aviso de traslado, con choque duro sin pedir y solo texto.
    foreach (['p1', 'p2'] as $key) {
        expect($request($key)->impact)->toMatchArray(['assessable' => true, 'hard' => [], 'soft' => []]);
    }

    expect(collect(['p7', 'p8', 'p9'])->map(fn (string $key): bool => $request($key)->impact['assessable'])->all())->toBe([false, false, false])
        ->and($request('p9')->teacher_id)->toBeNull();

    // El motor: sin otro acomodo (el docente solo está disponible a sus horas de clase), en la cola, empezado y fallido.
    $locked = TeacherAvailability::query()->where('teacher_id', $request('n')->group->teacher_id)->where('kind', AvailabilityKind::Unavailable->value)->count();

    expect($alternatives('n'))->toBe(ChangeAlternativesStatus::None)
        ->and(collect($request('n')->impact['hard'])->pluck('rule')->all())->toContain('HC-09')
        ->and($locked)->toBeGreaterThan(0)
        ->and(ChangeAlternativesStatus::isQueued($request('q')->alternatives))->toBeTrue()
        ->and($alternatives('s'))->toBe(ChangeAlternativesStatus::Computing)
        ->and(ChangeAlternativesStatus::isQueued($request('s')->alternatives))->toBeFalse()
        ->and($alternatives('f'))->toBe(ChangeAlternativesStatus::Failed)
        ->and($request('f')->alternatives['message'])->toBe(ComputeChangeAlternatives::FAILED_MESSAGE);

    // Pasado el plazo, la empezada y la de la cola se ven colgadas.
    $this->travel(ComputeChangeAlternatives::QUEUE_STALE_AFTER + 60)->seconds();
    expect(ChangeAlternativesStatus::messageOf($request('s')->alternatives))->toBe(ChangeAlternativesStatus::STALE_MESSAGE)
        ->and(ChangeAlternativesStatus::messageOf($request('q')->alternatives))->toBe(ChangeAlternativesStatus::STALE_MESSAGE);
    $this->travelBack();

    // `x` chocaba con el grupo de `y`: todas sus alternativas vencieron, con la revisión barata y con la pesada, así que la
    // que elija el jefe dice «Esto cambió…»; `z` y `p4` siguen vigentes también con la revisión pesada de la pantalla.
    $choose = app(ChooseChangeAlternative::class);

    expect(collect($request('x')->impact['affected_groups'])->pluck('id')->all())->toContain($request('y')->group_id)
        ->and(collect($request('x')->alternatives['items'])->every(fn (array $item): bool => ! $choose->sessionsUnchanged($item) && ! $choose->isCurrent($request('x'), $item)))->toBeTrue()
        ->and($choose->isCurrent($request('z'), ChangeAlternatives::chosen($request('z')->alternatives)))->toBeTrue()
        ->and(collect($request('p4')->alternatives['items'])->every(fn (array $item): bool => $choose->isCurrent($request('p4'), $item)))->toBeTrue();

    // Aplicada con alternativa y acomodada a mano: el horario de sus grupos es el de la elegida; la aplicada avisa.
    $sessions = fn (string $groupId): array => GroupSession::query()->where('group_id', $groupId)->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (GroupSession $session): string => $session->day.' '.substr((string) $session->starts_at, 0, 5))->all();

    foreach (['y', 'm'] as $key) {
        foreach (ChangeAlternatives::chosen($request($key)->alternatives)['groups'] as $entry) {
            expect($sessions($entry['group_id']))->toBe(collect($entry['sessions'])->sortBy([['day', 'asc'], ['starts_at', 'asc']])
                ->map(fn (array $session): string => $session['day'].' '.substr($session['starts_at'], 0, 5))->values()->all(), "Sesiones de {$key}");
        }
    }

    expect(DB::table('notifications')->where('type', ChangeRequestMovedTeacherGroups::class)->count())->toBeGreaterThan(0)
        ->and($sessions($request('ap1')->group_id))->toBe(collect($request('ap1')->proposed['sessions'])->sortBy([['day', 'asc'], ['starts_at', 'asc']])
        ->map(fn (array $session): string => $session['day'].' '.$session['starts_at'])->values()->all());

    ChangeRequestsWorld::expectSettled($request, $w->term);

    $counts = fn (): array => [
        ChangeRequest::query()->count(), ChangeRequest::query()->where('status', ChangeRequestStatus::Approved)->count(),
        DB::table('load_transitions')->count(), GroupSession::query()->count(), DB::table('notifications')->count(),
        DB::table('activity_log')->count(), TeacherAvailability::query()->count(),
    ];
    $before = $counts();

    // Una segunda corrida, con los eventos de modelo encendidos, no escribe nada.
    Event::fakeFor(fn () => ($w->step->run)($w->demo), $w->plan->quietEvents());

    expect($counts())->toBe($before)
        ->and(($w->check->passes)($w->demo))->toBeTrue();

    // En el recorrido, la coordinación aplica la alternativa de `z` sin «Esto cambió…».
    $z = $request('z');
    $coordinator = app(RecipientsQuery::class)->holders(StaffingPermission::ChangeRequestApplySchedule, Target::of(campus: $z->group->campus_id, career: $z->group->owner_career_id, modality: $z->modality_id))[0]['user'];
    $applied = app(ApplyChosenAlternative::class)->handle($z, $coordinator);

    expect($applied->request->status)->toBe(ChangeRequestStatus::Applied);
});

it('DemoSeeder y MassiveSeeder siembran la pendiente con alternativas solo con demo.showcase', function () {
    $calls = new ArrayObject;
    app()->instance(ChangeRequestsDemoSeeder::class, new class($calls) extends Seeder
    {
        public function __construct(private ArrayObject $calls) {}

        public function run(string $term = '20271', string $modality = 'PRES', string $mode = 'cases'): void
        {
            $this->calls[] = $mode;
        }
    });

    app(DemoSeeder::class)->setContainer(app())->changeAlternatives();
    app(MassiveSeeder::class)->setContainer(app())->changeAlternatives();
    expect($calls->getArrayCopy())->toBe([]);

    config(['demo.showcase' => true]);
    app(DemoSeeder::class)->setContainer(app())->changeAlternatives();
    app(MassiveSeeder::class)->setContainer(app())->changeAlternatives();

    expect($calls->getArrayCopy())->toBe(['showcase', 'showcase']);
});
