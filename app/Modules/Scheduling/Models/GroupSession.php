<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Database\Factories\GroupSessionFactory;
use App\Modules\Scheduling\Enums\SessionKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Franja de un grupo en un día (glosario, «Sesión»), de inicio a fin, con su salón y su tipo. El periodo, el campus,
 * el docente y si cuenta los copia la base del grupo (no son asignables): son los que miran las exclusiones de
 * HC-01 y HC-02.
 */
#[Fillable(['group_id', 'day', 'starts_at', 'ends_at', 'room_id', 'kind'])]
#[UseFactory(GroupSessionFactory::class)]
class GroupSession extends Model
{
    /** @use HasFactory<GroupSessionFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'kind' => SessionKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Duración de la sesión en minutos.
     *
     * @return Attribute<int, never>
     */
    protected function minutes(): Attribute
    {
        return Attribute::get(fn (): int => self::minutesOf($this->ends_at) - self::minutesOf($this->starts_at));
    }

    private static function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
