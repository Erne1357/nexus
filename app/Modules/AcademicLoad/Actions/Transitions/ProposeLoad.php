<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\Constraints\CatalogsValidated;
use Illuminate\Validation\ValidationException;

/**
 * Pasa la carga de borrador a propuesta. Guarda: HC-14, los catálogos del departamento validados
 * (CatalogsValidated). La condición de que las propuestas de las carreras estén aceptadas llega con la
 * tarea 2.8 y se agrega a la guarda. Las demás transiciones llegan en las fases 3 y 4 con sus guardas.
 * Quién puede proponer lo decide AcademicLoadPolicy::propose antes de llamarla.
 */
final class ProposeLoad
{
    public function __construct(
        private readonly TransitionLoad $transition,
        private readonly CatalogsValidated $catalogsValidated,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(AcademicLoad $load, ?string $comment = null): AcademicLoad
    {
        return $this->transition->handle($load, LoadStatus::Proposed, $comment, $this->catalogsValidated->reasons(...));
    }
}
