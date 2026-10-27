<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Enums\SettingScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Parámetro editable sin despliegue (pesos del ranking, tolerancia de horas, cupos por omisión).
 * `value` es json: admite escalares y estructuras.
 */
#[Fillable(['scope', 'scope_id', 'key', 'value'])]
class Setting extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => SettingScope::class,
            'value' => 'json',
        ];
    }
}
