<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Models\TeacherTermLoad;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function assignmentRow(array $overrides = []): array
{
    $group = Group::factory()->create();

    return [
        'term_id' => $group->term_id,
        'campus_id' => $group->campus_id,
        'modality_id' => $group->modality_id,
        'group_id' => $group->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'assigned_by' => User::factory()->create()->id,
        'assigned_at' => now(),
        'ranking_position_at_assignment' => 1,
        'ranking_size_at_assignment' => 5,
        ...$overrides,
    ];
}

describe('group_teacher_assignments', function () {
    it('HC-16: la base rechaza asignar fuera del primer lugar sin motivo', function () {
        foreach ([['ranking_position_at_assignment' => 3], ['ranking_position_at_assignment' => 3, 'reason' => '  '], ['ranking_position_at_assignment' => null, 'ranking_size_at_assignment' => null]] as $case) {
            expect(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->insert(assignmentRow($case))))->toThrow(QueryException::class);
        }
    });

    it('HC-16: acepta el primer lugar sin motivo y a otro lugar con motivo', function () {
        DB::table('group_teacher_assignments')->insert(assignmentRow());
        DB::table('group_teacher_assignments')->insert(assignmentRow(['ranking_position_at_assignment' => 3, 'reason' => 'Disponibilidad']));
        DB::table('group_teacher_assignments')->insert(assignmentRow(['ranking_position_at_assignment' => null, 'ranking_size_at_assignment' => null, 'reason' => 'Fuera del ranking']));

        expect(DB::table('group_teacher_assignments')->count())->toBe(3);
    });

    it('rechaza posiciones y tamaños menores a 1', function () {
        expect(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->insert(assignmentRow(['ranking_position_at_assignment' => 0, 'reason' => 'x']))))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->insert(assignmentRow(['ranking_size_at_assignment' => 0]))))->toThrow(QueryException::class);
    });

    it('rechaza dos asignaciones vigentes del mismo grupo y acepta una nueva tras reemplazar la anterior', function () {
        $first = GroupTeacherAssignment::factory()->create();
        $group = $first->group_id;

        expect(fn () => DB::transaction(fn () => GroupTeacherAssignment::factory()->create(['group_id' => $group])))->toThrow(QueryException::class);

        DB::table('group_teacher_assignments')->where('id', $first->id)->update(['replaced_at' => now()->addMinute(), 'replaced_by' => User::factory()->create()->id]);

        GroupTeacherAssignment::factory()->create(['group_id' => $group]);

        expect(GroupTeacherAssignment::query()->where('group_id', $group)->count())->toBe(2);
    });

    it('rechaza reemplazar antes de asignar', function () {
        $assignment = GroupTeacherAssignment::factory()->create();

        expect(fn () => DB::table('group_teacher_assignments')->where('id', $assignment->id)->update(['replaced_at' => now()->subDay(), 'replaced_by' => User::factory()->create()->id]))
            ->toThrow(QueryException::class);
    });

    it('es solo de agregar: rechaza borrar y editar otras columnas', function () {
        $assignment = GroupTeacherAssignment::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $assignment->id)->delete()))->toThrow(QueryException::class, 'group_teacher_assignments es de solo agregar')
            ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $assignment->id)->update(['reason' => 'otro'])))->toThrow(QueryException::class, 'group_teacher_assignments es de solo agregar')
            ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $assignment->id)->update(['teacher_id' => Teacher::factory()->create()->id])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::statement('truncate table group_teacher_assignments')))->toThrow(QueryException::class, 'group_teacher_assignments es de solo agregar');
    });

    it('acepta fijar replaced_at una vez y rechaza un segundo cambio', function () {
        $assignment = GroupTeacherAssignment::factory()->create();
        $by = User::factory()->create()->id;

        DB::table('group_teacher_assignments')->where('id', $assignment->id)->update(['replaced_at' => now()->addMinute(), 'replaced_by' => $by, 'updated_at' => now()]);

        expect($assignment->fresh()->replaced_at)->not->toBeNull()
            ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $assignment->id)->update(['replaced_at' => now()->addHour()])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('group_teacher_assignments')->where('id', $assignment->id)->update(['replaced_by' => User::factory()->create()->id])))->toThrow(QueryException::class);
    });

    it('sus estados de fábrica cumplen HC-16', function () {
        $outside = GroupTeacherAssignment::factory()->outsideTop()->create();
        $replaced = GroupTeacherAssignment::factory()->replaced()->create();

        expect($outside->ranking_position_at_assignment)->toBe(3)
            ->and($outside->ranking_size_at_assignment)->toBe(12)
            ->and($outside->reason)->not->toBeEmpty()
            ->and($replaced->replaced_at)->not->toBeNull()
            ->and($replaced->replacedBy)->toBeInstanceOf(User::class);
    });

    it('relaciona grupo, docente, periodo, campus y usuario', function () {
        $assignment = GroupTeacherAssignment::factory()->create();

        expect($assignment->group)->toBeInstanceOf(Group::class)
            ->and($assignment->teacher)->toBeInstanceOf(Teacher::class)
            ->and($assignment->term)->toBeInstanceOf(Term::class)
            ->and($assignment->campus)->toBeInstanceOf(Campus::class)
            ->and($assignment->assignedBy)->toBeInstanceOf(User::class);
    });
});

describe('staffing_rankings', function () {
    it('es única por (grupo, docente) y por (grupo, lugar)', function () {
        $ranking = StaffingRanking::factory()->create(['rank' => 1]);

        expect(fn () => DB::transaction(fn () => StaffingRanking::factory()->create(['group_id' => $ranking->group_id, 'teacher_id' => $ranking->teacher_id, 'rank' => 2])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => StaffingRanking::factory()->create(['group_id' => $ranking->group_id, 'rank' => 1])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => StaffingRanking::factory()->create(['rank' => 0])))->toThrow(QueryException::class);

        StaffingRanking::factory()->create(['group_id' => $ranking->group_id, 'rank' => 2]);

        expect(StaffingRanking::query()->count())->toBe(2);
    });

    it('guarda el desglose y relaciona sus llaves', function () {
        $ranking = StaffingRanking::factory()->create(['score' => 12.5, 'breakdown' => ['base_hours' => 3]]);
        $fresh = $ranking->fresh();

        expect($fresh->breakdown)->toBe(['base_hours' => 3])
            ->and($fresh->score)->toBe('12.50')
            ->and($fresh->group)->toBeInstanceOf(Group::class)
            ->and($fresh->teacher)->toBeInstanceOf(Teacher::class)
            ->and($fresh->term)->toBeInstanceOf(Term::class)
            ->and($fresh->campus)->toBeInstanceOf(Campus::class);
    });

    it('se borra con su grupo o su docente (tabla derivada)', function () {
        $ranking = StaffingRanking::factory()->create();
        Teacher::query()->whereKey($ranking->teacher_id)->forceDelete();

        expect(StaffingRanking::query()->count())->toBe(0);
    });
});

describe('teacher_term_loads', function () {
    it('es única por (docente, periodo) y acepta horas restantes negativas', function () {
        $load = TeacherTermLoad::factory()->create(['remaining_hours' => -2]);

        expect($load->fresh()->remaining_hours)->toBe(-2)
            ->and($load->teacher)->toBeInstanceOf(Teacher::class)
            ->and($load->term)->toBeInstanceOf(Term::class)
            ->and(fn () => DB::transaction(fn () => TeacherTermLoad::factory()->create(['teacher_id' => $load->teacher_id, 'term_id' => $load->term_id])))->toThrow(QueryException::class);
    });

    it('rechaza horas negativas', function () {
        foreach (['base_hours', 'external_hours', 'assigned_hours', 'limit_hours', 'groups_count'] as $column) {
            expect(fn () => DB::transaction(fn () => TeacherTermLoad::factory()->create([$column => -1])))->toThrow(QueryException::class);
        }
    });
});

describe('permisos de Staffing', function () {
    it('declara ver el ranking y asignar docente, por campus y unidad, para lo académico', function () {
        $registry = app(PermissionRegistry::class);

        foreach ([StaffingPermission::RankingView, StaffingPermission::TeacherAssign] as $permission) {
            expect($registry->permissions())->toContain($permission)
                ->and($permission->isPersonal())->toBeFalse()
                ->and($permission->appliesTo())->not->toBeNull()
                ->and($permission->label())->not->toBeEmpty();
        }

        expect(StaffingPermission::RankingView->value)->toBe('staffing.ranking.view')
            ->and(StaffingPermission::TeacherAssign->value)->toBe('staffing.teacher.assign');
    });

    it('los jefes asignan y ven; la Subdirección solo ve; nadie más', function () {
        $registry = app(PermissionRegistry::class);

        foreach ([Role::JefeDepartamento, Role::JefeProyectoDocente] as $role) {
            expect($registry->grants($role, StaffingPermission::RankingView))->toBeTrue()
                ->and($registry->grants($role, StaffingPermission::TeacherAssign))->toBeTrue();
        }

        expect($registry->grants(Role::Subdireccion, StaffingPermission::RankingView))->toBeTrue()
            ->and($registry->grants(Role::Subdireccion, StaffingPermission::TeacherAssign))->toBeFalse()
            ->and($registry->grants(Role::Coordinador, StaffingPermission::RankingView))->toBeFalse()
            ->and($registry->grants(Role::Coordinador, StaffingPermission::TeacherAssign))->toBeFalse();
    });
});
