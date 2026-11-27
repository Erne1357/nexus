<?php

namespace App\Modules\Students\Models;

use App\Modules\Core\Models\User;
use App\Modules\Students\Database\Factories\CredentialRequestFactory;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Enums\RepositionReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de credencial de un alumno (CO-07, CO-08): reposición de la credencial física, foto o las dos. Las
 * transiciones las hacen las Actions; el modelo solo guarda y consulta.
 */
#[Fillable(['student_id', 'wants_reposition', 'wants_photo', 'status', 'requested_at', 'scheduled_at', 'place', 'ready_at', 'delivered_at', 'rejected_at', 'rejection_reason', 'cancelled_at', 'handled_by', 'photo_updated_at', 'reposition_reason', 'siisae_photo_visible', 'siisae_data_correct'])]
#[UseFactory(CredentialRequestFactory::class)]
class CredentialRequest extends Model
{
    /** @use HasFactory<CredentialRequestFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wants_reposition' => 'boolean',
            'wants_photo' => 'boolean',
            'status' => CredentialRequestStatus::class,
            'requested_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'photo_updated_at' => 'datetime',
            'reposition_reason' => RepositionReason::class,
            'siisae_photo_visible' => 'boolean',
            'siisae_data_correct' => 'boolean',
        ];
    }

    /**
     * CO-12: la foto va en una reposición solo porque el alumno dijo que el SIISAE no se la muestra (aunque la casilla de
     * la foto también la hubiera marcado él).
     */
    public function photoAddedBySiisae(): bool
    {
        return $this->wants_reposition && $this->wants_photo && $this->siisae_photo_visible === false;
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (CredentialRequestStatus $status): string => $status->value, CredentialRequestStatus::open()));
    }
}
