<?php

namespace Tests\Support\Scheduling;

use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Scheduling\Validation\ScheduleStateBuilder;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateRoom;
use App\Modules\Scheduling\Validation\State\StateSubject;
use App\Modules\Scheduling\Validation\State\StateTeacher;

/**
 * Atajos para describir un horario en las pruebas unitarias de las reglas, sin base: un grupo con lo mínimo, una
 * materia, un salón y un docente, con valores de omisión legibles (campus «C2», modalidad «PRES», plan «P1», semestre 1).
 */
final class States
{
    public static function builder(): ScheduleStateBuilder
    {
        return new ScheduleStateBuilder('20271');
    }

    /**
     * Un grupo con su materia (`subject` o `S-<id>`), en el campus, modalidad, plan y semestre indicados.
     */
    public static function group(
        string $id,
        ?string $teacher = null,
        string $campus = 'C2',
        ?string $subject = null,
        string $department = 'D1',
        string $career = 'ISC',
        string $plan = 'P1',
        int $semester = 1,
        ?string $code = null,
        int $capacity = 35,
        bool $active = true,
        string $modality = 'PRES',
    ): StateGroup {
        return new StateGroup($id, $campus, $modality, $subject ?? "S-{$id}", $department, $career, $plan, $semester, $code ?? $id, $teacher, $capacity, $active);
    }

    public static function subject(string $id, string $name = 'Materia', int $theory = 3, int $practice = 2, bool $lab = false, bool $active = true): StateSubject
    {
        return new StateSubject($id, $id, $name, $theory, $practice, $lab, $active);
    }

    public static function room(string $id, int $capacity = 35, RoomType $type = RoomType::Aula, ?string $department = null, string $campus = 'C2', bool $active = true): StateRoom
    {
        return new StateRoom($id, $campus, $id, $capacity, $type, $department, $active);
    }

    /**
     * @param  list<array{int, int, int}>  $unavailable
     */
    public static function teacher(string $id, string $name = 'Docente', int $base = 40, int $external = 0, array $unavailable = [], bool $honorarios = false): StateTeacher
    {
        return new StateTeacher($id, $name, $base, $external, $unavailable, $honorarios);
    }
}
