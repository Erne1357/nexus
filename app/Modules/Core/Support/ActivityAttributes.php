<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\ModuleServiceProvider;
use BackedEnum;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Cómo se muestra, en el detalle de la bitácora (CF-S5), el nombre de una columna y su valor: «Correo» y no `email`,
 * `dd/mm/aaaa hh:mm` en la zona de Nexus y no el ISO 8601, «Sí» o «No» y no `true`, «—» si no hay nada y, si se sabe
 * de qué enum es, su `label()`. Cada módulo declara las suyas en `activityAttributes()` de su provider: un mapa
 * `modelo => [columna => etiqueta | [etiqueta, enum]]`, donde `'*'` vale para cualquier tipo y la llave de un modelo
 * (por su nombre corto, como ActivityRecordTypes) gana cuando el nombre cambia de sentido (`status`, `kind`, `note`).
 * Lo que nadie declara sale con la columna y los guiones bajos como espacios. Inmutable: se comparte entre peticiones.
 */
final readonly class ActivityAttributes
{
    private const string DATETIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * @param  array<string, array<string, string|array{0: string, 1: class-string<BackedEnum>}>>  $declared  por nombre corto del modelo, o `*`
     */
    public function __construct(private array $declared) {}

    /**
     * @param  iterable<ModuleServiceProvider>  $modules
     */
    public static function fromModules(iterable $modules): self
    {
        $declared = [];

        foreach ($modules as $module) {
            foreach ($module->activityAttributes() as $type => $attributes) {
                $key = $type === '*' ? '*' : class_basename($type);
                $declared[$key] = [...($declared[$key] ?? []), ...$attributes];
            }
        }

        return new self($declared);
    }

    /**
     * El nombre de la columna para quien lee la bitácora; sin mapa, la columna con espacios en lugar de guiones bajos.
     */
    public function label(?string $subjectType, string $field): string
    {
        $entry = $this->entry($subjectType, $field);

        if ($entry !== null) {
            return is_array($entry) ? $entry[0] : $entry;
        }

        return str_replace('_', ' ', $field);
    }

    /**
     * Un valor para mostrarlo: «—» si no hay o está vacío, «Sí» o «No», las fechas como `dd/mm/aaaa` y `dd/mm/aaaa hh:mm`
     * en `America/Ciudad_Juarez`, las horas como `hh:mm`, el `label()` del enum que declara la columna, y el JSON de
     * lo compuesto.
     */
    public function format(?string $subjectType, string $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_string($value) || is_int($value)) {
            $entry = $this->entry($subjectType, $field);

            if (is_array($entry) && is_subclass_of($entry[1], BackedEnum::class)) {
                $case = $entry[1]::tryFrom($value);

                return $case === null ? (string) $value : (method_exists($case, 'label') ? (string) $case->label() : (string) $case->value);
            }
        }

        if (is_string($value)) {
            return $this->date($value) ?? $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return string|array{0: string, 1: class-string<BackedEnum>}|null
     */
    private function entry(?string $subjectType, string $field): string|array|null
    {
        $short = $subjectType === null || $subjectType === '' ? null : class_basename($subjectType);

        return ($short === null ? null : ($this->declared[$short][$field] ?? null)) ?? $this->declared['*'][$field] ?? null;
    }

    private function date(string $value): ?string
    {
        $timezone = config('app.timezone', 'America/Ciudad_Juarez');

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return CarbonImmutable::parse($value)->format('d/m/Y');
            }

            if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) === 1) {
                return substr($value, 0, 5);
            }

            if (preg_match(self::DATETIME, $value) === 1) {
                $moment = CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);

                return $moment->format($moment->format('H:i') === '00:00' ? 'd/m/Y' : 'd/m/Y H:i');
            }
        } catch (Throwable) {
        }

        return null;
    }
}
