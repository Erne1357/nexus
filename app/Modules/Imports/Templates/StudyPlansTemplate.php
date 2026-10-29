<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Planes de estudio del SII (tarea 1.9): clave, carrera, versión y si está vigente. La clave es la natural. La
 * exportación no trae nombre: si el archivo no lo trae, el plan se llama como su clave hasta que se capture.
 * «Vigente» se reconoce sin importar mayúsculas ni acentos (sí, s, vigente; no, n, no vigente), y un plan que no
 * lo está nace inactivo.
 *
 * Como todo lo importado, no desactiva: un plan activo que el SII ya no marca vigente sigue activo, con
 * advertencia. Un plan tampoco cambia de carrera por importación, porque la llave compuesta de `students` haría
 * que sus alumnos cambiaran de carrera con él; eso es una edición a mano. No ofrece "desactivar faltantes": los
 * planes son globales y la exportación puede ser de un solo campus. Busca cada carrera una vez por corrida.
 */
class StudyPlansTemplate extends ImportTemplate
{
    /** @var list<string> lo que significa «vigente», como slug */
    private const array CURRENT = ['si', 's', 'vigente', '1', 'true', 'x'];

    /** @var list<string> lo que significa «no vigente», como slug */
    private const array NOT_CURRENT = ['no', 'n', 'no_vigente', '0', 'false'];

    /** @var array<string, Career|null> carrera por clave, en esta corrida */
    private array $careers = [];

    public function key(): string
    {
        return 'study_plans';
    }

    public function label(): string
    {
        return 'Planes de estudio';
    }

    public function fields(): array
    {
        return [
            'code' => 'clave de plan',
            'career' => 'carrera',
            'name' => 'nombre',
            'version' => 'versión',
            'current' => 'vigente',
        ];
    }

    public function naturalKey(): array
    {
        return ['code'];
    }

    public function model(): string
    {
        return StudyPlan::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'career' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:20'],
            'current' => ['nullable', 'boolean'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['code', 'career', 'version'] as $field) {
            $row[$field] = self::asText($row[$field]);
        }

        foreach (['code', 'career'] as $field) {
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        $row['current'] = $this->current($row['current']);

        return $row;
    }

    /**
     * Como la base; en un alta sin nombre, el plan se llama como su clave.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        if ($row['name'] === null && ! StudyPlan::withTrashed()->where('code', $row['code'])->exists()) {
            $row['name'] = $row['code'];
        }

        return parent::apply($row, $context);
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);
        $career = $this->career($row['career']);

        if ($career === null) {
            return $issues->withError('career', "No hay una carrera con la clave {$row['career']}.");
        }

        if (! $career->is_active) {
            $issues = $issues->withWarning('career', "La carrera {$row['career']} está inactiva.");
        }

        $existing = StudyPlan::withTrashed()->where('code', $row['code'])->first(['career_id', 'is_active']);

        if ($existing !== null && $existing->career_id !== $career->id) {
            $current = Career::withTrashed()->whereKey($existing->career_id)->value('code');

            return $issues->withError('career', "El plan {$row['code']} es de la carrera {$current}; cambiarlo de carrera es una edición a mano.");
        }

        if ($existing !== null && $existing->is_active && $row['current'] === false) {
            $issues = $issues->withWarning('current', "El SII ya no marca vigente el plan {$row['code']}: sigue activo; desactívalo a mano si ya no se ofrece.");
        }

        return $issues;
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [
            'career_id' => $this->career($row['career'])?->id,
            'name' => $row['name'],
            'version' => $row['version'],
            'is_active' => $row['current'] ?? true,
        ];
    }

    /**
     * true o false si reconoce el valor de «vigente»; si no, el texto tal cual, y `rules()` lo reporta.
     */
    private function current(mixed $value): mixed
    {
        if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
            return $value;
        }

        $slug = self::slug((string) (is_bool($value) ? (int) $value : $value));

        return match (true) {
            in_array($slug, self::CURRENT, true) => true,
            in_array($slug, self::NOT_CURRENT, true) => false,
            default => $value,
        };
    }

    private function career(string $code): ?Career
    {
        return array_key_exists($code, $this->careers) ? $this->careers[$code] : $this->careers[$code] = Career::query()->where('code', $code)->first(['id', 'is_active']);
    }
}
