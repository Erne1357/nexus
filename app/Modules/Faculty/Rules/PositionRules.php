<?php

namespace App\Modules\Faculty\Rules;

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\TeacherPosition;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de una plaza que comparten AddPosition y UpdatePosition (docs/dominio/modelo-de-datos.md,
 * `teacher_positions`). Cada regla rota es una ValidationException sobre su campo.
 *
 * - El tipo es un valor de PositionKind.
 * - Las horas base son las que admite el tipo (S3, supuesto por confirmar): tiempo completo 40, tres cuartos 30,
 *   medio tiempo 20, honorarios 0 y asignatura de 1 a 40. Si el tipo las fija y no llegan, las toma de sí mismo.
 * - La vigencia: fechas `Y-m-d` o vacías (sin límite); el último día cuenta, así que puede terminar el mismo día
 *   que empieza, pero no antes.
 */
final class PositionRules
{
    /** Lo que se edita y se registra en la bitácora. */
    public const array FIELDS = ['kind', 'base_hours', 'valid_from', 'valid_to'];

    /**
     * Los datos de una plaza ya normalizados y revisados.
     *
     * @param  array{kind?: PositionKind|string|null, base_hours?: int|string|null, valid_from?: ?string, valid_to?: ?string}  $data
     * @return array{kind: PositionKind, base_hours: int, valid_from: ?string, valid_to: ?string}
     *
     * @throws ValidationException
     */
    public function normalize(array $data): array
    {
        $errors = [];
        $values = [];

        foreach ([
            'kind' => fn (): PositionKind => $this->kind($data['kind'] ?? null),
            'valid_from' => fn (): ?string => $this->date('valid_from', $data['valid_from'] ?? null),
            'valid_to' => fn (): ?string => $this->date('valid_to', $data['valid_to'] ?? null),
        ] as $field => $read) {
            try {
                $values[$field] = $read();
            } catch (ValidationException $exception) {
                $errors += $exception->errors();
            }
        }

        if (isset($values['valid_from'], $values['valid_to']) && $values['valid_to'] < $values['valid_from']) {
            $errors['valid_to'] = ['La vigencia no puede terminar antes de empezar.'];
        }

        if (isset($values['kind'])) {
            try {
                $values['base_hours'] = $this->hours($values['kind'], $data['base_hours'] ?? null);
            } catch (ValidationException $exception) {
                $errors += $exception->errors();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['kind' => $values['kind'], 'base_hours' => $values['base_hours'], 'valid_from' => $values['valid_from'], 'valid_to' => $values['valid_to']];
    }

    /**
     * Los valores de `$fields` como van a la bitácora: el tipo por su valor y las fechas como `Y-m-d`.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function logged(TeacherPosition $position, array $fields = self::FIELDS): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = match ($field) {
                'kind' => $position->kind->value,
                'valid_from', 'valid_to' => $position->{$field}?->toDateString(),
                default => $position->getAttribute($field),
            };
        }

        return $values;
    }

    /**
     * @throws ValidationException
     */
    private function kind(PositionKind|string|null $kind): PositionKind
    {
        return $kind instanceof PositionKind ? $kind : (PositionKind::tryFrom((string) $kind)
            ?? throw ValidationException::withMessages(['kind' => 'El tipo de plaza no es válido.']));
    }

    /**
     * @throws ValidationException
     */
    private function hours(PositionKind $kind, int|string|null $hours): int
    {
        $fixed = $kind->fixedHours();

        if ($hours === null || $hours === '') {
            return $fixed ?? throw ValidationException::withMessages(['base_hours' => 'Indica las horas base.']);
        }

        if (! is_int($hours) && ! preg_match('/^\d{1,4}$/', trim($hours))) {
            throw ValidationException::withMessages(['base_hours' => 'Las horas base deben ser un número entero.']);
        }

        $hours = (int) $hours;

        if (! $kind->allows($hours)) {
            throw ValidationException::withMessages(['base_hours' => $fixed === null
                ? 'Las horas base de una asignatura van de 1 a '.PositionKind::MAX_HOURS.'.'
                : 'Las horas base de '.mb_strtolower($kind->label())." son {$fixed}."]);
        }

        return $hours;
    }

    /**
     * @throws ValidationException
     */
    private function date(string $field, ?string $date): ?string
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->toDateString() !== $date) {
            throw ValidationException::withMessages([$field => 'La fecha no es válida.']);
        }

        return $date;
    }
}
