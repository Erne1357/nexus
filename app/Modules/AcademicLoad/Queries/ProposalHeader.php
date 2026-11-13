<?php

namespace App\Modules\AcademicLoad\Queries;

use Carbon\CarbonImmutable;

/**
 * Lo que dice el encabezado de la propuesta por modalidad y lo que avisa su envío (maqueta U1, opción A; armado por
 * ProposalOverview::header()): dónde se ofrece la carrera (MS5), quiénes la coordinan y qué columnas captura cada uno
 * (M5, M7), quién captura una columna, el aviso de alcance a quien corrige unas columnas y no otras (M7), quién la
 * envió y cuándo, y a quién avisa el envío (M6, MS18). Puro: recibe los datos ya leídos y no lee la base.
 */
final readonly class ProposalHeader
{
    /**
     * @param  list<array{key: string, code: ?string, name: ?string}>  $columns  cada columna por su llave (ProposalOverview::columnKey), con la clave y el nombre de su campus (nulos sin campus)
     * @param  list<array{id: string, name: string, keys: list<string>}>  $coordinators  por nombre, con las llaves de las columnas que cubre cada uno
     * @param  array{id: ?string, name: ?string, at: CarbonImmutable}|null  $sent  quién la envió la última vez y cuándo
     * @param  array{department: string, at: CarbonImmutable}|null  $returned  la última parte regresada: la clave de su departamento y cuándo
     * @param  list<string>  $pendingDepartments  las claves de los departamentos con grupos pedidos cuya parte no está aceptada
     */
    public function __construct(
        public string $careerCode,
        public array $columns,
        public array $coordinators,
        public ?array $sent,
        public ?array $returned,
        public array $pendingDepartments,
        public string $timezone,
    ) {}

    /**
     * «Se ofrece en C1 y C2» o «Se ofrece solo en C1» (MS5); null en la columna sin campus de una modalidad sin salones.
     */
    public function offer(): ?string
    {
        $codes = $this->codes(array_column($this->columns, 'key'));

        return match (count($codes)) {
            0 => null,
            1 => "Se ofrece solo en {$codes[0]}",
            default => 'Se ofrece en '.$this->join($codes),
        };
    }

    /**
     * Quiénes coordinan, para el encabezado: cada uno con la llave de su columna si cubre solo una de varias, o con los
     * campus que cubre («C1 y C2», «C1») si las cubre todas (M5); `me` es quien la ve.
     *
     * @return list<array{name: string, campusKey: ?string, covers: ?string, me: bool}>
     */
    public function coordinatorLine(?string $viewerId): array
    {
        return array_map(function (array $row) use ($viewerId): array {
            $whole = $this->coversAll($row);
            $codes = $this->codes($row['keys']);

            return [
                'name' => $row['name'],
                'campusKey' => ! $whole && count($row['keys']) === 1 ? $row['keys'][0] : null,
                'covers' => $whole && $codes !== [] ? $this->join($codes) : null,
                'me' => $viewerId !== null && $row['id'] === $viewerId,
            ];
        }, $this->coordinators);
    }

    /**
     * Quién captura la columna (M7): «C1 lo captura <nombres>.», o que no tiene coordinador.
     */
    public function capturedBy(string $key): string
    {
        $names = array_column(array_filter($this->coordinators, fn (array $row): bool => in_array($key, $row['keys'], true)), 'name');
        $where = $this->column($key)['code'] ?? 'Esta columna';

        return $names === [] ? "{$where} no tiene coordinador que la capture." : "{$where} lo captura {$this->join($names)}.";
    }

    /**
     * El aviso de alcance (M7) a quien corrige las columnas de `$editableKeys` y no las demás; null si corrige todas o
     * ninguna.
     *
     * @param  list<string>  $editableKeys
     * @return array{career: string, campuses: string, codes: string, others: string}|null
     */
    public function scopeNote(array $editableKeys): ?array
    {
        $editable = array_values(array_filter($this->columns, fn (array $column): bool => in_array($column['key'], $editableKeys, true)));
        $readonly = array_values(array_filter($this->columns, fn (array $column): bool => ! in_array($column['key'], $editableKeys, true)));

        if ($editable === [] || $readonly === []) {
            return null;
        }

        return [
            'career' => $this->careerCode,
            'campuses' => $this->join(array_values(array_filter(array_column($editable, 'name')))),
            'codes' => $this->join(array_values(array_filter(array_column($editable, 'code')))),
            'others' => implode(' ', array_map(fn (array $column): string => $this->capturedBy($column['key']), $readonly)),
        ];
    }

    /**
     * Quién la envió la última vez y cuándo, con su campus si coordina solo una de varias columnas, y la última parte
     * regresada con su fecha; null si nunca se ha enviado.
     *
     * @return array{name: string, campus: ?string, at: string, returned: ?string}|null
     */
    public function sentLine(): ?array
    {
        if ($this->sent === null) {
            return null;
        }

        $at = $this->sent['at']->setTimezone($this->timezone);

        return [
            'name' => $this->sent['name'] ?? 'alguien que ya no está',
            'campus' => $this->sent['id'] === null ? null : $this->campusOf($this->sent['id']),
            'at' => $at->format('d/m/Y').' a las '.$at->format('G:i'),
            'returned' => $this->returned === null ? null : "Regresada por {$this->returned['department']} el ".$this->returned['at']->setTimezone($this->timezone)->format('d/m/Y'),
        ];
    }

    /**
     * A quién avisa el envío, para la confirmación (MS18): a los demás coordinadores, cada uno con los campus que cubre,
     * y a los jefes de los departamentos con su parte por decidir. Vacío si no avisa a nadie.
     */
    public function submitNotice(?string $viewerId): string
    {
        $others = array_values(array_filter($this->coordinators, fn (array $row): bool => $row['id'] !== $viewerId));
        $names = array_map(function (array $row): string {
            $codes = $this->codes($row['keys']);

            return $codes === [] ? $row['name'] : "{$row['name']} ({$this->join($codes)})";
        }, $others);

        $to = array_values(array_filter([
            $names === [] ? null : $this->join($names).(count($names) === 1 ? ', que coordina contigo' : ', que coordinan contigo'),
            $this->pendingDepartments === [] ? null : 'los jefes de '.$this->join($this->pendingDepartments),
        ]));

        return $to === [] ? '' : 'Se avisa a '.implode(', y a ', $to).'.';
    }

    /**
     * La clave del campus de un coordinador que cubre solo una de varias columnas; null si cubre todas o es de una
     * propuesta de una sola columna.
     */
    private function campusOf(string $userId): ?string
    {
        foreach ($this->coordinators as $row) {
            if ($row['id'] === $userId) {
                return count($this->columns) > 1 && count($row['keys']) === 1 ? ($this->column($row['keys'][0])['code'] ?? null) : null;
            }
        }

        return null;
    }

    /**
     * @param  array{id: string, name: string, keys: list<string>}  $row
     */
    private function coversAll(array $row): bool
    {
        return array_diff(array_column($this->columns, 'key'), $row['keys']) === [];
    }

    /**
     * @return array{key: string, code: ?string, name: ?string}|null
     */
    private function column(string $key): ?array
    {
        foreach ($this->columns as $column) {
            if ($column['key'] === $key) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Las claves de los campus de esas columnas, por clave; sin la columna sin campus.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function codes(array $keys): array
    {
        $codes = array_values(array_filter(array_map(fn (string $key): ?string => $this->column($key)['code'] ?? null, $keys)));
        sort($codes);

        return $codes;
    }

    /**
     * «A», «A y B», «A, B y C».
     *
     * @param  list<string>  $items
     */
    private function join(array $items): string
    {
        return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)).' y '.end($items);
    }
}
