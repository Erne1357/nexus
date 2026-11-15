<?php

namespace App\Modules\Core\Settings;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Lo que un módulo declara de uno de sus parámetros (CF-S7), en `settings()` de su provider: la llave de `settings`
 * (alcance global), el módulo que lo lee, su etiqueta y su ayuda en español, el tipo, el valor por omisión y las reglas
 * de validación de Laravel del valor. Con un tipo de tabla (`SettingType::*Table`), `fields` da cada campo y su etiqueta, y
 * `rules` vale para cada campo. Con `nullable`, vacío es «sin valor» y equivale al valor por omisión, que es null.
 *
 * El valor por omisión y las reglas salen de cómo lo lee la clase del módulo (`fromValue()`, sus constantes): esta
 * definición no cambia cómo se lee, solo dice qué se puede guardar. Inmutable: se comparte entre peticiones (Octane).
 */
final readonly class SettingDefinition
{
    /**
     * @param  string  $key  llave de `settings` (`staffing.weights`)
     * @param  string  $module  alias del módulo que lo declara (`staffing`)
     * @param  string  $moduleLabel  nombre del módulo en la pantalla («Asignación docente»)
     * @param  string  $label  nombre del parámetro, en español
     * @param  string  $help  qué cambia, en español y claro para el admin
     * @param  mixed  $default  el valor cuando no hay fila; un arreglo (llave => valor) en las tablas; null si es nullable
     * @param  list<string>  $rules  reglas de Laravel del valor (o de cada campo de una tabla), además de las del tipo
     * @param  array<string, string>  $fields  campo => etiqueta, solo en las tablas
     * @param  string|null  $unit  «horas», «minutos», «semestres»…
     */
    public function __construct(
        public string $key,
        public string $module,
        public string $moduleLabel,
        public string $label,
        public string $help,
        public SettingType $type,
        public mixed $default,
        public array $rules = [],
        public array $fields = [],
        public ?string $unit = null,
        public bool $nullable = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("La llave de parámetro «{$key}» no tiene la forma modulo.nombre.");
        }

        if ($type->isTable() === ($fields === [])) {
            throw new InvalidArgumentException("El parámetro «{$key}»: solo las tablas llevan campos, y todas los necesitan.");
        }

        if ($default === null ? ! $nullable : false) {
            throw new InvalidArgumentException("El parámetro «{$key}» no admite un valor por omisión nulo.");
        }
    }

    /**
     * El valor ya validado y con su tipo (enteros, decimales, booleano o la tabla con sus campos); null si es
     * nullable y vino vacío. Es el que se guarda.
     *
     * @throws ValidationException cada regla rota sobre `value` (o `value.<campo>` en una tabla)
     */
    public function validated(mixed $value): mixed
    {
        if ($this->nullable && ($value === null || $value === '')) {
            return null;
        }

        $messages = ['required' => 'Escribe un valor.', 'present' => 'Escribe un valor.'];
        $attributes = ['value' => mb_strtolower($this->label), ...array_combine(
            array_map(fn (string $field): string => "value.{$field}", array_keys($this->fields)),
            array_map(mb_strtolower(...), array_values($this->fields)),
        )];

        $value = Validator::make(['value' => $value], $this->validationRules(), $messages + $this->messages(), $attributes)->validate()['value'];

        return $this->cast($value);
    }

    /**
     * Si el valor guardado (el json de la fila) pasa la validación; el de omisión siempre debe pasar.
     */
    public function accepts(mixed $value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }

        try {
            $this->validated($value);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * Las reglas de Laravel de `value` y, en las tablas, de cada campo.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $type = match ($this->type) {
            SettingType::Integer, SettingType::IntegerTable => ['integer'],
            SettingType::Decimal, SettingType::DecimalTable => ['numeric'],
            SettingType::Boolean => ['boolean'],
        };

        if (! $this->type->isTable()) {
            return ['value' => ['required', ...$type, ...$this->rules]];
        }

        $rules = ['value' => ['required', 'array:'.implode(',', array_keys($this->fields))]];

        foreach (array_keys($this->fields) as $field) {
            $rules["value.{$field}"] = ['required', ...$type, ...$this->rules];
        }

        return $rules;
    }

    /**
     * El valor por omisión o el dado, como texto para la pantalla: «2», «Sí», «1 · 3 · 1 · 2», «Sin valor».
     */
    public function display(mixed $value): string
    {
        return match (true) {
            $value === null => 'Sin valor',
            $this->type === SettingType::Boolean => $value ? 'Sí' : 'No',
            $this->type->isTable() => collect($this->fields)->map(fn (string $label, string $field): string => $label.': '.($value[$field] ?? '—'))->implode(', '),
            default => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').($this->unit === null ? '' : " {$this->unit}"),
        };
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'integer' => 'Debe ser un número entero.',
            'numeric' => 'Debe ser un número.',
            'boolean' => 'Debe ser sí o no.',
            'min' => 'No puede ser menor que :min.',
            'max' => 'No puede ser mayor que :max.',
            'array' => 'El valor no tiene la forma esperada.',
        ];
    }

    private function cast(mixed $value): mixed
    {
        $one = fn (mixed $item): int|float|bool => match ($this->type) {
            SettingType::Integer, SettingType::IntegerTable => (int) $item,
            SettingType::Decimal, SettingType::DecimalTable => (float) $item,
            SettingType::Boolean => filter_var($item, FILTER_VALIDATE_BOOLEAN),
        };

        if (! $this->type->isTable()) {
            return $one($value);
        }

        $table = [];

        foreach (array_keys($this->fields) as $field) {
            $table[$field] = $one($value[$field]);
        }

        return $table;
    }
}
