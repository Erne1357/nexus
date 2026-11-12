<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use Illuminate\Support\Facades\DB;

/*
| La migración que quita `heads_edit_rooms` del perfil de flujo guardado (decisión 5, confirmada el 12 de noviembre de
| 2026: el jefe corrige los salones de su departamento en cualquier campus). Lo demás del perfil se queda igual.
*/

it('quita heads_edit_rooms de campuses.settings y conserva lo demás', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2', 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    DB::table('campuses')->where('id', $c1->id)->update(['settings' => json_encode([...CampusProfile::DEFAULTS, 'heads_edit_rooms' => true, 'days' => [1, 2, 3, 4, 5, 6]])]);

    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_000010_remove_heads_edit_rooms_from_campus_settings.php'))->up();

    expect($c1->fresh()->settings)->not->toHaveKey('heads_edit_rooms')
        ->and($c1->fresh()->profile->days)->toBe([1, 2, 3, 4, 5, 6])
        ->and($c2->fresh()->profile->usesRooms)->toBeFalse();
});
