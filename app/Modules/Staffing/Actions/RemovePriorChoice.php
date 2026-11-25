<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Actions\Selection\Concerns\LocksDraftSelections;
use App\Modules\Staffing\Actions\Selection\RecomputeSelectionOrder;
use App\Modules\Staffing\Models\PriorChoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEL-16: quita la marca de «Elección previa» del docente en el periodo y la modalidad, solo con todas las selecciones
 * del periodo en borrador. El siguiente cálculo de la cola (que esta Action hace de una vez) lo devuelve a la fila:
 * `prior_choice_at` nulo y estado `waiting`. No desasigna sus grupos (eso lo hace el jefe con «Asignación docente»).
 * Sin marca no hace nada. Deja bitácora (`prior_choice_removed`).
 */
final class RemovePriorChoice
{
    use LocksDraftSelections;

    public function __construct(private readonly RecomputeSelectionOrder $recompute) {}

    /**
     * @return bool si había marca que quitar
     *
     * @throws ValidationException
     */
    public function handle(Term $term, Modality $modality, Teacher $teacher, User $actor): bool
    {
        return DB::transaction(function () use ($term, $modality, $teacher, $actor): bool {
            $selections = $this->lockDraftSelections($term, $modality, null);

            $mark = PriorChoice::query()
                ->where('term_id', $term->getKey())->where('modality_id', $modality->getKey())->where('teacher_id', $teacher->getKey())
                ->lockForUpdate()->first();

            if ($mark === null) {
                return false;
            }

            $mark->delete();

            activity()
                ->performedOn($teacher)
                ->causedBy($actor)
                ->event('prior_choice_removed')
                ->withProperties(['teacher_id' => $teacher->getKey(), 'term_id' => $term->getKey(), 'modality_id' => $modality->getKey()])
                ->log("Quitó la elección previa de {$teacher->name} en {$term->name}");

            foreach ($selections as $selection) {
                $this->recompute->handle($selection);
            }

            return true;
        });
    }
}
