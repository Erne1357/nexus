<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Queries\ProposalForecastQuery;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\DemandSettings;
use Illuminate\Validation\ValidationException;

/**
 * El coordinador decide qué hacer con el aviso «la proyección cambió: ahora sugiere N» de una fila que corrigió (tarea 5.5,
 * confirmado el 16 de noviembre de 2026). `$choice` es `use` (esperados y grupos pasan a lo que sugiere ahora Nexus, y la
 * fila sigue marcada como corregida porque fue una decisión) o `keep` (conserva lo capturado y solo se acepta lo sugerido
 * nuevo para que el aviso desaparezca). En los dos casos `suggested_*` queda con lo nuevo y la fila queda revisada
 * (`reviewed_at`, `reviewed_by`; «Revisar proyección», tarea 5.6, supuesto por confirmar).
 *
 * Usa las guardas, la transacción, la bitácora y el aviso de UpdateProposalItem::change (propuesta en borrador o regresada y
 * parte del departamento sin aceptar), y relee la proyección vigente dentro de la transacción. Si ya no hay cambio
 * pendiente, es una ValidationException sobre `item`. Quién puede lo decide CareerProposalPolicy::update sobre la fila
 * antes de llamarla.
 */
final class ResolveForecastChange
{
    public const string USE = 'use';

    public const string KEEP = 'keep';

    public function __construct(
        private readonly UpdateProposalItem $update,
        private readonly ProposalForecastQuery $forecasts,
    ) {}

    /**
     * @param  'use'|'keep'  $choice
     *
     * @throws ValidationException
     */
    public function handle(ProposalItem $item, string $choice, User $actor): ProposalItem
    {
        if (! in_array($choice, [self::USE, self::KEEP], true)) {
            throw ValidationException::withMessages(['choice' => 'Elige usar la proyección nueva o conservar lo capturado.']);
        }

        return $this->update->change($item, $actor, function (ProposalItem $locked) use ($choice, $actor): array {
            $proposal = $locked->proposal;

            $forecast = $this->forecasts->currentFor($proposal, $locked);

            $state = $this->forecasts->stateFor($locked, $forecast, DemandSettings::fromSettings());

            if ($state->state !== SuggestionState::Changed) {
                throw ValidationException::withMessages(['item' => 'La proyección de esta materia no tiene cambios pendientes.']);
            }

            $values = [
                'suggested_expected_students' => $forecast->expected_students,
                'suggested_groups' => $forecast->suggested_groups,
                'forecast_method' => $forecast->method,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->getKey(),
            ];

            if ($choice === self::USE) {
                $values['expected_students'] = $forecast->expected_students;
                $values['requested_groups'] = $forecast->suggested_groups;
            }

            return $values;
        });
    }
}
