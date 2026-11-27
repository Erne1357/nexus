<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\LoginNoticeFactory;
use App\Modules\Core\Models\Concerns\HasVisibilityWindow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aviso del carrusel de la pantalla de acceso (LG-01): título, foto opcional en el disco público y
 * enlace opcional, con orden y vigencia.
 */
#[Fillable(['title', 'image_path', 'link_url', 'sort_order', 'starts_at', 'ends_at', 'is_active'])]
#[UseFactory(LoginNoticeFactory::class)]
class LoginNotice extends Model
{
    /** @use HasFactory<LoginNoticeFactory> */
    use HasFactory, HasUuids, HasVisibilityWindow, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }
}
