<?php

namespace App\Modules\AcademicLoad\Actions\Agreements;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El departamento que imparte un grupo propone el cupo de una carrera de otro departamento
 * (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-07; confirmado el 7 de noviembre de 2026). Lo decide el
 * departamento de la carrera. La carrera debe ser de otro departamento (`career_id`) y los lugares de 1 a 999
 * (`max_seats`). Si la carrera ya tiene un acuerdo vivo (propuesto o aceptado) en ese grupo, lanza una
 * ValidationException sobre `career_id`, también cuando lo gana el índice `interdepartmental_agreements_shared_unique`
 * (savepoint propio). El grupo, su modalidad, su campus y su departamento (`from`) los da quien llama (Scheduling, que sí ve
 * el grupo; AcademicLoad no). El acuerdo es de la modalidad del grupo y no guarda campus: su lugar es el del grupo (el
 * campus solo va en la bitácora). Al quitar o mover un grupo, quien llama cancela antes sus acuerdos vivos.
 * Deja bitácora `proposed` y, después del commit, avisa con AgreementRequested.
 */
final class ProposeSharedGroup
{
    private const string UNIQUE_INDEX = 'interdepartmental_agreements_shared_unique';

    private const string TAKEN = 'La carrera ya tiene un acuerdo en este grupo.';

    public function __construct(private readonly AgreementRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(Term $term, Modality $modality, ?Campus $campus, Department $from, string $groupId, Career $career, int $seats, User $actor): InterdepartmentalAgreement
    {
        $this->rules->ensureCareerNeedsAgreement($career, $from);
        $this->rules->ensureSeats($seats);

        return DB::transaction(function () use ($term, $modality, $campus, $from, $groupId, $career, $seats, $actor): InterdepartmentalAgreement {
            $taken = InterdepartmentalAgreement::query()
                ->where('kind', AgreementKind::SharedGroup->value)
                ->where('group_id', $groupId)
                ->where('career_id', $career->getKey())
                ->live()
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['career_id' => self::TAKEN]);
            }

            try {
                $agreement = DB::transaction(fn (): InterdepartmentalAgreement => InterdepartmentalAgreement::query()->create([
                    'term_id' => $term->getKey(),
                    'campus_id' => null,
                    'modality_id' => $modality->getKey(),
                    'kind' => AgreementKind::SharedGroup,
                    'from_department_id' => $from->getKey(),
                    'to_department_id' => $career->department_id,
                    'group_id' => $groupId,
                    'career_id' => $career->getKey(),
                    'max_seats' => $seats,
                    'status' => AgreementStatus::Proposed,
                    'requested_by' => $actor->getKey(),
                ]));
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), self::UNIQUE_INDEX)) {
                    throw ValidationException::withMessages(['career_id' => self::TAKEN]);
                }

                throw $e;
            }

            $to = Department::withTrashed()->findOrFail($career->department_id);

            activity()
                ->performedOn($agreement)
                ->causedBy($actor)
                ->event('proposed')
                ->withChanges(['attributes' => [
                    'kind' => AgreementKind::SharedGroup->value,
                    'status' => AgreementStatus::Proposed->value,
                    'career_id' => $career->getKey(),
                    'max_seats' => $seats,
                ]])
                ->withProperties(['group_id' => $groupId, 'from_department_id' => $from->getKey(), 'to_department_id' => $to->getKey()])
                ->log("{$from->code} propuso a {$to->code} {$seats} lugares de {$career->code} en un grupo de ".($campus?->code ?? $modality->name)." para el periodo {$term->code}");

            AgreementRequested::dispatch($agreement);

            return $agreement;
        });
    }
}
