<?php

namespace Tests\Fixtures\ImportTemplates;

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\ImportTemplate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Plantilla de prueba sobre Campus para las pruebas del contrato de Imports, y ejemplo de cómo se
 * escribe una plantilla real en `app/Modules/Imports/Templates/`. Los campus no se importan: se usa
 * Campus porque ya existe y tiene lo que tiene todo catálogo (clave natural, `is_active` y borrado
 * lógico).
 */
class CampusesTemplate extends ImportTemplate
{
    public function key(): string
    {
        return 'test-campuses';
    }

    public function label(): string
    {
        return 'Campus (prueba)';
    }

    public function fields(): array
    {
        return [
            'code' => 'clave',
            'name' => 'nombre',
            'modality' => 'modalidad',
        ];
    }

    public function naturalKey(): array
    {
        return ['code'];
    }

    public function model(): string
    {
        return Campus::class;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:120'],
            'modality' => ['required', Rule::enum(Modality::class)],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);
        $row['code'] = $row['code'] === null ? null : Str::upper($row['code']);

        return $row;
    }

    protected function defaults(ImportContext $context): array
    {
        return ['settings' => CampusProfile::fromArray([])->toArray()];
    }
}
