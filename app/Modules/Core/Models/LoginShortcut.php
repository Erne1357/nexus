<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\LoginShortcutFactory;
use App\Modules\Core\Enums\LoginShortcutKind;
use App\Modules\Core\Models\Concerns\HasVisibilityWindow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Atajo de la pantalla de acceso (LG-02): un enlace o una descarga que se consulta sin iniciar sesión,
 * con orden y vigencia.
 */
#[Fillable(['kind', 'title', 'description', 'url', 'file_path', 'sort_order', 'starts_at', 'ends_at', 'is_active'])]
#[UseFactory(LoginShortcutFactory::class)]
class LoginShortcut extends Model
{
    /** @use HasFactory<LoginShortcutFactory> */
    use HasFactory, HasUuids, HasVisibilityWindow, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LoginShortcutKind::class,
            'sort_order' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }
}
