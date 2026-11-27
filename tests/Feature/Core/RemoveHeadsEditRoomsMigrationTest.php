<?php

use App\Modules\Core\Models\ModalityProfile;
use Illuminate\Support\Facades\DB;

/*
| La migración que quita `heads_edit_rooms` del perfil de flujo guardado (decisión 5, confirmada el 12 de noviembre de
| 2026: el jefe corrige los salones de su departamento en cualquier campus). Lo demás del perfil se queda igual. Se
| prueba sobre la forma de antes de contraer (`campuses.settings`): el `down()` de 2026_11_12_211102 y campus con perfil.
*/

beforeEach(function () {
    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_211102_contract_campuses_to_places.php'))->down();
});

/**
 * @return array<string, mixed>
 */
function storedCampusSettings(string $id): array
{
    return json_decode(DB::table('campuses')->where('id', $id)->value('settings'), true);
}

it('quita heads_edit_rooms de campuses.settings y conserva lo demás', function () {
    $c1 = campusBeforeContraction('C1', settings: [...ModalityProfile::DEFAULTS, 'heads_edit_rooms' => true, 'days' => [1, 2, 3, 4, 5, 6]]);
    $c2 = campusBeforeContraction('C2', settings: [...ModalityProfile::DEFAULTS, 'uses_rooms' => false]);

    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_000010_remove_heads_edit_rooms_from_campus_settings.php'))->up();

    expect(storedCampusSettings($c1->id))->not->toHaveKey('heads_edit_rooms')
        ->and(storedCampusSettings($c1->id)['days'])->toBe([1, 2, 3, 4, 5, 6])
        ->and(storedCampusSettings($c2->id)['uses_rooms'])->toBeFalse();
});
