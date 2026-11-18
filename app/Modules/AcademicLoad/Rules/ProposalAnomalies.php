<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandSettings;

/**
 * Lo raro de una fila de la propuesta, para marcarlo sin bloquear el envío (confirmado el 2 de noviembre de 2026;
 * reglas-de-negocio.md, «Propuesta de carrera»): más alumnos esperados que los que se explican y grupos con menos alumnos esperados por grupo que el mínimo («1 grupo para 2 alumnos»). El mínimo
 * sale del parámetro global `demand.min_group_size` (DemandSettings, que lo declara), 10 si no existe o no es un entero mayor que cero (supuesto
 * por confirmar). Lo marcan la propuesta del coordinador y la bandeja del jefe. Puro: solo fromSettings() lee la
 * base.
 *
 * Los esperados se comparan con lo que explica la proyección vigente de la fila (explained(): sus posibles, R1, más los que
 * dependen de aprobar lo que cursan hoy y el nuevo ingreso; confirmado el 17 de noviembre de 2026): con la fórmula v2 los
 * esperados de primer semestre incluyen el nuevo ingreso y los de otras materias a quienes cursan su seriación, así que
 * compararlos con los posibles marcaba casi todas las filas. Sin proyección, con los posibles, como antes («105 esperados
 * con 98 elegibles»).
 */
final readonly class ProposalAnomalies
{
    public const string SETTING = 'demand.min_group_size';

    public const int DEFAULT_MINIMUM = DemandSettings::DEFAULT_MIN_GROUP_SIZE;

    public function __construct(public int $minimum = self::DEFAULT_MINIMUM) {}

    public static function fromSettings(): self
    {
        return self::fromValue(Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->where('key', self::SETTING)
            ->value('value'));
    }

    public static function fromValue(mixed $value): self
    {
        return new self(is_int($value) && $value > 0 ? $value : self::DEFAULT_MINIMUM);
    }

    /**
     * Lo que explica la proyección vigente para una fila de la propuesta: sus posibles (R1) más los que dependen de aprobar
     * lo que cursan hoy (`pending_prerequisite_students`) y, solo en primer semestre (`first_semester`), el nuevo ingreso
     * (`new_students`) de su fila de la proyección; null sin proyección (confirmado el 17 de noviembre de 2026). En primer semestre (`first_semester`) de una columna con el
     * nuevo ingreso corregido (`$correctedIntake`), cuenta el corregido, porque la corrección mueve los esperados de esas filas
     * (supuesto por confirmar: sin esto, la carrera ofrecida por primera vez marcaba todas sus filas de 1.º).
     */
    public static function explained(int $eligible, ?DemandForecast $forecast, ?int $correctedIntake = null): ?int
    {
        if ($forecast === null) {
            return null;
        }

        // El nuevo ingreso solo explica esperados de primer semestre (`first_semester`); en otra fila, aunque venga, no suma.
        $newStudents = $forecast->method !== ForecastMethod::FirstSemester ? 0 : ($correctedIntake ?? (int) ($forecast->new_students ?? 0));

        return $eligible + (int) $forecast->pending_prerequisite_students + $newStudents;
    }

    /**
     * @param  int|null  $explained  lo que explica la proyección vigente (explained()); null sin proyección
     * @return list<string>
     */
    public function for(int $eligible, int $expected, int $groups, ?int $explained = null): array
    {
        $anomalies = [];

        if ($explained === null && $expected > $eligible) {
            $anomalies[] = "{$expected} esperados con {$eligible} elegibles";
        }

        if ($explained !== null && $expected > $explained) {
            $anomalies[] = "{$expected} esperados con {$explained} que explica la proyección";
        }

        if ($groups > 0 && $expected < $groups * $this->minimum) {
            $anomalies[] = ($groups === 1 ? '1 grupo' : "{$groups} grupos").' para '.($expected === 1 ? '1 alumno' : "{$expected} alumnos");
        }

        return $anomalies;
    }
}
