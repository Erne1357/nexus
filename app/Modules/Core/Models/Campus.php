<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\CampusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Campus: el lugar físico donde se dan los grupos (C1, C2), solo con código, nombre y estado (M2; MS3, supuesto por
 * confirmar). La modalidad y el perfil de flujo son del catálogo `modalities` (MS1): el campus no decide qué aplica.
 */
#[Fillable(['code', 'name', 'is_active'])]
#[UseFactory(CampusFactory::class)]
class Campus extends Model
{
    /** @use HasFactory<CampusFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** La llave de sesión del código del último campus elegido en una pantalla de un solo campus (AZ-02). */
    public const string LAST_CHOSEN_KEY = 'core.last_campus';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
