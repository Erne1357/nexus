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
use App\Modules\Core\Models\Modality;
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
 * bitácora, historial de la carga y avisos en la campana, como en el uso normal. La propuesta es una por carrera y
 * modalidad, con una fila por materia y campus (M6): en cada modalidad de los campus de `$campuses` donde el periodo
 * `$term` está abierto, por cada carrera que se ofrece en alguno de esos campus, una propuesta. Solo se piden grupos en
 * los campus de `$campuses`: las filas de otro campus quedan en cero grupos (en el piloto de C2 la carga solo tiene
 * los grupos de C2, M1). Por cada propuesta:
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
            $campusModels = Campus::query()->whereIn('code', $campuses)->orderBy('code')->get();

            foreach ($campusModels->groupBy('modality_id') as $modalityId => $inModality) {
                $modality = Modality::query()->findOrFail($modalityId);
                $codes = $inModality->pluck('code')->implode(', ');

                if (! TermOpening::query()->where('term_id', $termModel->id)->where('modality_id', $modality->id)->exists()) {
                    if ($complete) {
                        throw new RuntimeException("El periodo {$term} no está abierto en {$modality->name} ({$codes}): no se pueden enviar sus propuestas.");
                    }

                    $this->command?->warn("El periodo {$term} no está abierto en {$modality->name} ({$codes}): no se arman propuestas ahí.");

                    continue;
                }

                $complete
                    ? $this->completeModality($termModel, $modality, $inModality)
                    : $this->seedModality($termModel, $modality, $inModality, $returnEvery, $pendingEvery);
            }

            $this->markOlderNoticesRead($readOlderThan);
        } finally {
            config(['queue.default' => $previousQueue]);
        }
    }

    /**
     * @param  Collection<int, Campus>  $campuses  los campus de la demo en la modalidad
     */
    private function seedModality(Term $term, Modality $modality, Collection $campuses, int $returnEvery, int $pendingEvery): void
    {
        $careers = $this->offeredCareers($campuses);
        $decision = 0;

        foreach ($careers->values() as $index => $career) {
            $coordinator = $this->coordinatorOf($modality, $career, $campuses);

            if ($coordinator === null) {
                continue;
            }

            $proposal = app(StartCareerProposal::class)->handle($term, $modality, $career, $coordinator);

            if ($proposal->status !== ProposalStatus::Draft) {
                continue;
            }

            $this->outsideTheDemo($proposal, $campuses);
            $this->adjustRows($proposal, $coordinator, $campuses);

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

                $head = $this->headOf($modality, $part->department);

                if ($head === null) {
                    continue;
                }

                $validated = app(CatalogReviewQuery::class)->pendingFor($term, $modality, $part->department) === [];

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
                $this->adjustRows($proposal, $coordinator, $campuses);
                $this->submit($proposal, $coordinator);
            }
        }
    }

    /**
     * Cada carrera ofrecida corrige unas filas, envía, y cada departamento acepta su parte con su jefe; una propuesta
     * regresada antes se vuelve a enviar. Lo que no se pueda, se detiene con el motivo.
     *
     * @param  Collection<int, Campus>  $campuses  los campus de la demo en la modalidad
     *
     * @throws RuntimeException
     */
    private function completeModality(Term $term, Modality $modality, Collection $campuses): void
    {
        foreach ($this->offeredCareers($campuses) as $career) {
            $where = "{$career->code} en {$modality->name}";
            $coordinator = $this->coordinatorOf($modality, $career, $campuses) ?? throw new RuntimeException("{$where} no tiene coordinador: nadie puede enviar su propuesta.");
            $proposal = app(StartCareerProposal::class)->handle($term, $modality, $career, $coordinator);

            if (in_array($proposal->status, [ProposalStatus::Draft, ProposalStatus::Returned], true)) {
                $this->outsideTheDemo($proposal, $campuses, complete: true);
                $this->adjustRows($proposal, $coordinator, $campuses);
                $proposal = $this->orFail(fn (): CareerProposal => app(SubmitCareerProposal::class)->handle($proposal, $coordinator), "La propuesta de {$where} no se pudo enviar");
            }

            foreach ($this->pendingParts($proposal) as $part) {
                $what = "La parte de {$part->department->code} en la propuesta de {$where}";
                $head = $this->headOf($modality, $part->department) ?? throw new RuntimeException("{$what} no tiene jefe que la acepte.");

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
     * Las carreras ofrecidas en alguno de los campus de la demo, una vez cada una.
     *
     * @param  Collection<int, Campus>  $campuses
     * @return Collection<int, Career>
     */
    private function offeredCareers(Collection $campuses): Collection
    {
        return Career::query()
            ->whereIn('id', CareerCampus::query()->whereIn('campus_id', $campuses->modelKeys())->where('is_active', true)->select('career_id'))
            ->orderBy('code')
            ->get();
    }

    /**
     * Las filas de los campus que no son de la demo no piden grupos: la demo de un campus solo abre grupos en él (M1). Cada
     * fila la deja en cero un coordinador que corrige su campus (M7): uno de ese campus o uno sin campus. Si nadie la
     * cubre, con `$complete` se detiene (los grupos nacerían fuera de la demo); si no, la deja como está y lo avisa.
     *
     * @param  Collection<int, Campus>  $campuses
     *
     * @throws RuntimeException con `$complete`, si una fila con grupos fuera de la demo no tiene quien la corrija
     */
    private function outsideTheDemo(CareerProposal $proposal, Collection $campuses, bool $complete = false): void
    {
        $items = ProposalItem::query()
            ->where('career_proposal_id', $proposal->id)
            ->whereNotNull('campus_id')
            ->whereNotIn('campus_id', $campuses->modelKeys())
            ->where('requested_groups', '>', 0)
            ->with(['campus', 'subject'])
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $holder = app(RecipientsQuery::class)->holders(AcademicLoadPermission::ProposalUpdate, Target::of(modality: $proposal->modality_id, campus: $item->campus_id, career: $proposal->career_id))[0]['user'] ?? null;

            if ($holder === null) {
                $what = "La fila de {$item->subject->code} en {$item->campus->code} de la propuesta {$proposal->id} pide grupos fuera de la demo y nadie la puede corregir";

                if ($complete) {
                    throw new RuntimeException("{$what}.");
                }

                $this->command?->warn("{$what}: se deja como está.");

                continue;
            }

            try {
                app(UpdateProposalItem::class)->handle($item, $item->expected_students, 0, $item->notes, $holder);
            } catch (ValidationException) {
                // Fila bloqueada: se deja como está.
            }
        }
    }

    /**
     * Corrige unas cuantas filas de los campus de la demo como lo haría el coordinador: más o menos esperados, un grupo
     * de más o de menos y, a veces, una nota. Las filas bloqueadas (de un departamento que ya aceptó) se saltan.
     *
     * @param  Collection<int, Campus>  $campuses
     */
    private function adjustRows(CareerProposal $proposal, User $coordinator, Collection $campuses): void
    {
        $items = ProposalItem::query()
            ->where('career_proposal_id', $proposal->id)
            ->where(fn ($query) => $query->whereNull('campus_id')->orWhereIn('campus_id', $campuses->modelKeys()))
            ->where('expected_students', '>', 0)
            ->orderBy('id')
            ->get();

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

    /**
     * Quien arma y envía la propuesta: el primer coordinador de la carrera en la modalidad que corrige las filas de un
     * campus de la demo; si no hay, cualquiera de la carrera en la modalidad (M6).
     *
     * @param  Collection<int, Campus>  $campuses
     */
    private function coordinatorOf(Modality $modality, Career $career, Collection $campuses): ?User
    {
        $recipients = app(RecipientsQuery::class);

        foreach ($campuses as $campus) {
            $holder = $recipients->holders(AcademicLoadPermission::ProposalUpdate, Target::of(modality: $modality->getKey(), campus: $campus->getKey(), career: $career->getKey()))[0] ?? null;

            if ($holder !== null) {
                return $holder['user'];
            }
        }

        return $recipients->holders(AcademicLoadPermission::ProposalSubmit, Target::of(modality: $modality->getKey(), career: $career->getKey()))[0]['user'] ?? null;
    }

    private function headOf(Modality $modality, Department $department): ?User
    {
        return app(RecipientsQuery::class)->holders(AcademicLoadPermission::ProposalReviewDecide, Target::of(modality: $modality->getKey(), unit: $department->getKey()))[0]['user'] ?? null;
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
