<?php

namespace Tests\Fixtures\ImportTemplates;

use App\Modules\Core\Models\Campus;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\ImportTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Plantilla de prueba sobre Campus para las pruebas del contrato de Imports, y ejemplo de cómo se
 * escribe una plantilla real en `app/Modules/Imports/Templates/`. Los campus no se importan: se usa
 * Campus porque ya existe y tiene lo que tiene todo catálogo (clave natural, `is_active` y borrado
 * lógico). `modality` es un campo de lista que se valida y no se guarda (el campus es solo el lugar, MS3): sirve para
 * probar los errores de un valor fuera de la lista. Un alta nace activa por defaults(), que no pisa un cambio: un campus
 * desactivado sigue así.
 */
class CampusesTemplate extends ImportTemplate
{
    /** Los valores que acepta la columna `modalidad`. */
    public const array MODALITIES = ['presencial', 'mixta', 'en_linea', 'virtual_tecnm'];

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
            'modality' => ['required', Rule::in(self::MODALITIES)],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);
        $row['code'] = $row['code'] === null ? null : Str::upper($row['code']);

        return $row;
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return Arr::except($row, ['modality']);
    }

    protected function defaults(ImportContext $context): array
    {
        return ['is_active' => true];
    }
}
