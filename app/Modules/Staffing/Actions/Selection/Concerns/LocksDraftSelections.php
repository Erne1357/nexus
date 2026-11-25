<?php

namespace App\Modules\Staffing\Actions\Selection\Concerns;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Para las Actions de la elección previa (SEL-16): toma el candado consultivo (`staffing_selection:{id}`, en orden de id,
 * antes de cualquier bloqueo de fila) de las selecciones no canceladas del periodo y la modalidad y exige que sigan en
 * borrador; con una selección publicada o viva los turnos ya se avisaron y la marca no se toca.
 */
trait LocksDraftSelections
{
    /**
     * @param  list<string>|null  $departments  solo las selecciones cuyas cargas incluyen alguno de estos departamentos (null: todas)
     * @return Collection<int, Selection> las selecciones en borrador, ya bloqueadas
     *
     * @throws ValidationException
     */
    private function lockDraftSelections(Term $term, Modality $modality, ?array $departments): Collection
    {
        if ($departments === []) {
            return collect();
        }

        $ids = Selection::query()
            ->where('term_id', $term->getKey())->where('modality_id', $modality->getKey())
            ->where('status', '!=', SelectionStatus::Cancelled->value)
            ->when($departments !== null, fn ($query) => $query->whereHas('loads', fn ($loads) => $loads->whereIn('academic_loads.department_id', $departments)))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$id}"]);
        }

        $selections = Selection::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $notDraft = $selections->first(fn (Selection $selection): bool => $selection->status !== SelectionStatus::Draft);

        if ($notDraft !== null) {
            throw ValidationException::withMessages(['selection' => "La selección de {$notDraft->department()->value('name')} está {$notDraft->status->label()}: no se cambia una elección previa con la selección publicada o en curso; asigna los grupos a mano con «Asignación docente»."]);
        }

        return $selections;
    }
}
