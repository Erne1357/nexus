<?php

namespace App\Modules\Core\Actions\Concerns;

use App\Modules\Core\Models\PermissionSet;
use Illuminate\Support\Facades\DB;

/**
 * Para las Actions que guardan la lista propia de un rol (CF-01, CF-02): cambia sus filas de
 * `permission_set_permissions` por las claves dadas. Se llama dentro de la transacción de la Action, que ya revisó
 * las claves (PermissionSetRules) y deja la bitácora.
 */
trait ReplacesPermissionSetKeys
{
    /**
     * @param  list<string>  $keys
     */
    private function replaceKeys(PermissionSet $set, array $keys): void
    {
        DB::table('permission_set_permissions')->where('permission_set_id', $set->getKey())->delete();
        DB::table('permission_set_permissions')->insert(array_map(fn (string $key): array => ['permission_set_id' => $set->getKey(), 'permission' => $key], $keys));
    }
}
