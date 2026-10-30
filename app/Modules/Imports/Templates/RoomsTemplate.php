<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Department;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Rules\BuildingRules;
use App\Modules\Facilities\Rules\RoomRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Salones del SII (tarea 1.15) de un campus, el del lote: edificio, clave, nombre, tipo, capacidad y departamento
 * dueño. La clave natural es la clave del salón dentro del campus (`requiresCampus()`), así que «desactivar
 * faltantes» solo mira los salones activos de ese campus.
 *
 * El edificio que el campus no tiene se crea con su clave como nombre y avisa, como las especialidades de
 * SubjectsTemplate (S11, supuesto por confirmar); uno inactivo avisa y uno borrado es error. El tipo se reconoce por
 * nombre, sin acentos ni mayúsculas (`AULA`, `SALA DE COMPUTO`, `CENTRO DE COMPUTO`…); otro es error. La capacidad
 * es un entero mayor que cero y acepta separadores de miles. Las claves del salón y del edificio se normalizan con
 * RoomRules y BuildingRules, igual que las capturadas a mano.
 *
 * Como todo lo importado, no reactiva ni restaura: un salón inactivo se actualiza y sigue inactivo, con
 * advertencia, y uno borrado es error. Un cambio no pisa con vacío el nombre ni el dueño. No toca las
 * características. Busca cada edificio y departamento una vez por corrida.
 */
class RoomsTemplate extends ImportTemplate
{
    /**
     * Cómo llama el SII a cada tipo, ya como slug (ImportTemplate::slug()).
     */
    private const array TYPES = [
        'aula' => RoomType::Aula,
        'laboratorio' => RoomType::Laboratorio,
        'taller' => RoomType::Taller,
        'sala_de_computo' => RoomType::SalaComputo,
        'sala_computo' => RoomType::SalaComputo,
        'centro_de_computo' => RoomType::SalaComputo,
        'auditorio' => RoomType::Auditorio,
    ];

    /** @var array<string, Building|null> edificio por campus y clave, en esta corrida */
    private array $buildings = [];

    /** @var array<string, Department|null> departamento por clave, en esta corrida */
    private array $departments = [];

    public function key(): string
    {
        return 'rooms';
    }

    public function label(): string
    {
        return 'Salones';
    }

    public function fields(): array
    {
        return [
            'building' => 'edificio',
            'code' => 'clave',
            'name' => 'nombre',
            'type' => 'tipo',
            'capacity' => 'capacidad',
            'department' => 'departamento',
        ];
    }

    public function naturalKey(): array
    {
        return ['code'];
    }

    public function model(): string
    {
        return Room::class;
    }

    public function requiresCampus(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'building' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(array_column(RoomType::cases(), 'value'))],
            'capacity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'department' => ['nullable', 'string'],
        ];
    }

    protected function messages(): array
    {
        return [
            'type.in' => 'El tipo :input no es válido: usa aula, laboratorio, taller, sala de cómputo o auditorio.',
            'capacity.min' => 'La capacidad debe ser mayor que cero.',
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        $row['code'] = $this->roomCode(self::asText($row['code']));
        $row['building'] = $this->buildingCode(self::asText($row['building']));
        $row['department'] = self::asText($row['department']);
        $row['department'] = is_string($row['department']) ? mb_strtoupper($row['department']) : $row['department'];

        if (is_string($row['type'])) {
            $row['type'] = (self::TYPES[self::slug($row['type'])] ?? null)?->value ?? $row['type'];
        }

        $capacity = self::asText($row['capacity']);

        if (is_string($capacity)) {
            $clean = preg_replace('/[\s,]+/u', '', $capacity);
            $clean = preg_replace('/\.0+$/', '', (string) $clean);
            $row['capacity'] = ctype_digit((string) $clean) && $clean !== '' ? (int) $clean : $capacity;
        } else {
            $row['capacity'] = $capacity;
        }

        return $row;
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);

        $building = $this->building($row['building'], $context);

        if ($building === null) {
            $issues = $issues->withWarning('building', "El edificio {$row['building']} no existe en este campus: se crea con su clave como nombre.");
        } elseif ($building->trashed()) {
            $issues = $issues->withError('building', "Hay un edificio borrado con la clave {$row['building']}; una importación no lo restaura.");
        } elseif (! $building->is_active) {
            $issues = $issues->withWarning('building', "El edificio {$row['building']} está inactivo.");
        }

        if ($row['department'] !== null) {
            $department = $this->department($row['department']);

            if ($department === null) {
                $issues = $issues->withError('department', "No hay un departamento con la clave {$row['department']}.");
            } elseif (! $department->is_active) {
                $issues = $issues->withWarning('department', "El departamento {$row['department']} está inactivo.");
            }
        }

        return $issues;
    }

    /**
     * El salón nuevo nace activo. Las características no vienen en el archivo y no se tocan.
     */
    protected function defaults(ImportContext $context): array
    {
        return ['is_active' => true];
    }

    /**
     * Crea el edificio si el campus no lo tiene (S11). Corre dentro de la transacción del lote, así que si la fila
     * falla el edificio no se queda.
     */
    protected function attributes(array $row, ImportContext $context): array
    {
        $building = $this->building($row['building'], $context)
            ?? Building::query()->create(['campus_id' => $context->campus->getKey(), 'code' => $row['building'], 'name' => $row['building']]);
        $this->buildings[$this->buildingCacheKey($row['building'], $context)] = $building;

        return [
            'building_id' => $building->getKey(),
            'name' => $row['name'],
            'type' => $row['type'],
            'capacity' => $row['capacity'],
            'department_id' => $row['department'] === null ? null : $this->department($row['department'])?->getKey(),
        ];
    }

    private function roomCode(mixed $code): mixed
    {
        if (! is_string($code)) {
            return $code;
        }

        try {
            return app(RoomRules::class)->normalizeCode($code);
        } catch (ValidationException) {
            return $code;
        }
    }

    private function buildingCode(mixed $code): mixed
    {
        if (! is_string($code)) {
            return $code;
        }

        try {
            return app(BuildingRules::class)->normalizeCode($code);
        } catch (ValidationException) {
            return $code;
        }
    }

    private function building(string $code, ImportContext $context): ?Building
    {
        $key = $this->buildingCacheKey($code, $context);

        return array_key_exists($key, $this->buildings) ? $this->buildings[$key] : $this->buildings[$key] = Building::withTrashed()
            ->where(['campus_id' => $context->campus->getKey(), 'code' => $code])
            ->first(['id', 'campus_id', 'code', 'is_active', 'deleted_at']);
    }

    private function buildingCacheKey(string $code, ImportContext $context): string
    {
        return $context->campus->getKey().'|'.$code;
    }

    private function department(string $code): ?Department
    {
        return array_key_exists($code, $this->departments) ? $this->departments[$code] : $this->departments[$code] = Department::query()->where('code', $code)->first(['id', 'is_active']);
    }
}
