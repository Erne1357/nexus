<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Rules\ProposalSuggestionState;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandSettings;
use Illuminate\Validation\ValidationException;

/**
 * El coordinador decide qué hacer con el aviso «la proyección cambió: ahora sugiere N» de una fila que corrigió (tarea 5.5,
 * confirmado el 16 de noviembre de 2026). `$choice` es `use` (esperados y grupos pasan a lo que sugiere ahora Nexus, y la
 * fila sigue marcada como corregida porque fue una decisión) o `keep` (conserva lo capturado y solo se acepta lo sugerido
 * nuevo para que el aviso desaparezca). En los dos casos `suggested_*` queda con lo nuevo.
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

    public function __construct(private readonly UpdateProposalItem $update) {}

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

        return $this->update->change($item, $actor, function (ProposalItem $locked) use ($choice): array {
            $proposal = $locked->proposal;

            $forecast = DemandForecast::query()
                ->where('term_id', $proposal->term_id)
                ->where('modality_id', $proposal->modality_id)
                ->where('career_id', $proposal->career_id)
                ->where('subject_id', $locked->subject_id)
                ->where(fn ($query) => $locked->campus_id === null ? $query->whereNull('campus_id') : $query->where('campus_id', $locked->campus_id))
                ->first();

            $state = ProposalSuggestionState::of(
                $locked->expected_students,
                $locked->requested_groups,
                $locked->suggested_expected_students,
                $locked->suggested_groups,
                $locked->captured_at,
                $forecast?->expected_students,
                $forecast?->suggested_groups,
                DemandSettings::fromSettings(),
            );

            if ($state->state !== SuggestionState::Changed) {
                throw ValidationException::withMessages(['item' => 'La proyección de esta materia no tiene cambios pendientes.']);
            }

            $values = [
                'suggested_expected_students' => $forecast->expected_students,
                'suggested_groups' => $forecast->suggested_groups,
                'forecast_method' => $forecast->method,
            ];

            if ($choice === self::USE) {
                $values['expected_students'] = $forecast->expected_students;
                $values['requested_groups'] = $forecast->suggested_groups;
            }

            return $values;
        });
    }
}
