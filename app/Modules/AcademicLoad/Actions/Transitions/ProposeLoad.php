<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\Constraints\CatalogsValidated;
use App\Modules\AcademicLoad\Rules\Constraints\ProposalPartAccepted;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Pasa la carga de borrador a propuesta. Guarda, con los motivos en este orden: HC-14, los catálogos del
 * departamento validados (CatalogsValidated), y HC-20, al menos una parte aceptada del departamento en una
 * propuesta de carrera del periodo y campus (ProposalPartAccepted). La llama la bandeja del departamento al
 * aceptar la primera parte (AcceptProposalReview), dentro de su transacción. Las demás transiciones llegan en las
 * fases 3 y 4 con sus guardas. Quién puede proponer lo decide AcademicLoadPolicy::propose antes de llamarla.
 */
final class ProposeLoad
{
    public function __construct(
        private readonly TransitionLoad $transition,
        private readonly CatalogsValidated $catalogsValidated,
        private readonly ProposalPartAccepted $proposalPartAccepted,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): AcademicLoad
    {
        return $this->transition->handle($load, LoadStatus::Proposed, $actor, $comment, fn (AcademicLoad $load): array => [
            ...$this->catalogsValidated->reasons($load),
            ...$this->proposalPartAccepted->reasons($load),
        ]);
    }
}
