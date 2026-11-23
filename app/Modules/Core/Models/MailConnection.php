<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\MailConnectionFactory;
use App\Modules\Core\Enums\MailProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La cuenta de Microsoft 365 con que sale el correo (tarea 1.29, ADR-0032, CF-S9). Los tokens van cifrados con APP_KEY
 * (cast `encrypted`): si APP_KEY cambia ya no se pueden leer y hay que reconectar. Nunca se serializan.
 *
 * @property string $account_email
 * @property string $access_token
 * @property string $refresh_token
 */
#[Table('mail_connections')]
#[Fillable(['provider', 'account_email', 'access_token', 'refresh_token', 'access_token_expires_at', 'connected_by', 'connected_at', 'last_used_at', 'last_error', 'expired_at'])]
#[Hidden(['access_token', 'refresh_token'])]
#[UseFactory(MailConnectionFactory::class)]
class MailConnection extends Model
{
    /** @use HasFactory<MailConnectionFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => MailProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
