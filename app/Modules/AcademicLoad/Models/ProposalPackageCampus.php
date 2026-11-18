<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\AcademicLoad\Database\Factories\ProposalPackageCampusFactory;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuántos paquetes de un tipo abre una columna de la propuesta (tarea 5.6b): `count` es lo que vale y `suggested_count`
 * lo que sugiere Nexus (los paquetes que cubren el nuevo ingreso esperado, en el primer paquete). `captured_at` nulo es
 * «sin tocar»: sigue solo a lo sugerido. `campus_id` es nulo solo en una modalidad sin salones (MS5).
 */
#[Fillable(['proposal_package_id', 'campus_id', 'suggested_count', 'count', 'captured_at', 'captured_by'])]
#[UseFactory(ProposalPackageCampusFactory::class)]
class ProposalPackageCampus extends Model
{
    /** @use HasFactory<ProposalPackageCampusFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suggested_count' => 'integer',
            'count' => 'integer',
            'captured_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProposalPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ProposalPackage::class, 'proposal_package_id');
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
