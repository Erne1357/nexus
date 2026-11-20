<?php

namespace App\Modules\CatalogValidation\Models;

use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un salón sin dueño que una revisión de salones reclamó para su departamento (CV16, confirmado el 20 de noviembre de
 * 2026). Vigente mientras `released_at` es null; soltarlo llena `released_at` y deja el salón sin dueño otra vez.
 */
#[Fillable(['catalog_review_id', 'room_id', 'claimed_by', 'claimed_at', 'released_at'])]
class CatalogReviewRoomClaim extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * Los reclamos vigentes: los que no se han soltado.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('released_at');
    }

    /**
     * @return BelongsTo<CatalogReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(CatalogReview::class, 'catalog_review_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Quién lo reclamó.
     *
     * @return BelongsTo<User, $this>
     */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
