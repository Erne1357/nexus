<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\HeldPositionMailFactory;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Notifications\PositionRef;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El correo de un aviso de puesto que espera a que su puesto tenga buzón (CE-02): la notificación serializada, a quién
 * iba y el puesto. Solo se agrega y se marca liberado o fallido; no lleva `created_at` ni `updated_at`.
 */
#[Fillable(['user_id', 'role', 'org_unit_id', 'career_id', 'campus_id', 'modality_id', 'kind', 'subject', 'notification', 'held_at', 'released_at', 'failed_at'])]
#[UseFactory(HeldPositionMailFactory::class)]
class HeldPositionMail extends Model
{
    /** @use HasFactory<HeldPositionMailFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'held_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Los que siguen en espera: ni liberados ni fallidos.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->whereNull('released_at')->whereNull('failed_at');
    }

    public function position(): PositionRef
    {
        return PositionRef::fromColumns($this->role, $this->only(PositionRef::COLUMNS));
    }
}
