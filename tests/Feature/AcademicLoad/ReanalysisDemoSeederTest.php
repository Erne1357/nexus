<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\CancelReanalysis;
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
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| Punto `reanalisis-casos` de la demo (fase 8, #614): sobre lo que el punto 9 publicó en su versión 1, cada carga queda en
| un caso del re-análisis para que la pantalla del re-análisis, el tablero de versiones y la campana se vean llenos. Corre
| la demo por puntos real con el volumen demo (Campus II en pequeño, el de los browser tests): el caso cruza el kárdex, la
| proyección, el validador, el ranking y los salones, que un mundo de factories tendría que volver a armar.
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
            ReanalysisDemoSeeder::CASE_AVAILABLE, ReanalysisDemoSeeder::CASE_REPUBLISHED, ReanalysisDemoSeeder::CASE_CLOSED,
            ReanalysisDemoSeeder::CASE_UNCHANGED, ReanalysisDemoSeeder::CASE_AVAILABLE, ReanalysisDemoSeeder::CASE_CHANGED,
        ]);
});

it('deja cada caso del re-análisis con sus revisiones en verde, y repetir los pasos no duplica nada', function () {
    $failures = [];

    app(DemoRunner::class)->run(DemoCheckpoint::ReanalysisCases, DemoContext::for(DemoVolume::Demo), function (DemoCheckpoint $checkpoint, array $results) use (&$failures): void {
        foreach ($results as $result) {
            if (! $result['passed']) {
                $failures[] = "{$checkpoint->value}: {$result['label']} ({$result['reason']})";
            }
        }
    });

    expect($failures)->toBe([]);

    $term = Term::query()->where('code', '20271')->sole();
    $modality = modalityOf('PRES');
    $load = fn (string $code): AcademicLoad => AcademicLoad::query()->with(['term', 'modality', 'department'])
        ->ofDepartment($term->id, $modality->id, Department::query()->where('code', $code)->value('id'))->sole();
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
    ];
    $before = $counts();
    $demo = DemoContext::for(DemoVolume::Demo);

    Model::withoutEvents(fn () => collect(app(DemoPlan::class)->stepsFor(DemoCheckpoint::ReanalysisCases))->each(fn (DemoStep $step) => ($step->run)($demo)));

    expect($counts())->toBe($before);
});
