<?php

use App\Modules\Core\Enums\Modality as ModalityEnum;
use App\Modules\Core\Enums\StaffingMode;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| La migración que crea el catálogo de modalidades (MS1 y MS2, supuestos por confirmar; ADR-0025). Siembra `PRES`
| (activa, con el perfil de los campus presenciales activos sin `heads_edit_rooms`), `EAD`, `ENL` y `TNMV` (inactivas,
| solo con lo documentado), y agrega el puente `campuses.modality_id`, que llena por la modalidad de cada campus. Si
| los campus presenciales activos difieren en otra clave del perfil, se detiene, las lista y no deja nada. Se prueba
| sobre la forma vieja: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    // Las tablas de la carga, la apertura y el calendario del periodo, los nombramientos y la modalidad del alumno
    // apuntan al catálogo: sus migraciones, posteriores, se deshacen primero, de la última a la primera.
    (require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_12_210601_make_career_proposals_per_modality.php'))->down();
    (require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_12_210501_make_academic_loads_per_modality.php'))->down();
    (require base_path('app/Modules/Staffing/Database/Migrations/2026_11_12_210403_add_modality_to_staffing_tables.php'))->down();
    (require base_path('app/Modules/Scheduling/Database/Migrations/2026_11_12_210402_add_modality_to_groups.php'))->down();
    (require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_12_210401_add_modality_to_load_tables.php'))->down();
    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_210301_open_terms_per_modality.php'))->down();
    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_210201_add_modality_to_role_assignments.php'))->down();
    (require base_path('app/Modules/Students/Database/Migrations/2026_11_12_210101_replace_student_modality_with_modality_id.php'))->down();
    $this->migration = require base_path('app/Modules/Core/Database/Migrations/2026_11_12_210001_create_modalities_table.php');
    $this->migration->down();
});

/**
 * Inserta un campus con la forma de antes del catálogo (modalidad como texto y perfil en `settings`) y devuelve su id.
 *
 * @param  array<string, mixed>  $settings
 */
function campusBeforeModalities(string $code, string $modality, array $settings = [], bool $active = true, ?string $deletedAt = null): string
{
    $id = (string) Str::uuid7();

    DB::table('campuses')->insert([
        'id' => $id,
        'code' => $code,
        'name' => "Campus {$code}",
        'modality' => $modality,
        'settings' => json_encode($settings),
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => $deletedAt,
    ]);

    return $id;
}

it('siembra Presencial, EAD, En línea y TecNM Virtual', function () {
    $this->migration->up();

    expect(Modality::query()->pluck('name', 'code')->all())->toEqualCanonicalizing([
        'PRES' => 'Presencial',
        'EAD' => 'EAD',
        'ENL' => 'En línea',
        'TNMV' => 'TecNM Virtual',
    ])
        ->and(Modality::query()->where('is_active', true)->pluck('code')->all())->toBe(['PRES']);
});

it('el perfil de Presencial es el de los campus presenciales sin heads_edit_rooms', function () {
    campusBeforeModalities('C1', 'presencial', [...CampusProfile::DEFAULTS, 'requires_subdireccion_approval' => true, 'heads_edit_rooms' => true]);
    campusBeforeModalities('C2', 'presencial', [...CampusProfile::DEFAULTS, 'requires_subdireccion_approval' => true, 'heads_edit_rooms' => false]);
    campusBeforeModalities('C3', 'presencial', ['day_end' => '20:00'], active: false);
    campusBeforeModalities('C4', 'presencial', ['day_end' => '19:00'], deletedAt: '2026-11-01');
    campusBeforeModalities('EAD', 'mixta', ['days' => [1, 2, 3, 4, 5, 6]], active: false);

    $this->migration->up();

    $presencial = Modality::query()->where('code', 'PRES')->sole();

    expect($presencial->profile->requiresSubdireccionApproval)->toBeTrue()
        ->and($presencial->settings)->not->toHaveKey('heads_edit_rooms')
        ->and($presencial->settings)->toEqual(ModalityProfile::fromArray(['requires_subdireccion_approval' => true])->toArray());
});

it('sin campus presenciales activos, Presencial toma el perfil por omisión', function () {
    $this->migration->up();

    expect(Modality::query()->where('code', 'PRES')->sole()->settings)->toEqual(ModalityProfile::DEFAULTS);
});

it('se detiene si los campus presenciales difieren en otra clave (MS2)', function () {
    campusBeforeModalities('C1', 'presencial', ['day_end' => '21:00', 'heads_edit_rooms' => true]);
    campusBeforeModalities('C2', 'presencial', ['day_end' => '20:00']);

    expect(fn () => $this->migration->up())->toThrow(function (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('day_end', 'C1', 'C2')
            ->not->toContain('heads_edit_rooms');
    });

    expect(Schema::hasTable('modalities'))->toBeFalse()
        ->and(Schema::hasColumn('campuses', 'modality_id'))->toBeFalse();
});

it('se detiene y nombra el campus si el perfil de un campus presencial no es válido', function () {
    campusBeforeModalities('C1', 'presencial');
    campusBeforeModalities('C2', 'presencial', ['day_end' => '24:00']);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'El perfil de flujo del campus C2 no es válido');

    expect(Schema::hasTable('modalities'))->toBeFalse();
});

it('perfiles iniciales de las otras modalidades (MS2)', function () {
    $this->migration->up();

    $profiles = Modality::query()->get()->mapWithKeys(fn (Modality $modality): array => [$modality->code => $modality->profile]);

    expect($profiles['EAD'])
        ->days->toBe([1, 2, 3, 4, 5, 6])
        ->usesRooms->toBeTrue()
        ->staffingMode->toBe(StaffingMode::Central)
        ->and($profiles['ENL'])
        ->usesRooms->toBeFalse()
        ->staffingMode->toBe(StaffingMode::Central)
        ->and($profiles['TNMV'])
        ->usesRooms->toBeFalse()
        ->usesTimeBlocks->toBeFalse()
        ->allowExternalTeachers->toBeTrue()
        ->staffingMode->toBe(StaffingMode::Central);

    Modality::query()->get()->each(fn (Modality $modality) => expect($modality->settings)->toHaveKeys(array_keys(ModalityProfile::DEFAULTS)));
});

it('llena campuses.modality_id por la modalidad de cada campus', function () {
    campusBeforeModalities('C1', 'presencial');
    campusBeforeModalities('C2', 'presencial');
    campusBeforeModalities('EAD', 'mixta', active: false);
    campusBeforeModalities('ENL', 'en_linea', active: false);
    campusBeforeModalities('TNMV', 'virtual_tecnm', active: false, deletedAt: '2026-11-01');

    $this->migration->up();

    expect(Campus::withTrashed()->with('catalogModality')->get()->mapWithKeys(fn (Campus $campus): array => [$campus->code => $campus->catalogModality->code])->all())
        ->toEqualCanonicalizing(['C1' => 'PRES', 'C2' => 'PRES', 'EAD' => 'EAD', 'ENL' => 'ENL', 'TNMV' => 'TNMV']);
});

it('el código es único aun entre borradas', function () {
    $this->migration->up();

    Modality::query()->where('code', 'PRES')->sole()->delete();

    expect(Modality::withTrashed()->where('code', 'PRES')->sole()->trashed())->toBeTrue()
        ->and(fn () => DB::transaction(fn () => Modality::factory()->create(['code' => 'PRES'])))->toThrow(UniqueConstraintViolationException::class);
});

it('expone el perfil de settings y lee el nuevo en la misma instancia', function () {
    $this->migration->up();

    $modality = Modality::factory()->create();

    expect($modality->code)->toMatch('/^M\d{3}$/')
        ->and($modality->is_active)->toBeTrue()
        ->and($modality->profile->toArray())->toBe(ModalityProfile::DEFAULTS)
        ->and(Modality::factory()->inactive()->create()->is_active)->toBeFalse();

    $modality->settings = [...$modality->settings, 'uses_rooms' => false];

    expect($modality->profile->usesRooms)->toBeFalse();
});

it('cada modalidad del enum apunta a su clave del catálogo (puente hasta contraer)', function (ModalityEnum $modality, string $code) {
    expect($modality->catalogCode())->toBe($code);
})->with([
    'presencial' => [ModalityEnum::Presencial, 'PRES'],
    'mixta' => [ModalityEnum::Mixta, 'EAD'],
    'en línea' => [ModalityEnum::EnLinea, 'ENL'],
    'TecNM Virtual' => [ModalityEnum::VirtualTecnm, 'TNMV'],
]);
