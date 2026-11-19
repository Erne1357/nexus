<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Queries\PendingPlacements;
use App\Modules\Scheduling\Queries\PendingPlacementSet;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Support\ChangeAlternatives;
use Illuminate\Support\Facades\Gate;

/**
 * «Por colocar» en el editor de horarios (tarea 8.7): con `?solicitud=<id>`, la alternativa del motor que eligió el
 * jefe en una solicitud de cambio aprobada, para quien quiera acomodarla a mano en lugar de «Aplicar la alternativa».
 * Solo la ve quien ve la solicitud (ChangeRequestPolicy::view); una solicitud que no está aprobada o que no se aprobó
 * con una alternativa no trae nada. Cada grupo de la cadena con sus sesiones de antes a después, como en la pantalla
 * de solicitudes (ChangeAlternativesQuery).
 */
final class ChosenAlternativePlacementsQuery implements PendingPlacements
{
    public function __construct(private readonly ChangeAlternativesQuery $alternatives) {}

    public function for(Actor $context, string $key): ?PendingPlacementSet
    {
        $user = $context->user();
        $request = $user === null ? null : KeyLookup::find(ChangeRequest::query(), $key);

        if ($request === null || $request->status !== ChangeRequestStatus::Approved || ChangeAlternatives::chosen($request->alternatives) === null
            || ! Gate::forUser($user)->allows('view', $request)) {
            return null;
        }

        $view = $this->alternatives->for($request);
        $option = $view['selected'];
        $requested = collect($option['chain'])->firstWhere('requested', true);

        return new PendingPlacementSet(
            title: "{$option['name']} de la solicitud de cambio de ".($requested['label'] ?? 'un grupo'),
            note: 'El jefe del departamento la aprobó. Colócala a mano aquí, o escríbela de una vez con «Aplicar la alternativa» en la solicitud; después márcala como aplicada.',
            backUrl: route('staffing.change-requests', ['solicitud' => $request->id]),
            groups: array_map(fn (array $row): array => [
                'group_id' => $row['group_id'],
                'label' => $row['label'],
                'subject' => $row['subject'],
                'moves' => $row['moves'],
                'same' => $row['same'],
            ], $option['chain']),
        );
    }
}
