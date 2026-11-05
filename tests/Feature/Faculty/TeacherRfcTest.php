<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Templates\EvaluationsTemplate;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\PositionsTemplate;
use App\Modules\Imports\Templates\TeacherHistoryTemplate;
use App\Modules\Imports\Templates\TeachersTemplate;

/*
| El RFC es la clave natural y el usuario del docente (confirmado el 5 de noviembre de 2026): en el SII todo se liga
| por RFC y el número de tarjeta no identifica a nadie. Forma de un RFC de persona física, con homoclave o, con
| advertencia, sin ella.
*/

it('el usuario del docente es su RFC', function () {
    $teacher = Teacher::factory()->create(['rfc' => 'LOPA800101AB3']);

    app(SyncTeacherAccess::class)->handle($teacher);

    expect(User::query()->where('username', 'LOPA800101AB3')->exists())->toBeTrue()
        ->and($teacher->refresh()->user->username)->toBe('LOPA800101AB3');
});

it('rechaza un RFC con forma inválida en las plantillas de docentes', function (string $value) {
    $context = new ImportContext(User::factory()->admin()->create());
    $templates = [
        [new TeachersTemplate, ['name' => 'Ana López', 'department' => 'SC']],
        [new PositionsTemplate, ['kind' => 'Tiempo completo', 'base_hours' => '40', 'valid_from' => '01/01/2020']],
        [new TeacherHistoryTemplate, ['subject_code' => 'X', 'term_code' => '20251', 'group_code' => 'A']],
        [new EvaluationsTemplate, ['term_code' => '20251', 'kind' => 'estudiantil', 'score' => '4']],
    ];

    foreach ($templates as [$template, $rest]) {
        $row = $template->normalize(['rfc' => $value, ...$rest]);

        expect($template->validate($row, $context)->errors['rfc'] ?? null)->toBe(['El RFC no tiene la forma de un RFC.']);
    }
})->with([
    'número de tarjeta' => '1234567',
    'corto' => 'LOPA80',
    'fecha con letras' => 'LOPA8001AB',
    'largo' => 'LOPA800101AB3X',
    'con símbolos' => 'LOPA800101A-3',
]);

it('acepta un RFC sin homoclave con advertencia', function () {
    Department::factory()->create(['code' => 'SC']);
    $context = new ImportContext(User::factory()->admin()->create());
    $row = (new TeachersTemplate)->normalize(['rfc' => ' lopa 800101 ', 'name' => 'Ana López', 'department' => 'sc']);

    expect($row['rfc'])->toBe('LOPA800101');

    $issues = (new TeachersTemplate)->validate($row, $context);

    expect($issues->errors)->toBe([])
        ->and($issues->warnings)->toBe(['rfc' => ['el RFC no trae homoclave']]);
});

it('normaliza el RFC a mayúsculas sin espacios y acepta la Ñ y el &', function () {
    expect((new TeachersTemplate)->normalize(['rfc' => ' ñuñ 800101 ab3 '])['rfc'])->toBe('ÑUÑ800101AB3')
        ->and((new TeachersTemplate)->normalize(['rfc' => 'a&b800101ab3'])['rfc'])->toBe('A&B800101AB3');
});

it('la migración pasa los mapeos guardados de employee_number a rfc y los regresa', function () {
    $migration = require base_path('app/Modules/Faculty/Database/Migrations/2026_11_05_000001_rename_employee_number_to_rfc_on_teachers.php');
    $migration->down();

    $teachers = ImportMapping::factory()->create(['template' => 'teachers', 'columns' => ['RFC_SII' => 'employee_number', 'Nombre' => 'name']]);
    $rooms = ImportMapping::factory()->create(['template' => 'rooms', 'columns' => ['Clave' => 'employee_number']]);

    $migration->up();

    expect($teachers->refresh()->columns)->toEqual(['RFC_SII' => 'rfc', 'Nombre' => 'name'])
        ->and($rooms->refresh()->columns)->toEqual(['Clave' => 'employee_number']);

    $migration->down();

    expect($teachers->refresh()->columns)->toEqual(['RFC_SII' => 'employee_number', 'Nombre' => 'name']);

    $migration->up();
});
