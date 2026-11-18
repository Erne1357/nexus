<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Database\Seeders\ProposalForecastDemoSeeder;
use App\Modules\AcademicLoad\Enums\ForecastReason;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\ProposalForecastQuery;
use App\Modules\AcademicLoad\Queries\ProposalForecastReview;
use App\Modules\AcademicLoad\Queries\ProposalOverview;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoReport;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use Spatie\Activitylog\Models\Activity;

/*
| La demo de la propuesta precargada con todos los casos (#520): el paso de AcademicLoad que lleva a «propuesta» arma, con las
| Actions reales y como cada usuario, propuestas en cada estado del tablero, correcciones de todo tipo, filas cambiadas por un
| recálculo de la proyección y resueltas o revisadas, paquetes y nuevo ingreso. Con el volumen demo (Campus II): COPU es la
| oferta nueva de Demand en Campus I, que se queda en borrador; de las demás, IGEM regresada, IIND enviada con una parte
| aceptada, ILOG en borrador sin tocar, ISIC en borrador con correcciones y LADM sin propuesta.
*/

function proposalDemoUpTo(): DemoReport
{
    return app(DemoRunner::class)->run(DemoCheckpoint::Proposal, DemoContext::for(DemoVolume::Demo));
}

function proposalDemoOf(string $careerCode): ?CareerProposal
{
    return CareerProposal::query()->whereHas('career', fn ($career) => $career->where('code', $careerCode))->first();
}

function proposalDemoReview(CareerProposal $proposal): ProposalForecastReview
{
    return app(ProposalForecastQuery::class)->review(app(CareerProposalQuery::class)->overview($proposal, withPendingCatalogs: false), DemandSettings::fromSettings());
}

it('deja cada caso en «propuesta»: estados del tablero, la propuesta sin tocar, correcciones y paquetes', function () {
    $report = proposalDemoUpTo();
    $settings = DemandSettings::fromSettings();
    $c1 = Campus::query()->where('code', 'C1')->sole();

    $failed = array_filter($report->checks, fn (array $check): bool => ! $check['passed']);
    $statuses = Career::query()->orderBy('code')->where('is_active', true)->get()
        ->mapWithKeys(fn (Career $career): array => [$career->code => proposalDemoOf($career->code)?->status->value ?? 'sin propuesta'])->all();

    expect($failed)->toBe([])
        ->and($statuses)->toBe(['COPU' => 'draft', 'IGEM' => 'returned', 'IIND' => 'submitted', 'ILOG' => 'draft', 'ISIC' => 'draft', 'LADM' => 'sin propuesta'])
        ->and(ProposalReview::query()->where('career_proposal_id', proposalDemoOf('IGEM')->id)->pluck('status')->map->value->countBy()->all())
        ->toMatchArray(['accepted' => 1, 'returned' => 1])
        ->and(ProposalReview::query()->where('career_proposal_id', proposalDemoOf('IIND')->id)->where('status', ProposalReviewStatus::Accepted)->count())->toBe(1);

    // Sin tocar: como la armó Nexus, sin correcciones, notas, revisiones ni paquetes.
    $untouched = proposalDemoOf('ILOG');

    expect(ProposalItem::query()->where('career_proposal_id', $untouched->id)->where(fn ($query) => $query->whereNotNull('captured_at')->orWhereNotNull('notes')->orWhereNotNull('reviewed_at'))->count())->toBe(0)
        ->and(ProposalPackage::query()->where('career_proposal_id', $untouched->id)->exists())->toBeFalse()
        ->and(ProposalIntake::query()->where('career_proposal_id', $untouched->id)->whereNotNull('captured_at')->exists())->toBeFalse();

    // Correcciones chicas y grandes, una sugerida en 0 grupos, una que Nexus no sugería y notas.
    $rows = ProposalItem::query()->where('career_proposal_id', proposalDemoOf('ISIC')->id)->get();
    $captured = $rows->whereNotNull('captured_at');

    expect($captured->contains(fn (ProposalItem $item): bool => $item->requested_groups === $item->suggested_groups && ! $settings->isLargeDifference($item->suggested_expected_students, $item->expected_students)))->toBeTrue()
        ->and($captured->contains(fn (ProposalItem $item): bool => $item->requested_groups === $item->suggested_groups && $settings->isLargeDifference($item->suggested_expected_students, $item->expected_students)))->toBeTrue()
        ->and($captured->contains(fn (ProposalItem $item): bool => $item->suggested_groups > 0 && $item->requested_groups === 0))->toBeTrue()
        ->and($captured->contains(fn (ProposalItem $item): bool => $item->suggested_groups === 0 && $item->requested_groups > 0))->toBeTrue()
        ->and($captured->contains(fn (ProposalItem $item): bool => $item->package_groups === 0 && $item->requested_groups >= $item->suggested_groups + 2))->toBeTrue()
        ->and($rows->contains(fn (ProposalItem $item): bool => $item->captured_at === null && $item->notes !== null))->toBeTrue();

    // Paquetes: P1 con 1.º, P2 con una de 2.º, un nuevo ingreso corregido, lugares que faltan y la oferta nueva de 0 a 35.
    $firstOffering = ProposalIntake::query()->where('career_proposal_id', proposalDemoOf('COPU')->id)->where('campus_id', $c1->id)->sole();
    $packages = ProposalPackage::query()->where('career_proposal_id', proposalDemoOf('ISIC')->id)->with('subjects')->orderBy('code')->get();
    $semesters = fn (ProposalPackage $package): array => ProposalItem::query()->where('career_proposal_id', $package->career_proposal_id)
        ->whereIn('subject_id', $package->subjects->pluck('subject_id'))->distinct()->orderBy('semester')->pluck('semester')->all();
    $short = ProposalPackageCampus::query()->whereIn('proposal_package_id', ProposalPackage::query()->where('career_proposal_id', proposalDemoOf('IIND')->id)->select('id'))->sole();

    expect($packages->pluck('code')->all())->toBe(['P1', 'P2'])
        ->and($semesters($packages[0]))->toBe([1])
        ->and($semesters($packages[1]))->toBe([1, 2])
        ->and(ProposalIntake::query()->where('career_proposal_id', proposalDemoOf('IGEM')->id)->sole()->captured_at)->not->toBeNull()
        ->and($short->count)->toBeLessThan($short->suggested_count)
        ->and([$firstOffering->suggested_new_students, $firstOffering->new_students])->toBe([0, ProposalForecastDemoSeeder::FIRST_OFFERING_INTAKE])
        // Campus I está fuera de la demo: sus paquetes abren 0 y sus filas no piden grupos (M1).
        ->and((int) ProposalPackageCampus::query()->where('campus_id', $c1->id)->sum('count'))->toBe(0)
        ->and(ProposalItem::query()->where('campus_id', $c1->id)->where('requested_groups', '>', 0)->exists())->toBeFalse();
});

it('recalcula la proyección, resuelve y revisa como el coordinador de cada campus (M7), y volver a correrlo no cambia nada', function () {
    proposalDemoUpTo();
    $isc = proposalDemoOf('ISIC');
    $review = proposalDemoReview($isc);
    $keys = [ProposalOverview::columnKey(Campus::query()->where('code', 'C2')->sole())];
    $itemEdits = Activity::query()->where('subject_type', (new ProposalItem)->getMorphClass())->where('event', 'updated')->whereNotNull('causer_id')->get();
    // «Usar N» y «Mantener» guardan lo sugerido nuevo en la fila (ResolveForecastChange).
    $resolved = $itemEdits->filter(fn (Activity $edit): bool => array_key_exists('suggested_expected_students', $edit->attribute_changes['attributes'] ?? []));
    // M7 es de las correcciones de la fila; armar un paquete es de la carrera (M6) y mueve las filas de todos sus campus.
    $rowEdits = $itemEdits->reject(fn (Activity $edit): bool => str_contains($edit->description, 'por los paquetes'));

    expect(KardexRecord::query()->where('term_code', '20263')->where('result', KardexResult::Reprobada)->exists())->toBeTrue()
        ->and($review->pending($keys, ForecastReason::Changed))->not->toBeEmpty()
        ->and($review->reviewed($keys))->not->toBeEmpty()
        ->and($resolved)->not->toBeEmpty()
        ->and($rowEdits)->not->toBeEmpty()
        ->and($rowEdits->reject(function (Activity $edit): bool {
            $item = ProposalItem::query()->with('proposal')->findOrFail($edit->subject_id);

            return User::query()->findOrFail($edit->causer_id)->can('update', [$item->proposal, $item]);
        }))->toBeEmpty();

    $snapshot = fn (): array => [
        CareerProposal::query()->count(),
        ProposalPackage::query()->count(),
        ProposalReview::query()->count(),
        Activity::query()->count(),
        KardexRecord::query()->where('result', KardexResult::Cursando)->count(),
    ];
    $before = $snapshot();

    DemoContext::for(DemoVolume::Demo)->runSeeder(ProposalForecastDemoSeeder::class, ['campuses' => ['C2']]);

    expect($snapshot())->toBe($before);

    // Una corrida que solo completa lo que falta (aquí, la propuesta sin tocar) la arma sin volver a calificar el kárdex.
    $untouched = proposalDemoOf('ILOG');
    ProposalItem::query()->where('career_proposal_id', $untouched->id)->delete();
    ProposalIntake::query()->where('career_proposal_id', $untouched->id)->delete();
    $untouched->delete();
    $cursando = KardexRecord::query()->where('result', KardexResult::Cursando)->count();

    DemoContext::for(DemoVolume::Demo)->runSeeder(ProposalForecastDemoSeeder::class, ['campuses' => ['C2']]);

    expect(proposalDemoOf('ILOG'))->not->toBeNull()
        ->and(KardexRecord::query()->where('result', KardexResult::Cursando)->count())->toBe($cursando);
});

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new ProposalForecastDemoSeeder)->run())->toThrow(RuntimeException::class);
});
