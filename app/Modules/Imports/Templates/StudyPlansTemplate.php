<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Planes de estudio del SII (tarea 1.9): clave, carrera, versión y si está vigente. La clave es la natural. La
 * exportación no trae nombre: si el archivo no lo trae, el plan se llama como su clave hasta que se capture.
 * «Vigente» se reconoce sin importar mayúsculas ni acentos (sí, s, vigente; no, n, no vigente; 1 y 0, también como
 * número de Excel), y un plan que no lo está, o de una carrera inactiva, nace inactivo.
 *
 * Como todo lo importado, no desactiva: un plan activo que el SII ya no marca vigente sigue activo, con
 * advertencia. Un plan tampoco cambia de carrera por importación, porque la llave compuesta de `students` haría
 * que sus alumnos cambiaran de carrera con él; eso es una edición a mano. No ofrece "desactivar faltantes": los
 * planes son globales y la exportación puede ser de un solo campus. Busca cada carrera una vez por corrida.
 *
 * «Nuevo ingreso» (`intake`, se reconoce como «vigente») marca el plan actual de la carrera, el que se asigna a los
 * alumnos de nuevo ingreso: una carrera tiene uno solo (D15, confirmado el 5 de noviembre de 2026). Si dos planes de
 * una carrera vienen marcados en el archivo, el posterior es error; si en la base el actual es otro, el importado
 * pasa a serlo y el otro deja de serlo, con advertencia que dice cuál (supuesto por confirmar). Un archivo que no lo
 * marca no cambia cuál es el actual.
 *
 * «Créditos» (`credits`, opcional) son los créditos totales del plan (`total_credits`), contra los que se mide el
 * avance del alumno, como en el SII (decidido el 10 de noviembre de 2026). Vacíos no borran los capturados.
 *
 * «Histórica» (`historical`, opcional, se reconoce como «vigente») marca la retícula histórica (`is_historical`, D18,
 * confirmado el 10 de noviembre de 2026): ningún alumno inscrito en el periodo actual. Vacía no la cambia.
 */
class StudyPlansTemplate extends ImportTemplate
{
    /** @var list<string> lo que significa «vigente», como slug */
    private const array CURRENT = ['si', 's', 'vigente', '1', 'true', 'x'];

    /** @var list<string> lo que significa «no vigente», como slug */
    private const array NOT_CURRENT = ['no', 'n', 'no_vigente', '0', 'false'];

    /** @var array<string, Career|null> carrera por clave, en esta corrida */
    private array $careers = [];

    /** @var array<string, string> clave del plan marcado de nuevo ingreso por carrera, en esta corrida */
    private array $intakePlans = [];

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
            'intake' => 'nuevo ingreso',
            'credits' => 'créditos',
            'historical' => 'histórica',
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
            'intake' => ['nullable', 'boolean'],
            'credits' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'historical' => ['nullable', 'boolean'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['code', 'career', 'version', 'credits'] as $field) {
            $row[$field] = self::asText($row[$field]);
        }

        foreach (['code', 'career'] as $field) {
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        $row['current'] = $this->current($row['current']);
        $row['intake'] = $this->current($row['intake']);
        $row['historical'] = $this->current($row['historical']);

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

        if ($row['intake'] === true) {
            StudyPlan::query()
                ->where('career_id', $this->career($row['career'])?->id)
                ->where('is_current', true)
                ->where('code', '!=', $row['code'])
                ->update(['is_current' => false]);
        }

        return parent::apply($row, $context);
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);

        if ($issues->hasErrors()) {
            return $issues;
        }

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

        if ($row['intake'] === true) {
            $issues = $this->checkIntake($row, $career, $issues);
        }

        return $issues;
    }

    /**
     * Un solo plan de nuevo ingreso por carrera en el archivo, y aviso si deja de ser el actual otro de la base.
     */
    private function checkIntake(array $row, Career $career, RowIssues $issues): RowIssues
    {
        if ($row['current'] === false || ! $career->is_active) {
            $issues = $issues->withWarning('intake', "El plan {$row['code']} queda inactivo y se marca como el actual de la carrera {$row['career']}.");
        }

        $first = $this->intakePlans[$career->id] ?? null;

        if ($first !== null && $first !== $row['code']) {
            return $issues->withError('intake', "El plan {$first} ya viene como nuevo ingreso de la carrera {$row['career']}: una carrera tiene un solo plan actual.");
        }

        $this->intakePlans[$career->id] = $row['code'];

        $previous = StudyPlan::query()->where('career_id', $career->id)->where('is_current', true)->where('code', '!=', $row['code'])->value('code');

        return $previous === null ? $issues : $issues->withWarning('intake', "El plan {$previous} deja de ser el actual de la carrera {$row['career']}.");
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [
            'career_id' => $this->career($row['career'])?->id,
            'name' => $row['name'],
            'version' => $row['version'],
            'total_credits' => is_numeric($row['credits']) ? (int) $row['credits'] : null,
            'is_active' => ($row['current'] ?? true) && (bool) $this->career($row['career'])?->is_active,
            ...($row['intake'] === true ? ['is_current' => true] : []),
            ...(is_bool($row['historical']) ? ['is_historical' => $row['historical']] : []),
        ];
    }

    /**
     * true o false si reconoce el valor de «vigente»; si no, el texto tal cual, y `rules()` lo reporta.
     */
    private function current(mixed $value): mixed
    {
        $value = is_bool($value) ? (string) (int) $value : self::asText($value);

        if (! is_string($value)) {
            return $value;
        }

        $slug = self::slug($value);

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
