<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Rules\TeacherRules;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Correos institucionales de los docentes desde el archivo de TI (tarea 1.21, D4, confirmado el 14 de noviembre de
 * 2026): el RFC y el correo de cada docente. Solo los importa el admin, como todo lote (ImportBatchPolicy). No da de
 * alta docentes ni cambia otro dato: el RFC busca a un docente que ya existe.
 *
 * Cada fila guarda el correo con UpdateTeacher, la misma Action con que la ficha y la lista lo capturan, a nombre de
 * quien aplica el lote: así se cumplen las mismas reglas (AC-06: el dominio institucional, en minúsculas, único entre
 * todos los docentes, también los borrados) y la invitación de acceso sale por el mismo camino
 * (InviteTeacherAfterValidation, AC-09): si un departamento del docente ya validó su catálogo de docentes, sale en
 * ese momento; si no, al validarlo. Por eso, a diferencia de las demás plantillas, cada docente que cambia deja su
 * propia entrada en la bitácora, además de la del lote.
 *
 * Si el docente ya tiene otro correo institucional, no se cambia: la fila es error y el resumen la lista como «ya
 * tenía otro correo» (D14, supuesto por confirmar); el mismo que ya tiene no cambia nada. Un RFC que no está en
 * Nexus, un correo de otro dominio, uno que ya es de otro docente, o que el archivo repite para otro RFC, son error
 * de su fila. Un docente borrado es error y uno dado de baja solo avisa, como en toda importación. No ofrece
 * «desactivar faltantes»: el archivo solo trae correos.
 */
class TeacherEmailsTemplate extends ImportTemplate
{
    use ChecksTeacherRfc;

    public const string NOT_FOUND = 'No hay un docente con este RFC en Nexus.';

    /** El principio del error de D14; lo sigue el correo que ya tiene. */
    public const string OTHER_EMAIL = 'Ya tiene otro correo institucional';

    /** El principio del error de AC-06 por dominio (TeacherRules::institutionalEmail()). */
    private const string OUTSIDE_DOMAIN = 'El correo institucional debe terminar en';

    /** El principio del error de AC-06 por un correo que ya es de otro docente (TeacherRules::institutionalEmailTaken()). */
    private const string TAKEN = 'Otro docente ya tiene el correo institucional';

    /** El principio de los errores del motor por una clave repetida y por un correo que el archivo da a dos RFC. */
    private const array REPEATED = ['rfc' => 'La clave se repite', 'email' => 'La clave '];

    public function key(): string
    {
        return 'teacher_emails';
    }

    public function label(): string
    {
        return 'Correos institucionales de docentes';
    }

    public function fields(): array
    {
        return [
            'rfc' => 'RFC',
            'email' => 'correo',
        ];
    }

    public function naturalKey(): array
    {
        return ['rfc'];
    }

    public function model(): string
    {
        return Teacher::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    /**
     * Un correo es de un solo docente: si el archivo lo trae para otro RFC, la fila posterior es error.
     */
    public function exclusiveAcrossRows(): ?array
    {
        return ['value' => 'email', 'owner' => 'rfc'];
    }

    public function rules(): array
    {
        return [
            'rfc' => $this->rfcRules(),
            'email' => ['required', 'string', 'max:255'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);
        $row['rfc'] = self::asText($row['rfc']);

        if (is_string($row['rfc'])) {
            try {
                $row['rfc'] = app(TeacherRules::class)->normalizeRfc($row['rfc']);
            } catch (ValidationException) {
                // Texto que no es UTF-8 válido: validate() lo reporta.
            }
        }

        if (is_string($row['email']) && mb_check_encoding($row['email'], 'UTF-8')) {
            $row['email'] = mb_strtolower($row['email']);
        }

        return $row;
    }

    /**
     * Guarda el correo con UpdateTeacher a nombre de quien aplica. Si el docente ya tiene otro, no lo toca (D14):
     * check() ya lo dio como error, y aquí no se cambia aunque llegue. El mismo que ya tiene no escribe nada.
     *
     * @throws LogicException si el RFC no es de un docente sin borrar: validate() ya lo reporta como error
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $teacher = Teacher::query()->where('rfc', $row['rfc'])->first()
            ?? throw new LogicException("La plantilla {$this->key()} no encontró al docente de una fila válida.");

        if ($teacher->institutional_email !== null && $teacher->institutional_email !== $row['email']) {
            return $teacher;
        }

        return app(UpdateTeacher::class)->handle($teacher, ['institutional_email' => $row['email']], $context->user);
    }

    /**
     * Lo que el resumen del lote cuenta aparte (D4): los RFC que no están en Nexus, los correos fuera del dominio, los
     * repetidos (en el archivo o ya de otro docente), los que ya tenían otro correo (D14) y, ya aplicado, las
     * invitaciones que salieron por la importación: las que se mandaron a nombre de quien la aplicó, desde que se
     * subió el archivo, a los docentes de sus filas aplicadas. Salen de la cola en unos segundos (`live`).
     */
    public function summaryGroups(ImportBatch $batch): array
    {
        $groups = [
            ['label' => 'RFC que no están en Nexus', 'one' => 'RFC que no está en Nexus', 'rows' => $this->withError($batch, 'rfc', self::NOT_FOUND)],
            ['label' => 'Correos fuera del dominio', 'one' => 'Correo fuera del dominio', 'rows' => $this->withError($batch, 'email', self::OUTSIDE_DOMAIN)],
            [
                'label' => 'Repetidos',
                'one' => 'Repetido',
                'hint' => 'El archivo repite el RFC o da el mismo correo a dos docentes, o el correo ya es de otro docente.',
                'rows' => $this->errorRows($batch)->where(fn (Builder $rows) => $rows
                    ->where(fn (Builder $repeated) => $this->hasMessage($repeated, 'rfc', self::REPEATED['rfc']))
                    ->orWhere(fn (Builder $repeated) => $this->hasMessage($repeated, 'email', self::REPEATED['email']))
                    ->orWhere(fn (Builder $taken) => $this->hasMessage($taken, 'email', self::TAKEN))),
            ],
            [
                'label' => 'Ya tenían otro correo',
                'one' => 'Ya tenía otro correo',
                'hint' => 'Su correo no se cambia; si el del archivo es el bueno, corrígelo en su ficha.',
                'rows' => $this->withError($batch, 'email', self::OTHER_EMAIL),
            ],
        ];

        if ($batch->status !== BatchStatus::Applied || $batch->applied_by === null) {
            return $groups;
        }

        $groups[] = [
            'label' => 'Invitaciones enviadas',
            'one' => 'Invitación enviada',
            'hint' => 'A los docentes de un departamento que todavía no valida su catálogo de docentes les llega al validarlo.',
            'live' => true,
            'rows' => ImportRow::query()
                ->where('batch_id', $batch->id)
                ->where('status', RowStatus::Applied)
                ->whereExists(fn (QueryBuilder $invited) => $invited->selectRaw('1')
                    ->from('teachers')
                    ->join((new Activity)->getTable().' as invitation', 'invitation.subject_id', '=', 'teachers.user_id')
                    ->whereColumn('teachers.id', 'import_rows.target_id')
                    ->where('invitation.subject_type', (new User)->getMorphClass())
                    ->where('invitation.event', 'access-invited')
                    ->where('invitation.causer_id', $batch->applied_by)
                    ->where('invitation.created_at', '>=', $batch->created_at)),
        ];

        return $groups;
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = $this->warnWithoutHomoclave(parent::check($row, $context, $issues), $row['rfc']);

        try {
            $email = app(TeacherRules::class)->institutionalEmail($row['email']);
        } catch (ValidationException $exception) {
            $email = null;
            $issues = $issues->withError('email', (string) collect($exception->errors())->flatten()->first());
        }

        $teacher = Teacher::withTrashed()->where('rfc', $row['rfc'])->first(['id', 'institutional_email', 'deleted_at']);

        if ($teacher === null) {
            return $issues->withError('rfc', self::NOT_FOUND);
        }

        if ($email === null || $teacher->trashed()) {
            return $issues;
        }

        if ($teacher->institutional_email !== null && $teacher->institutional_email !== $email) {
            return $issues->withError('email', self::OTHER_EMAIL." ({$teacher->institutional_email}): no se cambia.");
        }

        try {
            app(TeacherRules::class)->ensureInstitutionalEmailIsFree($email, $teacher);
        } catch (ValidationException $exception) {
            $issues = $issues->withError('email', (string) collect($exception->errors())->flatten()->first());
        }

        return $issues;
    }

    /**
     * @return Builder<ImportRow>
     */
    private function errorRows(ImportBatch $batch): Builder
    {
        return ImportRow::query()->where('batch_id', $batch->id)->where('status', RowStatus::Error);
    }

    /**
     * Las filas con error del lote con un error en `$field` que empieza con `$prefix`.
     *
     * @return Builder<ImportRow>
     */
    private function withError(ImportBatch $batch, string $field, string $prefix): Builder
    {
        return $this->hasMessage($this->errorRows($batch), $field, $prefix);
    }

    /**
     * @param  Builder<ImportRow>  $rows
     * @return Builder<ImportRow>
     */
    private function hasMessage(Builder $rows, string $field, string $prefix): Builder
    {
        return $rows->whereRaw(
            "exists (select 1 from jsonb_array_elements_text(coalesce(import_rows.errors -> ?, '[]'::jsonb)) as message where starts_with(message, ?))",
            [$field, $prefix],
        );
    }
}
