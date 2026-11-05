<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Unidades del organigrama (`org_units`) por clave: nombre, tipo, unidad madre y estado. El padre es la clave de una
 * unidad que ya existe, activa o no (un departamento académico lo es), así que un archivo no puede colgar una unidad
 * de otra de su mismo lote: la migración del SII importa primero los departamentos.
 *
 * Nunca crea ni modifica una unidad de tipo `departamento_academico`: esa unidad nace con el departamento y solo se
 * edita desde él (ADR-0018, triggers de `departments`). Una fila con esa clave o ese tipo es error.
 *
 * `active` funciona como en departamentos y docentes: un alta con «No» nace inactiva; un cambio nunca reactiva ni
 * desactiva. No ofrece "desactivar faltantes": el archivo no trae el organigrama completo.
 */
class OrgUnitsTemplate extends ImportTemplate
{
    use ParsesActiveFlag;

    public function key(): string
    {
        return 'org_units';
    }

    public function label(): string
    {
        return 'Unidades del organigrama';
    }

    public function fields(): array
    {
        return [
            'code' => 'clave',
            'name' => 'nombre',
            'kind' => 'tipo',
            'parent' => 'padre',
            'active' => 'activo',
        ];
    }

    public function naturalKey(): array
    {
        return ['code'];
    }

    public function model(): string
    {
        return OrgUnit::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(OrgUnitKind::class)->except(OrgUnitKind::DepartamentoAcademico)],
            'parent' => ['required', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['code', 'parent'] as $field) {
            $row[$field] = self::asText($row[$field]);
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        $row['kind'] = is_string($row['kind']) ? self::slug($row['kind']) : $row['kind'];
        $row['active'] = self::activeFlag($row['active']);

        return $row;
    }

    protected function messages(): array
    {
        return ['kind.Illuminate\Validation\Rules\Enum' => 'El tipo no es válido.'];
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        if (OrgUnit::withTrashed()->where('code', $row['code'])->where('kind', OrgUnitKind::DepartamentoAcademico->value)->exists()) {
            return $issues->withError('code', 'La clave es de un departamento académico: su unidad se edita desde el departamento.');
        }

        $parent = OrgUnit::query()->where('code', $row['parent'])->orderByRaw("kind = 'departamento_academico' desc")->first();

        if ($parent === null) {
            return $issues->withError('parent', "No hay una unidad con la clave {$row['parent']}.");
        }

        $issues = parent::check($row, $context, $issues);
        $existing = OrgUnit::query()->where('code', $row['code'])->first();

        if ($existing === null) {
            return $issues;
        }

        if ($this->descendsFrom($parent, $existing)) {
            return $issues->withError('parent', 'La unidad no puede colgar de sí misma ni de una de sus descendientes.');
        }

        if ($row['active'] === false && $existing->is_active) {
            $issues = $issues->withWarning('active', 'El archivo la marca inactiva, pero la unidad sigue activa: una importación no la desactiva.');
        }

        return $issues;
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [
            'name' => $row['name'],
            'kind' => $row['kind'],
            'parent_id' => OrgUnit::query()->where('code', $row['parent'])->orderByRaw("kind = 'departamento_academico' desc")->value('id'),
            'is_active' => $row['active'] ?? true,
        ];
    }

    /**
     * Si `$unit` es `$ancestor` o cuelga de ella.
     */
    private function descendsFrom(OrgUnit $unit, Model $ancestor): bool
    {
        $seen = [];

        for ($current = $unit; $current !== null && ! isset($seen[$current->id]); $current = $current->parent_id === null ? null : OrgUnit::withTrashed()->find($current->parent_id)) {
            if ($current->is($ancestor)) {
                return true;
            }

            $seen[$current->id] = true;
        }

        return false;
    }
}
