<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\MailLogFactory;
use App\Modules\Core\Enums\MailStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un correo que salió de Nexus (CF-S8), sin su cuerpo. Solo se agrega: no lleva `created_at` ni `updated_at`.
 * `is_blocked` dice si es un correo que la guardia de CE-01 no dejó salir (fallido con MailStatus::BLOCKED_PREFIX).
 *
 * @property string $to
 * @property-read bool $is_blocked
 */
#[Table('mail_log', timestamps: false)]
#[Fillable(['to', 'subject', 'kind', 'user_id', 'status', 'error', 'sent_at'])]
#[UseFactory(MailLogFactory::class)]
class MailLog extends Model
{
    /** @use HasFactory<MailLogFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MailStatus::class,
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isBlocked(): Attribute
    {
        return Attribute::get(fn (): bool => $this->status === MailStatus::Failed && str_starts_with((string) $this->error, MailStatus::BLOCKED_PREFIX));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
