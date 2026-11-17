<?php

use App\Modules\AcademicLoad\Models\ProposalItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| La migración que agrega lo sugerido y la marca de captura a `proposal_items` (tarea 5.5): rellena `captured_at` con
| `updated_at` en las filas que ya se corrigieron (`updated_at > created_at`) y deja sin tocar las demás; los sugeridos no
| pueden ser negativos.
*/

it('rellena captured_at solo en las filas cuyos esperados o grupos ya no son los de origen', function () {
    $migration = require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_16_000001_add_suggestions_to_proposal_items.php');
    $migration->down();

    $origin = ['eligible_students' => 70, 'expected_students' => 70, 'requested_groups' => 2];
    $untouched = ProposalItem::factory()->create($origin);
    $noteOnly = ProposalItem::factory()->create($origin);
    $corrected = ProposalItem::factory()->create([...$origin, 'requested_groups' => 3]);
    DB::table('subjects')->whereIn('id', [$untouched->subject_id, $noteOnly->subject_id, $corrected->subject_id])->update(['needs_lab' => false]);
    DB::table('proposal_items')->where('id', $untouched->id)->update(['created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
    DB::table('proposal_items')->where('id', $noteOnly->id)->update(['created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-02 09:00:00', 'notes' => 'Solo una nota']);
    DB::table('proposal_items')->where('id', $corrected->id)->update(['created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-02 12:00:00']);

    $migration->up();

    $rows = DB::table('proposal_items')->whereIn('id', [$untouched->id, $noteOnly->id, $corrected->id])->pluck('captured_at', 'id');

    expect($rows[$untouched->id])->toBeNull()
        ->and($rows[$noteOnly->id])->toBeNull()
        ->and($rows[$corrected->id])->toStartWith('2026-11-02 12:00:00');
});

it('rechaza sugeridos negativos', function () {
    $item = ProposalItem::factory()->create();

    expect(fn () => DB::table('proposal_items')->where('id', $item->id)->update(['suggested_groups' => -1]))->toThrow(QueryException::class);
});
