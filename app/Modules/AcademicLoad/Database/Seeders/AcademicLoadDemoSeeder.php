<?php

namespace App\Modules\AcademicLoad\Database\Seeders;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Proposals\AcceptProposalReview;
use App\Modules\AcademicLoad\Actions\Proposals\ReturnProposalReview;
use App\Modules\AcademicLoad\Actions\Proposals\StartCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\SubmitCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Propuestas de carrera y bandejas de prueba (tareas 2.7 a 2.9), armadas con las Actions reales para que dejen
 * bitácora, historial de la carga y avisos en la campana, como en el uso normal. En cada campus de `$campuses`
 * donde el periodo `$term` está abierto, por cada carrera que se ofrece ahí:
 *
 * - la primera queda en borrador, con algunas filas corregidas; las demás se corrigen y se envían;
 * - cada departamento decide su parte: regresa con un comentario una de cada `$returnEvery`, deja pendiente una de
 *   cada `$pendingEvery` y acepta las demás si tiene sus catálogos validados (HC-14; los deja así el seeder de
 *   CatalogValidation, que corre antes); sin catálogos validados la parte se queda pendiente. Una propuesta
 *   regresada a veces se corrige y se reenvía, para que también haya reenvíos;
 * - los avisos («envió», «reenvió», «regresó») los mandan los listeners en el momento (la cola va en `sync`
 *   mientras corre) y el seeder marca como leídos los más viejos de cada usuario, para que la campana tenga de
 *   los dos.
 *
 * Con `$complete` (el paso de AcademicLoad que lleva al punto 4 «horarios» de `demo:seed`), en cambio, cada carrera
 * ofrecida propone y envía, y cada departamento acepta todas sus partes; así nacen todos los grupos y cada carga con
 * partes pasa a `proposed`. Lo que no se pueda (una carrera sin coordinador, un departamento sin jefe o sin sus
 * catálogos validados, un envío rechazado) detiene el seeder con el motivo, en vez de dejar el punto a medias.
 *
 * Determinista con `$seed`. Idempotente: una propuesta que ya no está en borrador no se toca, así que volver a
 * correrlo solo completa lo que falte. Se niega a correr en producción.
 */
final class AcademicLoadDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** Generador con semilla: la misma da los mismos datos. */
    private Randomizer $random;

    private const array RETURN_COMMENTS = [
        'Los esperados no llenan los grupos pedidos; ajusta los grupos y vuelve a enviar.',
        'Pides más grupos de los que podemos atender con la planta actual; revisa el estimado.',
        'Faltan grupos para los repetidores de este semestre; agrega al menos uno.',
        'Revisa los elegibles: el kárdex nuevo cambia el número de alumnos.',
        'Junta los grupos pequeños con otra carrera; con estos esperados basta uno.',
    ];

    private const array NOTES = [
        'Incluye a los repetidores del semestre anterior.',
        'Preferencia de turno vespertino.',
        'Se espera más demanda por la reforma del plan.',
        'Un grupo para alumnos que trabajan.',
    ];

    /**
     * @param  list<string>  $campuses  claves de campus
     * @param  bool  $complete  todas las carreras envían y todas las partes se aceptan (punto 4 de la demo)
     *
     * @throws RuntimeException en producción, si el periodo no existe o, con `$complete`, si algo no se puede completar
     */
    public function run(array $campuses = ['C2'], string $term = '20271', int $seed = 2027, int $returnEvery = 4, int $pendingEvery = 3, int $readOlderThan = 3, bool $complete = false): void
    {
        $this->refuseProduction();
        $this->random = new Randomizer(new Mt19937($seed));

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe: corre antes DemoTermSeeder.");
        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            foreach ($campuses as $code) {
                $campus = Campus::query()->where('code', $code)->firstOrFail();

                if (! TermOpening::query()->where('term_id', $termModel->id)->where('campus_id', $campus->id)->exists()) {
                    if ($complete) {
                        throw new RuntimeException("El periodo {$term} no está abierto en {$code}: no se pueden enviar sus propuestas.");
                    }

                    $this->command?->warn("El periodo {$term} no está abierto en {$code}: no se arman propuestas ahí.");

                    continue;
                }

                $complete
                    ? $this->completeCampus($termModel, $campus)
                    : $this->seedCampus($termModel, $campus, $returnEvery, $pendingEvery);
            }

            $this->markOlderNoticesRead($readOlderThan);
        } finally {
            config(['queue.default' => $previousQueue]);
        }
    }

    private function seedCampus(Term $term, Campus $campus, int $returnEvery, int $pendingEvery): void
    {
        $careers = $this->offeredCareers($campus);
        $decision = 0;

        foreach ($careers->values() as $index => $career) {
            $coordinator = $this->coordinatorOf($campus, $career);

            if ($coordinator === null) {
                continue;
            }

            $proposal = app(StartCareerProposal::class)->handle($term, $campus, $career, $coordinator);

            if ($proposal->status !== ProposalStatus::Draft) {
                continue;
            }

            $this->adjustRows($proposal, $coordinator);

            if ($index === 0) {
                continue;
            }

            $proposal = $this->submit($proposal, $coordinator);

            if ($proposal === null) {
                continue;
            }

            foreach ($this->pendingParts($proposal) as $part) {
                $decision++;

                if ($decision % $pendingEvery === 0) {
                    continue;
                }

                $head = $this->headOf($campus, $part->department);

                if ($head === null) {
                    continue;
                }

                $validated = app(CatalogReviewQuery::class)->pendingFor($term, $campus, $part->department) === [];

                try {
                    if ($decision % $returnEvery === 0) {
                        app(ReturnProposalReview::class)->handle($part, self::RETURN_COMMENTS[$this->random->getInt(0, count(self::RETURN_COMMENTS) - 1)], $head);
                    } elseif ($validated) {
                        app(AcceptProposalReview::class)->handle($part, $head);
                    }
                } catch (ValidationException) {
                    // La parte ya no se puede decidir (por ejemplo, la propuesta se regresó): queda pendiente.
                }
            }

            $proposal->refresh();

            if ($proposal->status === ProposalStatus::Returned && $index % 2 === 0) {
                $this->adjustRows($proposal, $coordinator);
                $this->submit($proposal, $coordinator);
            }
        }
    }

    /**
     * Cada carrera ofrecida corrige unas filas, envía, y cada departamento acepta su parte con su jefe; una propuesta
     * regresada antes se vuelve a enviar. Lo que no se pueda, se detiene con el motivo.
     *
     * @throws RuntimeException
     */
    private function completeCampus(Term $term, Campus $campus): void
    {
        foreach ($this->offeredCareers($campus) as $career) {
            $where = "{$career->code} en {$campus->code}";
            $coordinator = $this->coordinatorOf($campus, $career) ?? throw new RuntimeException("{$where} no tiene coordinador: nadie puede enviar su propuesta.");
            $proposal = app(StartCareerProposal::class)->handle($term, $campus, $career, $coordinator);

            if (in_array($proposal->status, [ProposalStatus::Draft, ProposalStatus::Returned], true)) {
                $this->adjustRows($proposal, $coordinator);
                $proposal = $this->orFail(fn (): CareerProposal => app(SubmitCareerProposal::class)->handle($proposal, $coordinator), "La propuesta de {$where} no se pudo enviar");
            }

            foreach ($this->pendingParts($proposal) as $part) {
                $what = "La parte de {$part->department->code} en la propuesta de {$where}";
                $head = $this->headOf($campus, $part->department) ?? throw new RuntimeException("{$what} no tiene jefe que la acepte.");

                $this->orFail(fn () => app(AcceptProposalReview::class)->handle($part, $head), "{$what} no se pudo aceptar");
            }
        }
    }

    /**
     * Corre una Action y, si la rechaza, detiene el seeder con sus motivos.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws RuntimeException
     */
    private function orFail(Closure $work, string $what): mixed
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            throw new RuntimeException("{$what}: ".implode(' ', Arr::flatten($exception->errors())), previous: $exception);
        }
    }

    /**
     * @return Collection<int, Career>
     */
    private function offeredCareers(Campus $campus): Collection
    {
        return Career::query()
            ->whereIn('id', CareerCampus::query()->where('campus_id', $campus->id)->where('is_active', true)->select('career_id'))
            ->orderBy('code')
            ->get();
    }

    /**
     * Corrige unas cuantas filas como lo haría el coordinador: más o menos esperados, un grupo de más o de menos y,
     * a veces, una nota. Las filas bloqueadas (de un departamento que ya aceptó) se saltan.
     */
    private function adjustRows(CareerProposal $proposal, User $coordinator): void
    {
        $items = ProposalItem::query()->where('career_proposal_id', $proposal->id)->where('expected_students', '>', 0)->orderBy('id')->get();

        foreach ($items as $item) {
            if ($this->random->getInt(1, 100) > 30) {
                continue;
            }

            $expected = min(ProposalItemRules::MAX_EXPECTED_STUDENTS, max(0, $item->expected_students + $this->random->getInt(-8, 12)));
            $groups = min(ProposalItemRules::MAX_REQUESTED_GROUPS, max(0, $item->requested_groups + $this->random->getInt(-1, 1)));
            $notes = $this->random->getInt(1, 100) <= 25 ? self::NOTES[$this->random->getInt(0, count(self::NOTES) - 1)] : $item->notes;

            try {
                app(UpdateProposalItem::class)->handle($item, $expected, $groups, $notes, $coordinator);
            } catch (ValidationException) {
                // Fila bloqueada o fuera de rango: el coordinador no la puede corregir; se deja como está.
            }
        }
    }

    private function submit(CareerProposal $proposal, User $coordinator): ?CareerProposal
    {
        try {
            return app(SubmitCareerProposal::class)->handle($proposal, $coordinator);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * @return Collection<int, ProposalReview>
     */
    private function pendingParts(CareerProposal $proposal): Collection
    {
        return ProposalReview::query()
            ->where('career_proposal_id', $proposal->id)
            ->where('status', ProposalReviewStatus::Pending)
            ->join('departments', 'departments.id', '=', 'proposal_reviews.department_id')
            ->orderBy('departments.code')
            ->select('proposal_reviews.*')
            ->with('department')
            ->get();
    }

    private function coordinatorOf(Campus $campus, Career $career): ?User
    {
        return app(RecipientsQuery::class)->holders(AcademicLoadPermission::ProposalUpdate, Target::of(modality: $campus->modality_id, campus: $campus->getKey(), career: $career->getKey()))[0]['user'] ?? null;
    }

    private function headOf(Campus $campus, Department $department): ?User
    {
        return app(RecipientsQuery::class)->holders(AcademicLoadPermission::ProposalReviewDecide, Target::of(modality: $campus->modality_id, unit: $department->getKey()))[0]['user'] ?? null;
    }

    /**
     * Deja como leídas las notificaciones de cada usuario salvo las `$keep` más recientes.
     */
    private function markOlderNoticesRead(int $keep): void
    {
        User::query()->whereHas('unreadNotifications')->each(function (User $user) use ($keep): void {
            $keepIds = $user->notifications()->latest()->limit($keep)->pluck('id');

            $user->unreadNotifications()->whereNotIn('id', $keepIds)->update(['read_at' => now()]);
        });
    }
}
