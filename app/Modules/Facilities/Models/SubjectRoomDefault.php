<?php

namespace App\Modules\Facilities\Models;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Database\Factories\SubjectRoomDefaultFactory;
use App\Modules\Facilities\Enums\RoomDefaultSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Salón por defecto o sugerido de una materia en un campus; el salón es de ese campus (llave compuesta).
 * La prioridad menor se prefiere.
 */
#[Fillable(['subject_id', 'campus_id', 'room_id', 'priority', 'source'])]
#[UseFactory(SubjectRoomDefaultFactory::class)]
class SubjectRoomDefault extends Model
{
    /** @use HasFactory<SubjectRoomDefaultFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'source' => RoomDefaultSource::class,
        ];
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
