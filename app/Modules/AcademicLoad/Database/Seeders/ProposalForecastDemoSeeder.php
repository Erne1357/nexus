<?php

namespace App\Modules\AcademicLoad\Database\Seeders;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\AcademicLoad\Actions\Proposals\AcceptProposalReview;
use App\Modules\AcademicLoad\Actions\Proposals\CreateProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\MarkProposalItemReviewed;
use App\Modules\AcademicLoad\Actions\Proposals\ResolveForecastChange;
use App\Modules\AcademicLoad\Actions\Proposals\ReturnProposalReview;
use App\Modules\AcademicLoad\Actions\Proposals\SetPackageCount;
use App\Modules\AcademicLoad\Actions\Proposals\StartCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\SubmitCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalIntake;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Database\Seeders\Concerns\FindsProposalActors;
use App\Modules\AcademicLoad\Enums\ForecastReason;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\ProposalForecastQuery;
use App\Modules\AcademicLoad\Queries\ProposalForecastRow;
use App\Modules\AcademicLoad\Queries\ProposalOverview;
use App\Modules\AcademicLoad\Queries\ProposalPackagesQuery;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Database\Seeders\DemandRecalculationDemoSeeder;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Demand\Rules\TermCode;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Las propuestas precargadas de la demo con todos los casos de las pantallas de la fase 5 (#520): el paso que lleva al punto
 * «propuesta» de `demo:seed`, después de que Demand proyectó al abrir el periodo y los jefes validaron sus catálogos. Todo
 * con las Actions reales, como cada usuario (FindsProposalActors: el coordinador que cubre el campus de cada fila o columna,
 * M7, y el jefe de cada departamento), para que dejen bitácora y avisos como en el uso normal.
 *
 * En cada modalidad de `$modalities` abierta en el periodo, por cada carrera ofrecida en los campus `$campuses`, en orden de
 * clave:
 *
 * - **Estado.** La última se queda sin propuesta; una carrera ofrecida por primera vez en un campus sin alumnos (el caso raro
 *   de Demand: una columna de su proyección con cero elegibles y cero de nuevo ingreso) se queda en borrador con correcciones; de las demás, la primera se envía y la regresa un departamento después
 *   de que otro aceptó su parte, la segunda se envía con una parte aceptada, la tercera queda en borrador sin tocar y las
 *   siguientes alternan borrador con correcciones, enviada, borrador con correcciones y aceptada completa (supuesto por
 *   confirmar, para que el tablero y la bandeja tengan cada estado).
 * - **Paquetes** (todas menos la sin tocar): «P1» con las materias de 1.º y la cuenta sugerida. De las que no son oferta nueva,
 *   la primera corrige su nuevo ingreso (+12) antes de armarlo, la segunda abre un paquete menos que el sugerido («Faltan N
 *   lugares») y una de cada dos de las siguientes arma «P2» con las materias de 1.º menos una y la primera de 2.º, y abre uno.
 *   La oferta nueva pasa su nuevo ingreso de 0 a 35. En una columna fuera de `$campuses` los paquetes abren 0, como sus filas
 *   piden 0 grupos (M1).
 * - **Correcciones** (UpdateProposalItem; todas menos la sin tocar), en las filas de `$campuses`: dos chicas (±10 % de los
 *   esperados, dentro del umbral), una grande en esperados (×1.5 + 5) y otra en grupos (+2), una materia sugerida en 0 grupos,
 *   una que Nexus no sugería con un grupo y notas; además, una corrección chica en cada fila de las materias que va a calificar
 *   el recálculo, para que esas filas queden en «Cambió».
 * - **Recálculo:** DemandRecalculationDemoSeeder califica lo que se cursa en el semestre anterior en sus `RECALCULATED_SUBJECTS`
 *   materias y vuelve a proyectar; las filas sin tocar siguen a la proyección y las corregidas quedan en «Cambió». Después, en
 *   cada carrera con correcciones: «Usar N» y «Mantener» en dos filas cambiadas, dejando al menos una (ResolveForecastChange), «Dejar»
 *   en una con diferencia grande y «Está bien» en la primera de cada motivo sin tocar (MarkProposalItemReviewed), para que
 *   «Revisadas» no salga vacía y cada filtro de «Revisar proyección» tenga filas pendientes.
 * - **Envíos y decisiones:** SubmitCareerProposal, AcceptProposalReview y ReturnProposalReview según el estado de la carrera.
 *
 * Quien corrige una columna que no coordina nadie (la oferta nueva) es el admin. Determinista con `$seed`. Idempotente: una
 * carrera que ya tiene propuesta no se toca, y el recálculo, que califica el kárdex, corre una sola vez para todas las
 * modalidades y solo si el periodo no tenía propuestas; volver a correrlo no duplica ni recalcula, y si falta alguna, solo
 * la completa. Se niega a correr en producción.
 */
final class ProposalForecastDemoSeeder extends Seeder
{
    use FindsProposalActors;
    use RefusesProduction;

    /** Cuántas materias califica el recálculo (DemandRecalculationDemoSeeder, `$subjects`). */
    public const int RECALCULATED_SUBJECTS = 6;

    public const string MISSING = 'missing';

    public const string UNTOUCHED = 'untouched';

    public const string DRAFTING = 'drafting';

    public const string SUBMITTED = 'submitted';

    public const string RETURNED = 'returned';

    public const string PARTIAL = 'partial';

    public const string ACCEPTED = 'accepted';

    /** Los casos que cuentan las revisiones del punto (count()). */
    public const string CASE_SUGGESTED_ROW = 'suggested_row';

    public const string CASE_CAPTURED_ROW = 'captured_row';

    public const string CASE_CHANGED_ROW = 'changed_row';

    public const string CASE_REVIEWED_ROW = 'reviewed_row';

    public const string CASE_PACKAGE = 'package';

    public const string CASE_CORRECTED_INTAKE = 'corrected_intake';

    public const string CASE_DRAFTING = 'drafting';

    public const string CASE_SUBMITTED = 'submitted';

    public const string CASE_RETURNED = 'returned';

    public const string CASE_PARTIALLY_ACCEPTED = 'partially_accepted';

    /** El nuevo ingreso que captura la oferta nueva, donde Nexus sugiere 0. */
    public const int FIRST_OFFERING_INTAKE = 35;

    private const array RETURN_COMMENTS = [
        'Los esperados de Cálculo no llenan los grupos pedidos; ajusta y vuelve a enviar.',
        'Pides más grupos de los que podemos atender con la planta actual; revisa el estimado.',
        'Faltan grupos para los repetidores de este semestre; agrega al menos uno.',
    ];

    private const array NOTES = [
        'Incluye a los repetidores del semestre anterior.',
        'Preferencia de turno vespertino.',
        'Un grupo para alumnos que trabajan.',
        'Se espera más demanda por la reforma del plan.',
    ];

    /** Generador con semilla: la misma da los mismos datos. */
    private Randomizer $random;

    private Term $term;

    /** @var Collection<int, Campus> */
    private Collection $campuses;

    /**
     * Las modalidades van juntas (`$modalities`, las del volumen) porque el recálculo es del kárdex, de todas: corre una vez,
     * después de las correcciones de todas y antes de revisarlas.
     *
     * @param  list<string>  $campuses  claves de los campus de la demo
     * @param  list<string>  $modalities  claves de las modalidades de la carga (DemoVolume::modalities())
     *
     * @throws RuntimeException en producción o si el periodo no existe
     */
    public function run(array $campuses = ['C2'], string $term = '20271', int $seed = 2027, array $modalities = ['PRES']): void
    {
        $this->refuseProduction();
        $started = hrtime(true);
        $this->random = new Randomizer(new Mt19937($seed));
        $this->term = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe: corre antes DemoTermSeeder.");
        $this->campuses = Campus::query()->whereIn('code', $campuses)->orderBy('code')->get();
        $open = Modality::query()
            ->whereIn('code', $modalities)
            ->whereIn('id', TermOpening::query()->where('term_id', $this->term->getKey())->select('modality_id'))
            ->orderBy('code')
            ->get();

        if ($open->isEmpty()) {
            $this->command?->warn("El periodo {$term} no está abierto en ".implode(', ', $modalities).': no se arman propuestas.');

            return;
        }

        // El recálculo califica el kárdex: solo en la primera corrida, para no mover lo que ya se revisó y decidió.
        $firstRun = ! CareerProposal::query()->where('term_id', $this->term->getKey())->whereIn('modality_id', $open->modelKeys())->exists();
        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $proposals = $open->flatMap(fn (Modality $modality): array => $this->startProposals($modality))->all();

            if ($proposals === []) {
                $this->command?->info('Propuestas precargadas: ya estaban, no se tocan.');

                return;
            }

            $corrected = array_filter($proposals, fn (array $entry): bool => $entry['role'] !== self::UNTOUCHED);
            $graded = $this->subjectsToGrade();
            $variant = 0;

            foreach ($corrected as $entry) {
                $this->packages($entry, $entry['firstOffering'] === null ? $variant++ : null);
                $this->correctRows($entry['proposal'], $graded);
            }

            if ($firstRun) {
                $this->callWith(DemandRecalculationDemoSeeder::class, ['term' => $term, 'subjects' => self::RECALCULATED_SUBJECTS, 'seed' => $seed]);
            } else {
                $this->command?->warn('Ya había propuestas en el periodo: no se vuelve a recalcular la proyección.');
            }

            foreach ($corrected as $entry) {
                $this->closeOutsideRows($entry['proposal']);
                $this->review($entry['proposal']);
                $this->decide($entry);
            }
        } finally {
            config(['queue.default' => $previousQueue]);
        }

        $roles = array_count_values(array_column($proposals, 'role'));
        ksort($roles);
        $this->command?->info(sprintf('Propuestas precargadas con todos los casos en %s: %s, en %.1f s.', $this->campuses->pluck('code')->implode(', '),
            implode(', ', array_map(fn (string $role, int $count): string => "{$count} {$role}", array_keys($roles), $roles)), (hrtime(true) - $started) / 1e9));
    }

    /**
     * Cuántas filas, paquetes o propuestas de `$proposals` (las de la demo) traen un caso, para las revisiones del punto.
     *
     * @param  Builder<CareerProposal>  $proposals
     */
    public static function count(string $case, Builder $proposals): int
    {
        $ids = (clone $proposals)->select('career_proposals.id');
        $items = ProposalItem::query()->whereIn('career_proposal_id', $ids);

        return match ($case) {
            self::CASE_SUGGESTED_ROW => $items->whereNull('captured_at')->whereNotNull('suggested_groups')->count(),
            self::CASE_CAPTURED_ROW => $items->whereNotNull('captured_at')->count(),
            self::CASE_REVIEWED_ROW => $items->whereNotNull('reviewed_at')->count(),
            // ProposalSuggestionState: corregida y la proyección vigente ya no es la que guardó.
            self::CASE_CHANGED_ROW => $items->whereNotNull('proposal_items.captured_at')
                ->join('career_proposals', 'career_proposals.id', '=', 'proposal_items.career_proposal_id')
                ->join('demand_forecasts', fn ($join) => $join->on('demand_forecasts.term_id', '=', 'career_proposals.term_id')
                    ->on('demand_forecasts.modality_id', '=', 'career_proposals.modality_id')
                    ->on('demand_forecasts.career_id', '=', 'career_proposals.career_id')
                    ->on('demand_forecasts.subject_id', '=', 'proposal_items.subject_id')
                    ->whereRaw('demand_forecasts.campus_id is not distinct from proposal_items.campus_id'))
                ->where(fn ($query) => $query->whereRaw('demand_forecasts.expected_students is distinct from proposal_items.suggested_expected_students')
                    ->orWhereRaw('demand_forecasts.suggested_groups is distinct from proposal_items.suggested_groups'))
                ->count(),
            self::CASE_PACKAGE => ProposalPackage::query()->whereIn('career_proposal_id', $ids)->count(),
            self::CASE_CORRECTED_INTAKE => ProposalIntake::query()->whereIn('career_proposal_id', $ids)->whereNotNull('captured_at')->count(),
            self::CASE_DRAFTING => (clone $proposals)->where('status', ProposalStatus::Draft)->count(),
            self::CASE_SUBMITTED => (clone $proposals)->where('status', ProposalStatus::Submitted)->count(),
            self::CASE_RETURNED => (clone $proposals)->where('status', ProposalStatus::Returned)->count(),
            self::CASE_PARTIALLY_ACCEPTED => (clone $proposals)->where('status', '!=', ProposalStatus::Accepted)
                ->whereExists(fn (QueryBuilder $parts) => $parts->selectRaw('1')->from('proposal_reviews')
                    ->whereColumn('proposal_reviews.career_proposal_id', 'career_proposals.id')
                    ->where('proposal_reviews.status', ProposalReviewStatus::Accepted->value))
                ->count(),
        };
    }

    /**
     * Arma, en una modalidad, la propuesta de cada carrera que la lleva y no la tiene todavía, con el estado que le toca
     * (roles()).
     *
     * @return list<array{career: Career, modality: Modality, proposal: CareerProposal, role: string, coordinator: User, firstOffering: ?Campus}>
     */
    private function startProposals(Modality $modality): array
    {
        $careers = Career::query()
            ->whereIn('id', CareerCampus::query()->whereIn('campus_id', $this->campuses->modelKeys())->where('is_active', true)->select('career_id'))
            ->orderBy('code')
            ->get();
        $firstOfferings = $this->firstOfferings($modality, $careers);
        $roles = $this->roles($careers, $firstOfferings);
        $proposals = [];

        foreach ($careers as $career) {
            $role = $roles[$career->getKey()];

            if ($role === self::MISSING || app(CareerProposalQuery::class)->findFor($this->term, $modality, $career) !== null) {
                continue;
            }

            $coordinator = $this->coordinatorOf($modality, $career, $this->campuses);

            if ($coordinator === null) {
                $this->command?->warn("{$career->code} no tiene coordinador en {$modality->name}: se queda sin propuesta.");

                continue;
            }

            $proposals[] = [
                'career' => $career,
                'modality' => $modality,
                'proposal' => app(StartCareerProposal::class)->handle($this->term, $modality, $career, $coordinator),
                'role' => $role,
                'coordinator' => $coordinator,
                'firstOffering' => $firstOfferings[$career->getKey()] ?? null,
            ];
        }

        return $proposals;
    }

    /**
     * El campus de cada carrera ofrecida por primera vez en un campus sin alumnos, por id de carrera: el caso raro de Demand
     * (DemandRareCasesDemoSeeder::CASE_FIRST_OFFERING), una columna de la proyección del periodo y la modalidad con cero
     * elegibles y cero de nuevo ingreso.
     *
     * @param  Collection<int, Career>  $careers
     * @return array<string, Campus>
     */
    private function firstOfferings(Modality $modality, Collection $careers): array
    {
        $columns = DemandForecast::query()
            ->where('term_id', $this->term->getKey())
            ->where('modality_id', $modality->getKey())
            ->whereIn('career_id', $careers->modelKeys())
            ->whereNotNull('campus_id')
            ->groupBy('career_id', 'campus_id')
            ->havingRaw('sum(eligible_students) = 0 and coalesce(sum(new_students), 0) = 0')
            ->orderBy('career_id')
            ->orderBy('campus_id')
            ->toBase()
            ->get(['career_id', 'campus_id']);
        $campuses = Campus::query()->whereIn('id', $columns->pluck('campus_id'))->get()->keyBy('id');
        $found = [];

        foreach ($columns as $column) {
            $found[$column->career_id] ??= $campuses->get($column->campus_id);
        }

        return array_filter($found);
    }

    /**
     * El estado de cada carrera, por id: ver el docblock de la clase.
     *
     * @param  Collection<int, Career>  $careers  en orden de clave
     * @param  array<string, Campus>  $firstOfferings
     * @return array<string, string>
     */
    private function roles(Collection $careers, array $firstOfferings): array
    {
        $roles = array_map(fn (): string => self::DRAFTING, $firstOfferings);
        $others = $careers->reject(fn (Career $career): bool => isset($firstOfferings[$career->getKey()]))->values();
        $last = $others->pop();

        if ($last !== null) {
            $roles[$last->getKey()] = self::MISSING;
        }

        foreach ($others as $index => $career) {
            $roles[$career->getKey()] = $index < 3
                ? [self::RETURNED, self::PARTIAL, self::UNTOUCHED][$index]
                : [self::DRAFTING, self::SUBMITTED, self::DRAFTING, self::ACCEPTED][($index - 3) % 4];
        }

        return $roles;
    }

    /**
     * El nuevo ingreso y los paquetes de una propuesta. `$variant` es su lugar entre las que no son oferta nueva (null si lo
     * es): 0 corrige el nuevo ingreso, 1 abre un paquete menos que el sugerido y las pares desde 2 arman «P2».
     *
     * @param  array{career: Career, modality: Modality, proposal: CareerProposal, role: string, coordinator: User, firstOffering: ?Campus}  $entry
     */
    private function packages(array $entry, ?int $variant): void
    {
        $proposal = $entry['proposal'];
        $firstColumn = $this->demoIntakes($proposal)->first(fn (ProposalIntake $intake): bool => $intake->new_students > 0);

        if ($entry['firstOffering'] !== null) {
            $this->orWarn(fn () => app(UpdateProposalIntake::class)->handle($proposal, $entry['firstOffering'], self::FIRST_OFFERING_INTAKE, $this->columnActor($proposal, $entry['firstOffering']->getKey())), "nuevo ingreso de {$entry['career']->code}");
        } elseif ($variant === 0 && $firstColumn !== null) {
            $this->orWarn(fn () => app(UpdateProposalIntake::class)->handle($proposal, $firstColumn->campus, $firstColumn->new_students + 12, $this->columnActor($proposal, $firstColumn->campus_id)), "nuevo ingreso de {$entry['career']->code}");
        }

        $firstSemester = app(ProposalPackagesQuery::class)->openFirstSemesterSubjectIds($proposal);

        if ($firstSemester === []) {
            return;
        }

        $first = $this->orWarn(fn () => app(CreateProposalPackage::class)->handle($proposal, $firstSemester, null, $entry['coordinator']), "paquete de {$entry['career']->code}");

        if ($first === null) {
            return;
        }

        $this->closeOutsideColumns($proposal, $first);

        if ($variant === 1 && $firstColumn !== null) {
            $column = $first->campuses()->where('campus_id', $firstColumn->campus_id)->first();
            $this->orWarn(fn () => app(SetPackageCount::class)->handle($first, $firstColumn->campus, max(0, ($column->count ?? 1) - 1), $this->columnActor($proposal, $firstColumn->campus_id)), "cuenta de {$entry['career']->code}");
        }

        $secondSemester = ProposalItem::query()->where('proposal_items.career_proposal_id', $proposal->getKey())->where('proposal_items.semester', 2)
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')->orderBy('subjects.code')->value('proposal_items.subject_id');

        if ($variant === null || $variant < 2 || $variant % 2 !== 0 || $secondSemester === null || count($firstSemester) < 2) {
            return;
        }

        $second = $this->orWarn(fn () => app(CreateProposalPackage::class)->handle($proposal, [...array_slice($firstSemester, 0, -1), $secondSemester], null, $entry['coordinator']), "segundo paquete de {$entry['career']->code}");

        if ($second === null) {
            return;
        }

        $this->closeOutsideColumns($proposal, $second);

        foreach ($this->demoIntakes($proposal)->filter(fn (ProposalIntake $intake): bool => $intake->new_students > 0) as $intake) {
            $this->orWarn(fn () => app(SetPackageCount::class)->handle($second, $intake->campus, 1, $this->columnActor($proposal, $intake->campus_id)), "cuenta de P2 de {$entry['career']->code}");
        }
    }

    /**
     * En las columnas fuera de los campus de la demo, el paquete abre 0: la demo solo abre grupos en sus campus (M1).
     */
    private function closeOutsideColumns(CareerProposal $proposal, ProposalPackage $package): void
    {
        foreach ($package->campuses()->whereNotNull('campus_id')->whereNotIn('campus_id', $this->campuses->modelKeys())->where('count', '>', 0)->with('campus')->get() as $column) {
            $this->orWarn(fn () => app(SetPackageCount::class)->handle($package, $column->campus, 0, $this->columnActor($proposal, $column->campus_id)), "cuenta fuera de la demo de {$package->code}");
        }
    }

    /**
     * Las filas de otros campus que piden grupos quedan corregidas en cero, como en AcademicLoadDemoSeeder: la demo solo abre
     * grupos en sus campus (M1). Va después del recálculo, porque una fila sin tocar de primer semestre sigue al nuevo ingreso
     * corregido de su columna (la oferta nueva fuera de la demo) y el recálculo le vuelve a dar grupos. Las corrige quien
     * coordina esa columna o, si nadie, el admin.
     */
    private function closeOutsideRows(CareerProposal $proposal): void
    {
        foreach ($this->rows($proposal, outside: true)->where('requested_groups', '>', 0)->where('package_groups', 0) as $item) {
            $this->orWarn(fn () => app(UpdateProposalItem::class)->handle($item, $item->expected_students, 0, $item->notes, $this->columnActor($proposal, $item->campus_id)), "fila fuera de la demo de la propuesta {$proposal->getKey()}");
        }
    }

    /**
     * El nuevo ingreso de las columnas de los campus de la demo, en orden de campus.
     *
     * @return Collection<int, ProposalIntake>
     */
    private function demoIntakes(CareerProposal $proposal): Collection
    {
        return ProposalIntake::query()->where('career_proposal_id', $proposal->getKey())->whereIn('campus_id', $this->campuses->modelKeys())
            ->with('campus')->get()->sortBy(fn (ProposalIntake $intake): string => $intake->campus->code)->values();
    }

    /**
     * Las correcciones de una propuesta en las filas de los campus de la demo (ver el docblock de la clase); las de otros
     * campus las cierra closeOutsideRows(), después del recálculo.
     *
     * @param  list<string>  $graded  las materias que va a calificar el recálculo (subjectsToGrade())
     */
    private function correctRows(CareerProposal $proposal, array $graded): void
    {
        foreach ($this->rows($proposal)->filter(fn (ProposalItem $item): bool => in_array($item->subject_id, $graded, true)) as $item) {
            $this->correct($proposal, $item, $this->nudged($item->expected_students), $item->requested_groups, $item->notes);
        }

        $note = fn (): string => self::NOTES[$this->random->getInt(0, count(self::NOTES) - 1)];

        /** @var list<array{0: Closure(ProposalItem): bool, 1: Closure(ProposalItem): array{0: int, 1: int, 2: ?string}}> $cases */
        $cases = [
            // Dos chicas, dentro del umbral; la segunda con nota.
            [fn (ProposalItem $row): bool => $row->expected_students >= 10, fn (ProposalItem $row): array => [$this->nudged($row->expected_students), $row->requested_groups, $row->notes]],
            [fn (ProposalItem $row): bool => $row->expected_students >= 10, fn (ProposalItem $row): array => [$this->nudged($row->expected_students), $row->requested_groups, $note()]],
            // Grande en esperados y grande en grupos.
            [fn (ProposalItem $row): bool => $row->expected_students >= 5, fn (ProposalItem $row): array => [min(ProposalItemRules::MAX_EXPECTED_STUDENTS, (int) round($row->expected_students * 1.5) + 5), $row->requested_groups, $row->notes]],
            [fn (ProposalItem $row): bool => $row->requested_groups >= 1 && $row->requested_groups <= 5 && $row->package_groups === 0, fn (ProposalItem $row): array => [$row->expected_students, $row->requested_groups + 2, $note()]],
            // Una sugerida en 0 grupos y una que Nexus no sugería con un grupo.
            [fn (ProposalItem $row): bool => ($row->suggested_groups ?? 0) > 0 && $row->package_groups === 0, fn (ProposalItem $row): array => [$row->expected_students, 0, 'Esta materia se abre en verano.']],
            [fn (ProposalItem $row): bool => $row->suggested_groups === 0 && $row->package_groups === 0, fn (ProposalItem $row): array => [max($row->expected_students, 15), 1, 'Grupo para alumnos de reingreso.']],
            // Solo una nota: la fila sigue sin tocar.
            [fn (ProposalItem $row): bool => $row->notes === null, fn (ProposalItem $row): array => [$row->expected_students, $row->requested_groups, $note()]],
        ];
        $rows = $this->rows($proposal)->whereNull('captured_at')->values();

        foreach ($cases as [$fits, $values]) {
            $candidates = $rows->filter($fits)->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            $item = $candidates[$this->random->getInt(0, $candidates->count() - 1)];
            $rows = $rows->reject(fn (ProposalItem $row): bool => $row->is($item))->values();
            [$expected, $groups, $notes] = $values($item);
            $this->correct($proposal, $item, $expected, $groups, $notes);
        }
    }

    /**
     * Las filas de la propuesta en los campus de la demo (o, con `$outside`, en los demás), en el orden de la propuesta.
     *
     * @return Collection<int, ProposalItem>
     */
    private function rows(CareerProposal $proposal, bool $outside = false): Collection
    {
        return ProposalItem::query()
            ->where('proposal_items.career_proposal_id', $proposal->getKey())
            ->when($outside, fn ($query) => $query->whereNotNull('proposal_items.campus_id')->whereNotIn('proposal_items.campus_id', $this->campuses->modelKeys()),
                fn ($query) => $query->where(fn ($inside) => $inside->whereNull('proposal_items.campus_id')->orWhereIn('proposal_items.campus_id', $this->campuses->modelKeys())))
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')
            ->leftJoin('campuses', 'campuses.id', '=', 'proposal_items.campus_id')
            ->orderBy('proposal_items.semester')
            ->orderBy('subjects.code')
            ->orderBy('campuses.code')
            ->select('proposal_items.*')
            ->get();
    }

    /**
     * Las materias que califica el recálculo: las `RECALCULATED_SUBJECTS` con más alumnos cursándolas en el semestre
     * ordinario anterior, como las elige DemandRecalculationDemoSeeder.
     *
     * @return list<string>
     */
    private function subjectsToGrade(): array
    {
        return KardexRecord::query()
            ->where('term_code', TermCode::previousRegular($this->term->code))
            ->where('result', KardexResult::Cursando)
            ->groupBy('subject_id')
            ->orderByRaw('count(*) desc')
            ->orderBy('subject_id')
            ->limit(self::RECALCULATED_SUBJECTS)
            ->pluck('subject_id')
            ->all();
    }

    /**
     * Unos esperados corregidos dentro del umbral: ±10 % (al menos 1).
     */
    private function nudged(int $expected): int
    {
        $step = max(1, intdiv($expected, 10));

        return min(ProposalItemRules::MAX_EXPECTED_STUDENTS, max(0, $expected + ($this->random->getInt(0, 1) === 0 ? -$step : $step)));
    }

    /**
     * Corrige una fila como el coordinador de su campus (M7); si nadie la cubre o la Action la rechaza, se deja como está.
     */
    private function correct(CareerProposal $proposal, ProposalItem $item, int $expected, int $groups, ?string $notes): void
    {
        $holder = $this->rowHolder($proposal, $item);

        if ($holder === false) {
            return;
        }

        $groups = min(ProposalItemRules::MAX_REQUESTED_GROUPS, max($groups, $item->package_groups));
        $this->orWarn(fn () => app(UpdateProposalItem::class)->handle($item, $expected, $groups, $notes, $holder), "fila de la propuesta {$proposal->getKey()}");
    }

    /**
     * «Revisar proyección» después del recálculo, en las columnas de la demo (ver el docblock de la clase).
     */
    private function review(CareerProposal $proposal): void
    {
        $proposal->refresh();
        $overview = app(CareerProposalQuery::class)->overview($proposal, withPendingCatalogs: false);
        $review = app(ProposalForecastQuery::class)->review($overview, DemandSettings::fromSettings());
        // Las columnas de la demo y, en una modalidad sin salones, la columna sin campus (MS5).
        $keys = [...array_map(fn (Campus $campus): string => ProposalOverview::columnKey($campus), $this->campuses->all()), ProposalOverview::columnKey(null)];
        $changed = collect($review->pending($keys, ForecastReason::Changed));
        // «Usar N» deja los grupos en lo sugerido nuevo, que no puede bajar de los que salen de paquetes.
        $use = $changed->count() >= 2 ? $changed->first(fn (ProposalForecastRow $row): bool => $row->forecast->suggested_groups >= $row->item->package_groups) : null;
        $keep = $changed->count() >= 3 ? $changed->first(fn (ProposalForecastRow $row): bool => $row !== $use) : null;

        foreach ([ResolveForecastChange::USE => $use, ResolveForecastChange::KEEP => $keep] as $choice => $row) {
            if ($row !== null) {
                $this->act($proposal, $row, fn (User $actor) => app(ResolveForecastChange::class)->handle($row->item, $choice, $actor));
            }
        }

        foreach ([ForecastReason::LargeDifference, ForecastReason::Specialty, ForecastReason::NoUptake, ForecastReason::NoHistory] as $reason) {
            $row = collect($review->pending($keys, $reason))->first(fn (ProposalForecastRow $row): bool => $row->primary() === $reason);

            if ($row !== null) {
                $this->act($proposal, $row, fn (User $actor) => app(MarkProposalItemReviewed::class)->handle($row->item, $actor));
            }
        }
    }

    /**
     * @param  callable(User): mixed  $work
     */
    private function act(CareerProposal $proposal, ProposalForecastRow $row, callable $work): void
    {
        $holder = $this->rowHolder($proposal, $row->item);

        if ($holder !== false) {
            $this->orWarn(fn () => $work($holder), "revisión de la proyección de la propuesta {$proposal->getKey()}");
        }
    }

    /**
     * Envía y decide según el estado de la carrera: enviada; con una parte aceptada; regresada por un departamento después de
     * que otro aceptó (si hay dos), o aceptada completa.
     *
     * @param  array{career: Career, modality: Modality, proposal: CareerProposal, role: string, coordinator: User, firstOffering: ?Campus}  $entry
     */
    private function decide(array $entry): void
    {
        if ($entry['role'] === self::DRAFTING) {
            return;
        }

        $proposal = $this->orWarn(fn () => app(SubmitCareerProposal::class)->handle($entry['proposal'], $entry['coordinator']), "envío de {$entry['career']->code}");

        if ($proposal === null) {
            return;
        }

        $parts = ProposalReview::query()->where('career_proposal_id', $proposal->getKey())->where('status', ProposalReviewStatus::Pending)
            ->join('departments', 'departments.id', '=', 'proposal_reviews.department_id')->orderBy('departments.code')
            ->select('proposal_reviews.*')->with('department')->get()->values();

        $accept = match ($entry['role']) {
            self::PARTIAL => $parts->take(1),
            self::RETURNED => $parts->count() > 1 ? $parts->take(1) : collect(),
            self::ACCEPTED => $parts,
            default => collect(),
        };

        foreach ($accept as $part) {
            $head = $this->headOf($entry['modality'], $part->department);

            if ($head !== null && app(CatalogReviewQuery::class)->pendingFor($this->term, $entry['modality'], $part->department) === []) {
                $this->orWarn(fn () => app(AcceptProposalReview::class)->handle($part, $head), "parte de {$part->department->code} en {$entry['career']->code}");
            }
        }

        $returning = $entry['role'] === self::RETURNED ? $parts->skip($accept->count())->first() : null;
        $head = $returning === null ? null : $this->headOf($entry['modality'], $returning->department);

        if ($returning !== null && $head !== null) {
            $comment = self::RETURN_COMMENTS[$this->random->getInt(0, count(self::RETURN_COMMENTS) - 1)];
            $this->orWarn(fn () => app(ReturnProposalReview::class)->handle($returning, $comment, $head), "regreso de {$entry['career']->code}");
        }
    }

    /**
     * Quien corrige una columna: su coordinador (M7) o, si nadie la coordina (la oferta nueva), el admin.
     */
    private function columnActor(CareerProposal $proposal, ?string $campusId): User
    {
        return $this->columnHolder($proposal, $campusId) ?: User::query()->where('username', 'admin')->firstOrFail();
    }

    /**
     * Corre una Action y, si la rechaza (ValidationException), lo avisa y sigue: la demo se queda sin ese caso, que las
     * revisiones del punto notan. Cualquier otro error detiene el paso con su motivo, como debe.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T|null
     */
    private function orWarn(callable $work, string $what): mixed
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            $this->command?->warn("No se pudo: {$what} (".collect($exception->errors())->flatten()->implode(' ').').');

            return null;
        }
    }
}
