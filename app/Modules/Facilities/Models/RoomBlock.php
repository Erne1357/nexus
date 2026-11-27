<?php

namespace App\Modules\Facilities\Models;

use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Database\Factories\RoomBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bloqueo de un salón (mantenimiento, reservado) en un día de 1 (lunes) a 6 (sábado) y un rango de horas.
 * Sin periodo aplica a todos.
 */
#[Fillable(['room_id', 'term_id', 'day', 'starts_at', 'ends_at', 'reason'])]
#[UseFactory(RoomBlockFactory::class)]
class RoomBlock extends Model
{
    /** @use HasFactory<RoomBlockFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => 'integer'];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
