<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Vuelve un parámetro a su valor por omisión (CF-S7) borrando la fila global de `settings`: todo lo que lee un parámetro
 * usa el valor de omisión cuando no hay fila, y así el valor por omisión sigue siendo el que declara el código y no una copia
 * guardada. Borrar la fila es a propósito: `settings` no es un catálogo y la bitácora guarda el valor anterior. Deja bitácora con el valor anterior y el de omisión. Si no había fila, no hace nada ni registra.
 */
final class ResetSetting
{
    public function handle(SettingDefinition $definition): void
    {
        DB::transaction(function () use ($definition): void {
            $setting = Setting::query()
                ->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', $definition->key)
                ->lockForUpdate()->first();

            if ($setting === null) {
                return;
            }

            $setting->delete();

            activity()
                ->performedOn($setting)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['attributes' => ['key' => $definition->key, 'value' => $definition->default], 'old' => ['key' => $definition->key, 'value' => $setting->value]])
                ->log("Volvió el parámetro «{$definition->label}» ({$definition->key}) a su valor por omisión");
        });
    }
}
