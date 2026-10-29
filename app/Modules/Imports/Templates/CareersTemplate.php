<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Carreras del SII (tarea 1.9): clave, nombre, departamento que la coordina y campus donde se ofrece. Una carrera
 * que se ofrece en dos campus viene en un renglón por campus (confirmado el 29 de octubre de 2026): la clave del
 * archivo es la carrera con su campus, para que esos renglones no cuenten como repetidos, y el registro se busca
 * por la clave de la carrera. Cada renglón da de alta la carrera si falta, actualiza su nombre y departamento, y
 * da de alta su oferta en ese campus (`career_campus`).
 *
 * Como todo lo importado, no reactiva ni restaura: una oferta inactiva se queda inactiva, con advertencia, y una
 * borrada es error. No ofrece "desactivar faltantes": las carreras son globales y la exportación puede ser de un
 * solo campus. Busca cada departamento y campus una vez por corrida.
 */
class CareersTemplate extends ImportTemplate
{
    /** @var array<string, string|null> id de departamento por clave, en esta corrida */
    private array $departments = [];

    /** @var array<string, string|null> id de campus por clave, en esta corrida */
    private array $campuses = [];

    public function key(): string
    {
        return 'careers';
    }

    public function label(): string
    {
        return 'Carreras';
    }

    public function fields(): array
    {
        return [
            'code' => 'clave',
            'name' => 'nombre',
            'department' => 'departamento',
            'campus' => 'campus',
        ];
    }

    public function naturalKey(): array
    {
        return ['code', 'campus'];
    }

    public function model(): string
    {
        return Career::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string'],
            'campus' => ['required', 'string'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['code', 'department', 'campus'] as $field) {
            $row[$field] = self::asText($row[$field]);
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        return $row;
    }

    /**
     * Como la base, y además la oferta de la carrera en el campus del renglón, si todavía no la tiene.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $career = parent::apply($row, $context);

        CareerCampus::withTrashed()->firstOrCreate(
            ['career_id' => $career->getKey(), 'campus_id' => $this->campus($row['campus'])],
            ['is_active' => true],
        );

        return $career;
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);

        if ($this->department($row['department']) === null) {
            $issues = $issues->withError('department', "No hay un departamento con la clave {$row['department']}.");
        }

        $campus = $this->campus($row['campus']);

        if ($campus === null) {
            return $issues->withError('campus', "No hay un campus con la clave {$row['campus']}.");
        }

        $offering = CareerCampus::withTrashed()
            ->where('campus_id', $campus)
            ->whereIn('career_id', Career::withTrashed()->where('code', $row['code'])->select('id'))
            ->first();

        return match (true) {
            $offering === null => $issues,
            $offering->trashed() => $issues->withError('campus', "La oferta de la carrera {$row['code']} en {$row['campus']} está borrada; una importación no la restaura."),
            ! $offering->is_active => $issues->withWarning('campus', "La carrera {$row['code']} ya no se ofrece en {$row['campus']}: se actualizan sus datos, pero su oferta sigue inactiva."),
            default => $issues,
        };
    }

    protected function recordKey(array $row, ImportContext $context): ?array
    {
        return ['code' => $row['code']];
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [
            'name' => $row['name'],
            'department_id' => $this->department($row['department']),
        ];
    }

    private function department(string $code): ?string
    {
        return array_key_exists($code, $this->departments) ? $this->departments[$code] : $this->departments[$code] = Department::query()->where('code', $code)->value('id');
    }

    private function campus(string $code): ?string
    {
        return array_key_exists($code, $this->campuses) ? $this->campuses[$code] : $this->campuses[$code] = Campus::query()->where('code', $code)->value('id');
    }
}
