<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajusta qué cargas entran en una selección en borrador (diseño 6.2): el admin quita o vuelve a poner cargas del
 * mismo periodo y modalidad; otras se ignoran.
 *
 * Toma primero el candado de la selección (`staffing_selection:{id}`).
 */
final class SetSelectionLoads
{
    /**
     * @param  list<string>  $loadIds  cargas que deben quedar incluidas
     *
     * @throws ValidationException
     */
    public function handle(Selection $selection, array $loadIds, User $actor): void
    {
        DB::transaction(function () use ($selection, $loadIds, $actor): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$selection->getKey()}"]);

            $selection->setRawAttributes(Selection::query()->lockForUpdate()->findOrFail($selection->getKey())->getAttributes(), sync: true);

            if ($selection->status !== SelectionStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Solo se ajustan las cargas mientras la selección está en borrador.']);
            }

            $candidates = AcademicLoad::query()
                ->where('term_id', $selection->term_id)
                ->where('modality_id', $selection->modality_id)
                ->whereKey($loadIds)
                ->pluck('id')
                ->all();

            $selection->loads()->sync($candidates);

            activity()
                ->performedOn($selection)
                ->causedBy($actor)
                ->event('selection_loads_set')
                ->withProperties(['loads' => count($candidates)])
                ->log('Ajustó las cargas incluidas en la selección: '.count($candidates));
        });
    }
}
