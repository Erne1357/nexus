<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una materia de un paquete (tarea 5.6b). Solo materias que tienen fila en la propuesta; una vez por paquete.
 */
#[Fillable(['proposal_package_id', 'subject_id'])]
class ProposalPackageSubject extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<ProposalPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ProposalPackage::class, 'proposal_package_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
