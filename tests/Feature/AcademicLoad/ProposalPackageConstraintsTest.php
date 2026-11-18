<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Models\ProposalPackageSubject;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Las tablas de paquetes y nuevo ingreso (tarea 5.6b): código único por propuesta, una materia una vez por paquete, una fila
| por columna con campus nulo incluido (`nulls not distinct`, MS5), conteos no negativos y `requested_groups >=
| package_groups` en la fila.
*/

it('el código del paquete es único por propuesta, no entre propuestas', function () {
    $package = ProposalPackage::factory()->create(['code' => 'P1']);

    expect(fn () => DB::transaction(fn () => ProposalPackage::factory()->create(['career_proposal_id' => $package->career_proposal_id, 'code' => 'P1'])))->toThrow(QueryException::class);

    ProposalPackage::factory()->create(['code' => 'P1']);
    expect(ProposalPackage::query()->count())->toBe(2);
});

it('la posición empieza en 1', function () {
    expect(fn () => DB::transaction(fn () => ProposalPackage::factory()->create(['position' => 0])))->toThrow(QueryException::class);
});

it('una materia entra una vez por paquete', function () {
    $package = ProposalPackage::factory()->create();
    $subject = Subject::factory()->create();
    ProposalPackageSubject::query()->create(['proposal_package_id' => $package->id, 'subject_id' => $subject->id]);

    expect(fn () => DB::transaction(fn () => ProposalPackageSubject::query()->create(['proposal_package_id' => $package->id, 'subject_id' => $subject->id])))->toThrow(QueryException::class);
});

it('la cuenta de un paquete es única por campus y por la columna sin campus', function () {
    $package = ProposalPackage::factory()->create();
    $campus = Campus::factory()->create();

    ProposalPackageCampus::factory()->create(['proposal_package_id' => $package->id, 'campus_id' => $campus->id]);
    ProposalPackageCampus::factory()->create(['proposal_package_id' => $package->id, 'campus_id' => null]);

    expect(fn () => DB::transaction(fn () => ProposalPackageCampus::factory()->create(['proposal_package_id' => $package->id, 'campus_id' => $campus->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ProposalPackageCampus::factory()->create(['proposal_package_id' => $package->id, 'campus_id' => null])))->toThrow(QueryException::class);
});

it('el nuevo ingreso es único por propuesta y campus, también sin campus', function () {
    $intake = ProposalIntake::factory()->create(['campus_id' => null]);

    expect(fn () => DB::transaction(fn () => ProposalIntake::factory()->create(['career_proposal_id' => $intake->career_proposal_id, 'campus_id' => null])))->toThrow(QueryException::class);

    $campus = Campus::factory()->create();
    ProposalIntake::factory()->create(['career_proposal_id' => $intake->career_proposal_id, 'campus_id' => $campus->id]);

    expect(fn () => DB::transaction(fn () => ProposalIntake::factory()->create(['career_proposal_id' => $intake->career_proposal_id, 'campus_id' => $campus->id])))->toThrow(QueryException::class);
});

it('los conteos y los sugeridos no son negativos', function () {
    expect(fn () => DB::transaction(fn () => ProposalPackageCampus::factory()->create(['count' => -1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ProposalPackageCampus::factory()->create(['suggested_count' => -1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ProposalIntake::factory()->create(['new_students' => -1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ProposalIntake::factory()->create(['suggested_new_students' => -1])))->toThrow(QueryException::class);
});

it('requested_groups no baja de package_groups', function () {
    $item = ProposalItem::factory()->create(['requested_groups' => 3]);
    DB::table('proposal_items')->where('id', $item->id)->update(['package_groups' => 3]);

    expect(fn () => DB::transaction(fn () => DB::table('proposal_items')->where('id', $item->id)->update(['requested_groups' => 2])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('proposal_items')->where('id', $item->id)->update(['package_groups' => 4])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('proposal_items')->where('id', $item->id)->update(['package_groups' => -1])))->toThrow(QueryException::class)
        ->and(ProposalItem::factory()->create()->fresh()->package_groups)->toBe(0);
});
