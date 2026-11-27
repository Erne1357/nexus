<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Staffing\Models\ChangeRequest;

/**
 * Lo que dejó MarkChangeRequestApplied: la solicitud aplicada; si la carga volvió a asignación, y, si Scheduling lo
 * vetó, los motivos para mostrarlos.
 */
final readonly class AppliedChangeRequest
{
    /**
     * @param  list<string>  $vetoReasons
     */
    public function __construct(public ChangeRequest $request, public bool $returnedToStaffing, public array $vetoReasons = []) {}
}
