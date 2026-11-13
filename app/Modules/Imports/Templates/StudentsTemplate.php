<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Alumnos del SII (tarea 1.4): número de control, nombre, carrera, plan, campus, semestre y estatus. El número
 * de control es la clave natural y es único en todo el Tec, así que el campus viene en el archivo y no de la
 * barra superior. La carrera, el plan (de esa carrera) y el campus se buscan por su clave.
 *
 * Todavía no hay una exportación real: el estatus se reconoce sin importar mayúsculas, acentos ni espacios, y
 * uno que Nexus no conoce no detiene la importación. El alumno queda sin estatus, también si ya tenía uno,
 * para que no siga contando como activo, con advertencia; lo que vino queda en `import_rows` para agregar al
 * enum los que se repitan (confirmado el 29 de octubre de 2026).
 *
 * No ofrece "desactivar faltantes": una exportación suele ser de un campus y el número de control es de todo el
 * Tec, así que desactivaría a los alumnos de los demás campus; las bajas llegan en el estatus. Busca cada
 * carrera, plan y campus una vez por corrida. Al terminar el lote despacha el recálculo del progreso.
 *
 * `modality` (presencial, a distancia, «EAD» o mixta) es del alumno y se guarda como la modalidad del catálogo: presencial
 * es `PRES`; a distancia, «EAD» y mixta, `EAD`; sin ella, `PRES` (MS7, supuesto por confirmar). Es del alumno y `status_reason` es el motivo del
 * SII (BD1, BT1, TIT…), que se guarda junto al estatus de Nexus y se escribe en mayúsculas (D12 y D15, confirmados
 * el 5 de noviembre de 2026; lo de «EAD» y las mayúsculas, supuesto por confirmar). Como los demás campos, un
 * cambio no los pisa con vacío. `active` (el SII lo da por estatus: inscritos y bajas temporales cuentan como activos)
 * hace nacer inactivo al alumno que el archivo marca así; un cambio nunca activa ni desactiva, solo avisa (D11, confirmado
 * el 5 de noviembre de 2026; el campo, supuesto por confirmar).
 */
class StudentsTemplate extends ImportTemplate
{
    use ParsesActiveFlag;

    /** @var array<string, Career|null> carrera por clave, en esta corrida */
    private array $careers = [];

    /** @var array<string, StudyPlan|null> plan por clave, en esta corrida */
    private array $plans = [];

    /** @var array<string, bool|null> si el alumno está activo (null si no existe), por número de control, en esta corrida */
    private array $students = [];

    /** @var array<string, string|null> id de campus por clave, en esta corrida */
    private array $campuses = [];

    /** @var array<string, string|null> id de modalidad por clave, en esta corrida */
    private array $modalities = [];

    /** Texto de la modalidad (sin acentos, en minúsculas y con guion bajo) → clave del catálogo (MS7, supuesto por confirmar). */
    private const array MODALITY_CODES = [
        'presencial' => 'PRES',
        'distancia' => 'EAD',
        'a_distancia' => 'EAD',
        'ead' => 'EAD',
        'mixta' => 'EAD',
    ];

    /** La modalidad de un alumno nuevo sin modalidad en el archivo (MS7). */
    private const string DEFAULT_MODALITY = 'PRES';

    /**
     * Clave del catálogo → el texto del SII con que se exporta (la vuelta de MODALITY_CODES; MS7). En línea y TecNM
     * Virtual no tienen valor en el SII.
     *
     * @var array<string, string>
     */
    public const array SII_MODALITIES = [
        'PRES' => 'presencial',
        'EAD' => 'distancia',
    ];

    public function key(): string
    {
        return 'students';
    }

    public function label(): string
    {
        return 'Alumnos';
    }

    public function fields(): array
    {
        return [
            'control_number' => 'número de control',
            'name' => 'nombre',
            'career' => 'carrera',
            'study_plan' => 'plan',
            'campus' => 'campus',
            'semester' => 'semestre',
            'status' => 'estatus',
            'modality' => 'modalidad',
            'status_reason' => 'motivo del SII',
            'active' => 'activo',
        ];
    }

    public function naturalKey(): array
    {
        return ['control_number'];
    }

    public function model(): string
    {
        return Student::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'control_number' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'career' => ['required', 'string'],
            'study_plan' => ['required', 'string'],
            'campus' => ['required', 'string'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:20'],
            'status' => ['nullable', 'string'],
            'modality' => ['nullable', 'string'],
            'status_reason' => ['nullable', 'string', 'max:10'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['control_number', 'career', 'study_plan', 'campus'] as $field) {
            $row[$field] = self::asText($row[$field]);
        }

        foreach (['career', 'study_plan', 'campus'] as $field) {
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        $row['semester'] = is_string($row['semester']) && ctype_digit($row['semester']) ? (int) $row['semester'] : $row['semester'];
        $row['status'] = is_string($row['status']) ? $this->status($row['status']) : $row['status'];

        $modality = self::asText($row['modality']);
        $row['modality'] = is_string($modality) ? $this->modality($modality) : $modality;
        $reason = self::asText($row['status_reason']);
        $row['status_reason'] = is_string($reason) ? Str::upper($reason) : $reason;
        $row['active'] = self::activeFlag($row['active']);

        return $row;
    }

    /**
     * Como la base, pero un estatus que Nexus no conoce deja al alumno sin estatus también en un cambio: el
     * cambio no pisa con null lo que el archivo trae vacío, y aquí el archivo sí trajo un estatus.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $student = parent::apply($row, $context);

        if ($row['status'] !== null && StudentStatus::tryFrom($row['status']) === null && $student->getAttribute('status') !== null) {
            $student->forceFill(['status' => null])->save();
        }

        return $student;
    }

    public function afterApply(ImportContext $context): void
    {
        RecomputeStudentProgress::dispatch();
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);
        $career = $this->career($row['career']);

        if ($career === null) {
            $issues = $issues->withError('career', "No hay una carrera con la clave {$row['career']}.");
        }

        $plan = $this->plan($row['study_plan']);

        if ($plan === null) {
            $issues = $issues->withError('study_plan', "No hay un plan con la clave {$row['study_plan']}.");
        } elseif ($career !== null && $plan->career_id !== $career->id) {
            $issues = $issues->withError('study_plan', "El plan {$row['study_plan']} no es de la carrera {$row['career']}.");
        }

        if ($this->campus($row['campus']) === null) {
            $issues = $issues->withError('campus', "No hay un campus con la clave {$row['campus']}.");
        }

        if ($row['active'] === false) {
            if ($this->studentIsActive($row['control_number'])) {
                $issues = $issues->withWarning('active', 'El archivo lo marca inactivo, pero el alumno sigue activo: una importación no lo da de baja.');
            }
        }

        if ($row['status'] !== null && StudentStatus::tryFrom($row['status']) === null) {
            $issues = $issues->withWarning('status', "El estatus «{$row['status']}» no está en Nexus: el alumno queda sin estatus.");
        }

        return $this->checkModality($row, $issues);
    }

    /**
     * La modalidad del archivo debe ser una que Nexus reconoce (MS7) y existir en el catálogo: un texto desconocido o una
     * modalidad borrada es error de la fila, también la Presencial que toma un alta sin modalidad (sin ella el alumno no
     * se podría guardar). Una inactiva vale: sus alumnos se siguen viendo (M10).
     *
     * @param  array<string, mixed>  $row
     */
    private function checkModality(array $row, RowIssues $issues): RowIssues
    {
        if ($row['modality'] !== null && ! array_key_exists($row['modality'], self::MODALITY_CODES)) {
            return $issues->withError('modality', "La modalidad «{$row['modality']}» no está en Nexus: usa presencial, a distancia, EAD o mixta.");
        }

        $code = $row['modality'] !== null ? self::MODALITY_CODES[$row['modality']] : ($this->studentIsActive($row['control_number']) === null ? self::DEFAULT_MODALITY : null);

        return $code !== null && $this->modalityId($code) === null ? $issues->withError('modality', "La modalidad {$code} está borrada en Nexus.") : $issues;
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        // Sin modalidad en el archivo no se manda `modality_id`: un alta toma Presencial de `defaults()` y un cambio no la pisa.
        $modality = $row['modality'] === null ? [] : ['modality_id' => $this->modalityId(self::MODALITY_CODES[$row['modality']])];

        return [
            ...$modality,
            'name' => $row['name'],
            'career_id' => $this->career($row['career'])?->id,
            'study_plan_id' => $this->plan($row['study_plan'])?->id,
            'campus_id' => $this->campus($row['campus']),
            'current_semester' => $row['semester'],
            'status' => StudentStatus::tryFrom((string) $row['status']),
            'status_reason' => $row['status_reason'],
            'is_active' => $row['active'] ?? true,
        ];
    }

    /**
     * El valor de StudentStatus que corresponde al texto del SII, o el texto tal cual si no lo conoce.
     */
    private function status(string $value): string
    {
        return StudentStatus::tryFrom(self::slug($value))?->value ?? $value;
    }

    /**
     * La modalidad en minúsculas y con guion bajo si el texto es una que Nexus reconoce, o el texto tal cual si no.
     */
    private function modality(string $value): string
    {
        $slug = self::slug($value);

        return array_key_exists($slug, self::MODALITY_CODES) ? $slug : $value;
    }

    /**
     * Un alumno nuevo sin modalidad en el archivo queda en Presencial (MS7); un cambio sin ella no la pisa.
     *
     * @return array<string, mixed>
     */
    protected function defaults(ImportContext $context): array
    {
        return ['modality_id' => $this->modalityId(self::DEFAULT_MODALITY)];
    }

    private function modalityId(string $code): ?string
    {
        return array_key_exists($code, $this->modalities) ? $this->modalities[$code] : $this->modalities[$code] = Modality::query()->where('code', $code)->value('id');
    }

    private function career(string $code): ?Career
    {
        return array_key_exists($code, $this->careers) ? $this->careers[$code] : $this->careers[$code] = Career::query()->where('code', $code)->first(['id']);
    }

    private function plan(string $code): ?StudyPlan
    {
        return array_key_exists($code, $this->plans) ? $this->plans[$code] : $this->plans[$code] = StudyPlan::query()->where('code', $code)->first(['id', 'career_id']);
    }

    private function campus(string $code): ?string
    {
        return array_key_exists($code, $this->campuses) ? $this->campuses[$code] : $this->campuses[$code] = Campus::query()->where('code', $code)->value('id');
    }

    /**
     * Si el alumno con ese número está activo (null si no existe). Solo se guarda el booleano: con decenas de miles
     * de alumnos, conservar los modelos agotaría la memoria en una segunda corrida.
     */
    private function studentIsActive(string $number): ?bool
    {
        if (! array_key_exists($number, $this->students)) {
            $active = Student::query()->where('control_number', $number)->toBase()->value('is_active');
            $this->students[$number] = $active === null ? null : (bool) $active;
        }

        return $this->students[$number];
    }
}
