<?php

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TemplateRegistry;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/**
 * Variante de la plantilla de prueba que trae `is_active` desde el archivo, como lo haría el
 * catálogo de docentes del SII.
 */
class CampusesWithActiveTemplate extends CampusesTemplate
{
    public function fields(): array
    {
        return [...parent::fields(), 'is_active' => 'activo'];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'is_active' => ['required', 'boolean']];
    }
}

function campusImportContext(): ImportContext
{
    return new ImportContext(User::factory()->admin()->create());
}

function campusImportRow(array $overrides = []): array
{
    return [
        'code' => 'C9',
        'name' => 'Campus Nueve',
        'modality' => Modality::Presencial->value,
        ...$overrides,
    ];
}

describe('validate', function () {
    it('una fila válida no trae errores ni advertencias', function () {
        $issues = (new CampusesTemplate)->validate(campusImportRow(), campusImportContext());

        expect($issues->errors)->toBe([])
            ->and($issues->warnings)->toBe([]);
    });

    it('da los errores de las reglas en español, por campo y con la etiqueta del campo', function () {
        $issues = (new CampusesTemplate)->validate(
            campusImportRow(['name' => null, 'modality' => 'a_distancia']),
            campusImportContext(),
        );

        expect($issues->errors)->toBe([
            'name' => ['El campo nombre es obligatorio.'],
            'modality' => ['El campo modalidad no está en la lista de valores permitidos.'],
        ]);
    });

    it('si las reglas fallan no revisa la fila contra la base', function () {
        Campus::factory()->create(['code' => 'C9'])->delete();

        $issues = (new CampusesTemplate)->validate(campusImportRow(['name' => null]), campusImportContext());

        expect($issues->errors)->toHaveKeys(['name'])
            ->and($issues->errors)->not->toHaveKey('code');
    });

    it('un registro borrado con la misma clave es error: la importación no lo restaura', function () {
        Campus::factory()->create(['code' => 'C9'])->delete();

        $issues = (new CampusesTemplate)->validate(campusImportRow(), campusImportContext());

        expect($issues->errors)->toBe([
            'code' => ['Hay un registro borrado con esta clave; una importación no lo restaura.'],
        ]);
    });

    it('un registro inactivo con la misma clave es advertencia: se actualiza, pero sigue inactivo', function () {
        Campus::factory()->inactive()->create(['code' => 'C9']);

        $issues = (new CampusesTemplate)->validate(campusImportRow(), campusImportContext());

        expect($issues->errors)->toBe([])
            ->and($issues->warnings)->toBe([
                'code' => ['El registro está inactivo: se actualizan sus datos, pero sigue inactivo.'],
            ]);
    });
});

describe('apply', function () {
    it('da de alta el registro con sus valores de alta y activo', function () {
        $campus = (new CampusesTemplate)->apply(campusImportRow(), campusImportContext());

        expect($campus->wasRecentlyCreated)->toBeTrue()
            ->and($campus->fresh())
            ->code->toBe('C9')
            ->name->toBe('Campus Nueve')
            ->is_active->toBeTrue()
            ->settings->not->toBeEmpty();
    });

    it('cambia el registro existente por su clave natural sin duplicarlo ni pisar lo que no viene', function () {
        $existing = Campus::factory()->create(['code' => 'C9', 'name' => 'Viejo', 'settings' => ['days' => [1, 2, 3, 4, 5, 6]]]);

        $campus = (new CampusesTemplate)->apply(campusImportRow(['name' => 'Nuevo']), campusImportContext());

        expect($campus->is($existing))->toBeTrue()
            ->and($campus->wasRecentlyCreated)->toBeFalse()
            ->and($campus->wasChanged('name'))->toBeTrue()
            ->and(Campus::count())->toBe(1)
            ->and($existing->fresh()->name)->toBe('Nuevo')
            ->and($existing->fresh()->settings)->toBe(['days' => [1, 2, 3, 4, 5, 6]]);
    });

    it('aplicar dos veces la misma fila no cambia nada la segunda vez', function () {
        $template = new CampusesTemplate;
        $template->apply(campusImportRow(), campusImportContext());

        $campus = $template->apply(campusImportRow(), campusImportContext());

        expect($campus->wasRecentlyCreated)->toBeFalse()
            ->and($campus->wasChanged())->toBeFalse()
            ->and(Campus::count())->toBe(1);
    });

    it('nunca reactiva un registro inactivo, aunque el archivo lo traiga activo', function () {
        $existing = Campus::factory()->inactive()->create(['code' => 'C9']);

        (new CampusesWithActiveTemplate)->apply(campusImportRow(['is_active' => true]), campusImportContext());

        expect($existing->fresh()->is_active)->toBeFalse();
    });

    it('un alta respeta el is_active que trae el archivo', function () {
        $campus = (new CampusesWithActiveTemplate)->apply(campusImportRow(['is_active' => false]), campusImportContext());

        expect($campus->fresh()->is_active)->toBeFalse();
    });

    it('un registro borrado no se restaura ni se duplica aunque se llame sin validar', function () {
        Campus::factory()->create(['code' => 'C9'])->delete();

        (new CampusesTemplate)->apply(campusImportRow(), campusImportContext());
    })->throws(LogicException::class, 'borrado');

    it('no deja bitácora por registro: la deja el lote al aplicarse', function () {
        (new CampusesTemplate)->apply(campusImportRow(), campusImportContext());

        expect(Activity::count())->toBe(0);
    });
});

it('el módulo registra el registro de plantillas como singleton', function () {
    expect(app(TemplateRegistry::class))->toBeInstanceOf(TemplateRegistry::class)
        ->and(app(TemplateRegistry::class))->toBe(app(TemplateRegistry::class))
        ->and(app()->getProviders(ImportsServiceProvider::class))->not->toBeEmpty();
});
