<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\TeacherRoster;

/**
 * Docentes del SII (D4, D9, D10 confirmados el 5 de noviembre de 2026; el resto, supuestos por confirmar). Ver
 * `TeacherRoster` para quién es docente, el cambio de RFC (estatus 04), el departamento, el nombre, el correo y
 * la fecha de ingreso. Un solo lote `teachers` con un renglón por docente (su adscripción es el departamento);
 * el grado queda vacío con un aviso agregado. Recuerda `teacher_for_rfc`: cada RFC del SII, también los viejos
 * de estatus 04, al RFC del docente en Nexus.
 */
final class TeachersStep implements SiiStep
{
    public function name(): string
    {
        return 'docentes';
    }

    public function batches(SiiMigration $migration): iterable
    {
        /** @var array<string|int, string|null> $departmentForArea */
        $departmentForArea = $migration->remember('department_for_area', fn () => throw new SiiStepOutOfOrder($this->name(), 'department_for_area', ['organigrama']));

        /** @var TeacherRoster $roster */
        $roster = $migration->remember('teacher_roster', fn (): TeacherRoster => TeacherRoster::build($migration, $departmentForArea));
        $migration->remember('teacher_for_rfc', fn (): array => $roster->teacherFor);

        yield new SiiBatch('teachers', 'docentes.csv', $roster->rows, incidents: $roster->incidents);
    }
}
