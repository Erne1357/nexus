<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\CancelReanalysis;
use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReturnToStaffing;
use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\AcademicLoad\Notifications\ReanalysisAvailable;
use App\Modules\AcademicLoad\Queries\ReanalysisAvailabilityQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisChangesQuery;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Students\Actions\RefreshStudentProgress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| Punto `reanalisis-casos` de la demo (fase 8, #614): sobre lo que el punto 9 publicó en su versión 1, cada carga queda en
| un caso del re-análisis para que la pantalla del re-análisis, el tablero de versiones y la campana se vean llenos. Corre
| la demo por puntos real con el volumen demo (Campus II en pequeño, el de los browser tests): el caso cruza el kárdex, la
| proyección, el validador, el ranking y los salones, que un mundo de factories tendría que volver a armar. Los pasos se
| corren uno por uno, cayéndose a propósito a la mitad (una Action del contenedor que lanza), para ver que cada uno sigue
| desde donde se quedó.
*/

it('reparte las cargas entre los casos por clave de departamento y da otra vuelta si sobran', function () {
    $term = Term::factory()->create(['code' => '20271']);
    $modality = modalityOf('PRES');
    $load = fn (string $code, LoadStatus $status = LoadStatus::Published): AcademicLoad => AcademicLoad::factory()->create([
        'term_id' => $term->id, 'modality_id' => $modality->id, 'status' => $status,
        'department_id' => Department::factory()->create(['code' => $code])->id,
    ]);

    $codes = ['D09', 'D01', 'D02', 'D03', 'D04', 'D05', 'D06', 'D07', 'D08'];
    $loads = collect($codes)->mapWithKeys(fn (string $code): array => [$code => $load($code)]);
    $draft = $load('D00', LoadStatus::Draft);
    $plan = ReanalysisDemoSeeder::plan($term->id, $modality->id);

    expect($plan)->not->toHaveKey($draft->id)
        ->and(collect($codes)->sort()->values()->map(fn (string $code): string => $plan[$loads[$code]->id])->all())->toBe([
            ReanalysisDemoSeeder::CASE_CORRECTED, ReanalysisDemoSeeder::CASE_CHANGED, ReanalysisDemoSeeder::CASE_RESTARTED,
            ReanalysisDemoSeeder::CASE_AVAILABLE, ReanalysisDemoSeeder::CASE_REPUBLISHED, ReanalysisDemoSeeder::CASE_CHANGE_REQUESTS,
            ReanalysisDemoSeeder::CASE_CLOSED, ReanalysisDemoSeeder::CASE_UNCHANGED, ReanalysisDemoSeeder::CASE_AVAILABLE,
        ]);
});

it('no le da la corrección a una carga a la que le vetarían volver a asignación (solicitudes aprobadas sin aplicar)', function () {
    $term = Term::factory()->create(['code' => '20271']);
    $modality = modalityOf('PRES');
    $loads = collect(['D01', 'D02', 'D03'])->mapWithKeys(fn (string $code): array => [$code => AcademicLoad::factory()->create([
        'term_id' => $term->id, 'modality_id' => $modality->id, 'status' => LoadStatus::Published,
        'department_id' => Department::factory()->create(['code' => $code])->id,
    ])]);
    $group = Group::factory()->create([
        'term_id' => $term->id, 'modality_id' => $modality->id, 'department_id' => $loads['D01']->department_id,
        'subject_id' => Subject::factory()->create(['department_id' => $loads['D01']->department_id])->id,
    ]);
    ChangeRequest::factory()->forGroup($group)->approved()->create();

    $plan = ReanalysisDemoSeeder::plan($term->id, $modality->id);

    expect(implode(' ', ReanalysisDemoSeeder::returnVetoes($loads['D01'])))->toContain('1 solicitud de cambio aprobada sin aplicar')
        ->and(ReanalysisDemoSeeder::returnVetoes($loads['D02']))->toBe([])
        ->and($loads->map(fn (AcademicLoad $load): string => $plan[$load->id])->all())->toBe([
            'D01' => ReanalysisDemoSeeder::CASE_CHANGED,
            'D02' => ReanalysisDemoSeeder::CASE_CORRECTED,
            'D03' => ReanalysisDemoSeeder::CASE_RESTARTED,
        ]);
});

it('retoma cada paso que se cayó a la mitad, deja cada caso con sus revisiones en verde y repetirlo no duplica nada', function () {
    $failures = [];

    app(DemoRunner::class)->run(DemoCheckpoint::Reanalysis, DemoContext::for(DemoVolume::Demo), function (DemoCheckpoint $checkpoint, array $results) use (&$failures): void {
        foreach ($results as $result) {
            if (! $result['passed']) {
                $failures[] = "{$checkpoint->value}: {$result['label']} ({$result['reason']})";
            }
        }
    });

    expect($failures)->toBe([]);

    $this->actingAs(User::query()->where('username', 'admin')->sole());
    $demo = DemoContext::for(DemoVolume::Demo);
    $plan = app(DemoPlan::class);
    [$grades, $start, $changes, $republication] = $plan->stepsFor(DemoCheckpoint::ReanalysisCases);
    // Como DemoRunner: sin eventos de modelo y con los eventos silenciados de la demo.
    $run = fn (DemoStep $step) => Model::withoutEvents(fn () => Event::fakeFor(fn () => ($step->run)($demo), $plan->quietEvents()));
    $crash = fn (string $class) => app()->bind($class, fn () => throw new RuntimeException("caída en {$class}"));
    $recover = fn (string $class) => app()->offsetUnset($class);
    $load = fn (string $code): AcademicLoad => AcademicLoad::query()->with(['term', 'modality', 'department'])
        ->ofDepartment(Term::query()->where('code', '20271')->value('id'), modalityOf('PRES')->id, Department::query()->where('code', $code)->value('id'))->sole();

    // El lote quedó registrado pero la proyección no se recalculó: la siguiente corrida recalcula y no repite el lote.
    $crash(RefreshStudentProgress::class);
    expect(fn () => $run($grades))->toThrow(RuntimeException::class, 'caída');
    expect(ImportBatch::query()->count())->toBe(1)
        ->and(app(ReanalysisAvailabilityQuery::class)->isAvailable($load('SC')))->toBeFalse();
    $recover(RefreshStudentProgress::class);
    $run($grades);
    expect(ImportBatch::query()->count())->toBe(1)
        ->and(app(ReanalysisAvailabilityQuery::class)->isAvailable($load('SC')))->toBeTrue();

    // II inició y se cayó antes de cancelar: la siguiente corrida cancela y vuelve a iniciar, sin repetir los demás.
    $crash(CancelReanalysis::class);
    expect(fn () => $run($start))->toThrow(RuntimeException::class, 'caída');
    expect($load('II')->status)->toBe(LoadStatus::Reanalysis);
    $recover(CancelReanalysis::class);
    $run($start);
    expect($load('II')->transitions()->where('to_status', LoadStatus::Reanalysis->value)->count())->toBe(2)
        ->and($load('CB')->transitions()->where('to_status', LoadStatus::Reanalysis->value)->count())->toBe(1);

    $run($changes);

    // Cerró CB y CEA y se cayó antes de la devolución de CEA (RA-08).
    $crash(ReopenLoad::class);
    expect(fn () => $run($republication))->toThrow(RuntimeException::class, 'caída');
    expect($load('CEA')->status)->toBe(LoadStatus::Closed)
        ->and($load('CB')->status)->toBe(LoadStatus::Closed);
    $recover(ReopenLoad::class);

    // Devolvió CEA, publicó la v2 de CB, la reabrió para corregirla y se cayó antes de regresarla a asignación.
    $crash(ReturnToStaffing::class);
    expect(fn () => $run($republication))->toThrow(RuntimeException::class, 'caída');
    expect($load('CEA')->status)->toBe(LoadStatus::Reanalysis)
        ->and($load('CB')->status)->toBe(LoadStatus::Adjusting)
        ->and($load('CB')->current_version)->toBe(2);
    $recover(ReturnToStaffing::class);

    // Siguió con la corrección, la cerró y se cayó al publicar la v3.
    $publish = app(PublishLoad::class);
    app()->bind(PublishLoad::class, fn () => new class($publish)
    {
        public function __construct(private readonly PublishLoad $publish) {}

        public function handle(AcademicLoad $load, User $actor, ?string $comment = null): LoadSnapshot
        {
            return $load->current_version === 2 ? throw new RuntimeException('caída al publicar la versión 3') : $this->publish->handle($load, $actor, $comment);
        }
    });
    expect(fn () => $run($republication))->toThrow(RuntimeException::class, 'caída');
    expect($load('CB')->status)->toBe(LoadStatus::Closed)
        ->and($load('CB')->current_version)->toBe(2);
    $recover(PublishLoad::class);

    $run($republication);
    $failed = collect($plan->checksAt(DemoCheckpoint::ReanalysisCases))
        ->map(fn (DemoCheck $check): string|true => ($check->passes)($demo) === true ? true : $check->label.': '.($check->passes)($demo))
        ->reject(fn (string|true $result): bool => $result === true)->values()->all();

    expect($failed)->toBe([]);

    $term = Term::query()->where('code', '20271')->sole();
    $modality = modalityOf('PRES');
    $head = fn (string $code): User => User::query()->where('username', "jefe.{$code}")->sole();
    $availability = app(ReanalysisAvailabilityQuery::class);
    $versions = fn (AcademicLoad $load): array => $load->snapshots()->orderBy('version')->pluck('reason', 'version')->map(fn (SnapshotReason $reason): string => $reason->value)->all();

    expect(array_values(ReanalysisDemoSeeder::plan($term->id, $modality->id)))->toBe([
        ReanalysisDemoSeeder::CASE_CORRECTED, ReanalysisDemoSeeder::CASE_CHANGED, ReanalysisDemoSeeder::CASE_RESTARTED, ReanalysisDemoSeeder::CASE_AVAILABLE,
    ]);

    // SC: publicada en su versión 1, con el re-análisis habilitado, sin iniciar, y su jefe tiene el aviso.
    $sc = $load('SC');
    $progress = $availability->progress($sc);

    expect($sc->status)->toBe(LoadStatus::Published)
        ->and($sc->current_version)->toBe(1)
        ->and($availability->reasons($sc))->toBe([])
        ->and($progress['percent'])->toBeGreaterThanOrEqual(80)->toBeLessThanOrEqual(90)
        ->and($progress['graded'])->toBeLessThan($progress['total'])
        ->and($head('sc')->notifications()->where('type', ReanalysisAvailable::class)->count())->toBe(1);

    // II: canceló su re-análisis sin cambios y lo volvió a iniciar; sigue sin cambios, así que se puede cancelar.
    $ii = $load('II');

    expect($ii->status)->toBe(LoadStatus::Reanalysis)
        ->and($ii->transitions()->orderBy('created_at')->orderBy('id')->pluck('to_status')->map->value->slice(-3)->values()->all())->toBe(['reanalysis', 'published', 'reanalysis'])
        ->and($ii->transitions()->where('comment', CancelReanalysis::COMMENT)->count())->toBe(1)
        ->and(app(ReanalysisChangesQuery::class)->hasChanges($ii))->toBeFalse();

    // CEA: en re-análisis con cambios, cerrada y devuelta por Subdirección (RA-08); ya no se cancela.
    $cea = $load('CEA');

    expect($cea->status)->toBe(LoadStatus::Reanalysis)
        ->and($cea->current_version)->toBe(1)
        ->and(app(ReanalysisChangesQuery::class)->changedGroups($cea))->toBeGreaterThan(3)
        ->and($cea->transitions()->where('from_status', LoadStatus::Closed->value)->where('to_status', LoadStatus::Reanalysis->value)->count())->toBe(1)
        ->and(fn () => app(CancelReanalysis::class)->handle($cea, $head('cea')))->toThrow(ValidationException::class, 'Ya cambiaste');

    // CB: versión 2 por re-análisis con cada tipo de cambio, versión 3 por corrección, y sin re-análisis habilitado.
    $cb = $load('CB');
    $diff = LoadSnapshot::query()->where('academic_load_id', $cb->id)->where('version', 2)->sole()->diff;
    $kinds = collect($diff['groups']['changed'])->flatMap(fn (array $changed): array => array_keys($changed['changes']))->unique()->sort()->values()->all();

    expect($cb->status)->toBe(LoadStatus::Published)
        ->and($versions($cb))->toBe([1 => 'published', 2 => 'reanalysis', 3 => 'correction'])
        ->and($diff['summary']['added'])->toBeGreaterThan(0)
        ->and($diff['summary']['cancelled'])->toBeGreaterThan(0)
        ->and($diff['summary']['departments_affected'])->toBeGreaterThan(0)
        ->and($kinds)->toBe(['career_quotas', 'quota', 'room', 'schedule', 'teacher'])
        ->and($availability->reasons($cb))->toBe(['No se ha importado un kárdex después de la versión 3 de la carga.']);

    $notices = fn (?Role $role): int => DB::table('notifications')->where('type', LoadRepublished::class)
        ->whereRaw($role === null ? "data::jsonb ->> 'role' is null" : "data::jsonb ->> 'role' = '{$role->value}'")->count();

    expect($notices(null))->toBeGreaterThan(0)
        ->and($notices(Role::Coordinador))->toBeGreaterThan(0)
        ->and($notices(Role::JefeDepartamento))->toBeGreaterThan(0);

    $counts = fn (): array => [
        LoadSnapshot::query()->count(), LoadTransition::query()->count(), ImportBatch::query()->count(),
        DB::table('notifications')->count(), Group::query()->count(), GroupTeacherAssignment::query()->count(),
        DB::table('activity_log')->count(),
    ];
    $before = $counts();

    // Una segunda corrida completa, con los eventos de modelo encendidos, no escribe nada.
    foreach ([$grades, $start, $changes, $republication] as $step) {
        Event::fakeFor(fn () => ($step->run)($demo), $plan->quietEvents());
    }

    expect($counts())->toBe($before);
});
