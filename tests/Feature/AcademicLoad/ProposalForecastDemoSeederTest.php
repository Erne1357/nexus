<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\AcademicLoadServiceProvider;
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
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Demo\DemoReport;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Demo\DemoStep;
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
| recálculo de la proyección y resueltas o revisadas, paquetes y nuevo ingreso. Con el volumen demo (Campus II), en orden de
| clave: COPU regresada (CB aceptó, CEA regresó), IGEM enviada con una parte aceptada, IIND en borrador sin tocar, ILOG en
| borrador con correcciones y «Paquete 2», ISIC enviada y LADM sin propuesta. La oferta nueva de Demand (COPU en Campus I)
| cae fuera de la demo, así que no se usa: sus filas siguen sin grupos.
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

/**
 * El paso real de la demo, con los argumentos con que lo corre `demo:seed` (campus, periodo, semilla y modalidades del volumen).
 */
function proposalDemoStep(): void
{
    $step = collect(app(DemoPlan::class)->stepsFor(DemoCheckpoint::Proposal))->first(fn (DemoStep $step): bool => $step->label === 'Propuestas precargadas con todos los casos');

    ($step->run)(DemoContext::for(DemoVolume::Demo));
}

/**
 * Una revisión de «propuesta» de AcademicLoad por su etiqueta.
 */
function proposalDemoCheck(string $label): DemoCheck
{
    return collect(app()->getProvider(AcademicLoadServiceProvider::class)->demoChecks())->first(fn (DemoCheck $check): bool => $check->label === $label);
}

/**
 * Lo que deja la demo, en valores: el estado de cada propuesta, cada fila, cada cuenta de paquete, cada nuevo ingreso, cada
 * parte, el kárdex que se cursa y la bitácora.
 *
 * @return array<string, mixed>
 */
function proposalDemoSnapshot(): array
{
    return [
        'proposals' => CareerProposal::query()->with('career')->get()->mapWithKeys(fn (CareerProposal $proposal): array => [$proposal->career->code => $proposal->status->value])->sortKeys()->all(),
        'items' => ProposalItem::query()->orderBy('id')->get()->mapWithKeys(fn (ProposalItem $item): array => [$item->id => [
            $item->expected_students, $item->requested_groups, $item->package_groups, $item->suggested_expected_students, $item->suggested_groups,
            $item->captured_at !== null, $item->reviewed_at !== null, $item->notes,
        ]])->all(),
        'packages' => ProposalPackageCampus::query()->orderBy('id')->pluck('count', 'id')->all(),
        'intakes' => ProposalIntake::query()->orderBy('id')->get()->mapWithKeys(fn (ProposalIntake $intake): array => [$intake->id => [$intake->new_students, $intake->captured_at !== null]])->all(),
        'reviews' => ProposalReview::query()->orderBy('id')->get()->mapWithKeys(fn (ProposalReview $review): array => [$review->id => $review->status->value])->all(),
        'cursando' => KardexRecord::query()->where('result', KardexResult::Cursando)->count(),
        'activity' => Activity::query()->count(),
    ];
}

it('deja cada caso en «propuesta»: estados del tablero, la propuesta sin tocar, correcciones y paquetes', function () {
    $report = proposalDemoUpTo();
    $settings = DemandSettings::fromSettings();
    $c1 = Campus::query()->where('code', 'C1')->sole();

    $failed = array_filter($report->checks, fn (array $check): bool => ! $check['passed']);
    $statuses = Career::query()->orderBy('code')->where('is_active', true)->get()
        ->mapWithKeys(fn (Career $career): array => [$career->code => proposalDemoOf($career->code)?->status->value ?? 'sin propuesta'])->all();

    expect($failed)->toBe([])
        ->and($statuses)->toBe(['COPU' => 'returned', 'IGEM' => 'submitted', 'IIND' => 'draft', 'ILOG' => 'draft', 'ISIC' => 'submitted', 'LADM' => 'sin propuesta'])
        ->and(ProposalReview::query()->where('career_proposal_id', proposalDemoOf('COPU')->id)->pluck('status')->map->value->countBy()->all())
        ->toMatchArray(['accepted' => 1, 'returned' => 1])
        ->and(ProposalReview::query()->where('career_proposal_id', proposalDemoOf('IGEM')->id)->where('status', ProposalReviewStatus::Accepted)->count())->toBe(1);

    // Sin tocar: como la armó Nexus, sin correcciones, notas, revisiones ni paquetes.
    $untouched = proposalDemoOf('IIND');

    expect(ProposalItem::query()->where('career_proposal_id', $untouched->id)->where(fn ($query) => $query->whereNotNull('captured_at')->orWhereNotNull('notes')->orWhereNotNull('reviewed_at'))->count())->toBe(0)
        ->and(ProposalPackage::query()->where('career_proposal_id', $untouched->id)->exists())->toBeFalse()
        ->and(ProposalIntake::query()->where('career_proposal_id', $untouched->id)->whereNotNull('captured_at')->exists())->toBeFalse();

    // Correcciones chicas y grandes, una sugerida en 0 grupos, una que Nexus no sugería y notas.
    $rows = ProposalItem::query()->where('career_proposal_id', proposalDemoOf('ILOG')->id)->get();
    $captured = $rows->whereNotNull('captured_at');

    expect($captured->contains(fn (ProposalItem $item): bool => $item->requested_groups === $item->suggested_groups && ! $settings->isLargeDifference($item->suggested_expected_students, $item->expected_students)))->toBeTrue()
        ->and($captured->contains(fn (ProposalItem $item): bool => $item->requested_groups === $item->suggested_groups && $settings->isLargeDifference($item->suggested_expected_students, $item->expected_students)))->toBeTrue()
        ->and($captured->contains(fn (ProposalItem $item): bool => $item->suggested_groups > 0 && $item->requested_groups === 0))->toBeTrue()
        ->and($captured->contains(fn (ProposalItem $item): bool => $item->suggested_groups === 0 && $item->requested_groups > 0))->toBeTrue()
        ->and($captured->contains(fn (ProposalItem $item): bool => $item->package_groups === 0 && $item->requested_groups >= $item->suggested_groups + 2))->toBeTrue()
        ->and($rows->contains(fn (ProposalItem $item): bool => $item->captured_at === null && $item->notes !== null))->toBeTrue();

    // Paquetes: P1 con 1.º, P2 con una de 2.º, un nuevo ingreso corregido (COPU) y lugares que faltan (IGEM).
    $packages = ProposalPackage::query()->where('career_proposal_id', proposalDemoOf('ILOG')->id)->with('subjects')->orderBy('code')->get();
    $semesters = fn (ProposalPackage $package): array => ProposalItem::query()->where('career_proposal_id', $package->career_proposal_id)
        ->whereIn('subject_id', $package->subjects->pluck('subject_id'))->distinct()->orderBy('semester')->pluck('semester')->all();
    $short = ProposalPackageCampus::query()->whereIn('proposal_package_id', ProposalPackage::query()->where('career_proposal_id', proposalDemoOf('IGEM')->id)->select('id'))->sole();

    expect($packages->pluck('code')->all())->toBe(['P1', 'P2'])
        ->and($semesters($packages[0]))->toBe([1])
        ->and($semesters($packages[1]))->toBe([1, 2])
        ->and(ProposalIntake::query()->where('career_proposal_id', proposalDemoOf('COPU')->id)->whereNotNull('captured_at')->sole()->campus_id)->not->toBe($c1->id)
        ->and($short->count)->toBeLessThan($short->suggested_count)
        // La oferta nueva de Demand (COPU en Campus I) cae fuera de la demo: ni se corrige ni abre grupos (M1).
        ->and(ProposalIntake::query()->where('campus_id', $c1->id)->whereNotNull('captured_at')->exists())->toBeFalse()
        ->and((int) ProposalPackageCampus::query()->where('campus_id', $c1->id)->sum('count'))->toBe(0)
        ->and(ProposalItem::query()->where('campus_id', $c1->id)->where('requested_groups', '>', 0)->exists())->toBeFalse();
});

it('las revisiones de «propuesta» fallan si falta su caso, y solo cuentan los campus de la demo', function () {
    proposalDemoUpTo();
    $demo = DemoContext::for(DemoVolume::Demo);
    $outsideTheDemo = new DemoContext(DemoVolume::Demo, ['C1']);

    expect((proposalDemoCheck('La propuesta trae filas corregidas')->passes)($demo))->toBeTrue()
        // Las correcciones son de Campus II: con Campus I como la demo, la revisión no las cuenta.
        ->and((proposalDemoCheck('La propuesta trae filas corregidas')->passes)($outsideTheDemo))->toBe('Ninguna propuesta de la demo trae el caso.');

    ProposalItem::query()->update(['reviewed_at' => null, 'reviewed_by' => null]);
    // Lo sugerido que ya no es el de la proyección vigente no cuenta como «sigue a la proyección».
    ProposalItem::query()->whereNull('captured_at')->increment('suggested_groups');

    expect((proposalDemoCheck('La propuesta trae filas revisadas')->passes)($demo))->toBe('Ninguna propuesta de la demo trae el caso.')
        ->and((proposalDemoCheck('La propuesta trae filas sin tocar con lo sugerido de la proyección vigente')->passes)($demo))->toBe('Ninguna propuesta de la demo trae el caso.');
});

it('recalcula la proyección, resuelve y revisa como el coordinador de cada campus (M7)', function () {
    proposalDemoUpTo();
    $review = proposalDemoReview(proposalDemoOf('ILOG'));
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
});

it('volver a correr el paso real no cambia ningún valor, y una corrida a medias se retoma', function () {
    proposalDemoUpTo();
    $before = proposalDemoSnapshot();

    proposalDemoStep();

    expect(proposalDemoSnapshot())->toBe($before);

    // A medias: la propuesta sin tocar no se alcanzó a armar y la que debía quedar con una parte aceptada se envió sin decidir.
    $untouched = proposalDemoOf('IIND');
    ProposalItem::query()->where('career_proposal_id', $untouched->id)->delete();
    ProposalIntake::query()->where('career_proposal_id', $untouched->id)->delete();
    $untouched->delete();
    ProposalReview::query()->where('career_proposal_id', proposalDemoOf('IGEM')->id)->update(['status' => ProposalReviewStatus::Pending, 'decided_by' => null, 'decided_at' => null]);

    proposalDemoStep();

    expect(proposalDemoOf('IIND'))->not->toBeNull()
        ->and(ProposalItem::query()->where('career_proposal_id', proposalDemoOf('IIND')->id)->whereNotNull('captured_at')->exists())->toBeFalse()
        ->and(ProposalReview::query()->where('career_proposal_id', proposalDemoOf('IGEM')->id)->where('status', ProposalReviewStatus::Accepted)->count())->toBe(1)
        // El kárdex no se vuelve a calificar.
        ->and(KardexRecord::query()->where('result', KardexResult::Cursando)->count())->toBe($before['cursando']);
});

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new ProposalForecastDemoSeeder)->run())->toThrow(RuntimeException::class);
});
