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
 * El correo de un aviso de puesto que espera a que su puesto tenga buzón (CE-02): la notificación serializada y cifrada,
 * a quién iba y el puesto. En espera: ni tomado para mandarse (`released_at`), ni fallido, ni descartado. Solo se agrega
 * y se marca; no lleva `created_at` ni `updated_at`.
 */
#[Fillable(['notification_id', 'user_id', 'role', 'org_unit_id', 'career_id', 'campus_id', 'modality_id', 'academic_load', 'kind', 'subject', 'notification', 'held_at', 'released_at', 'attempts', 'failed_at', 'discarded_at'])]
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
            'academic_load' => 'boolean',
            'attempts' => 'integer',
            'held_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
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
     * Los que siguen en espera: ni tomados para mandarse, ni fallidos, ni descartados.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->whereNull('released_at')->whereNull('failed_at')->whereNull('discarded_at');
    }

    /**
     * Los que fallaron y no se han descartado.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->whereNotNull('failed_at')->whereNull('discarded_at');
    }

    public function position(): PositionRef
    {
        return PositionRef::fromColumns($this->role, $this->only(PositionRef::COLUMNS));
    }
}
