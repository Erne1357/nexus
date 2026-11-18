<?php

use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Queries\GroupRankingQuery;

/*
| GroupRankingQuery (HC-16): un grupo cancelado (al armar paralelos, tarea 8.8) no tiene ranking aunque RankGroups todavía
| no haya borrado sus renglones.
*/

it('no devuelve el ranking de un grupo cancelado', function () {
    $term = Term::factory()->create();
    $active = Group::factory()->create(['term_id' => $term->id]);
    $cancelled = Group::factory()->create(['term_id' => $term->id]);
    StaffingRanking::factory()->create(['group_id' => $active->id]);
    StaffingRanking::factory()->create(['group_id' => $cancelled->id]);
    $cancelled->update(['status' => GroupStatus::Cancelled]);

    $rankings = app(GroupRankingQuery::class)->forGroups($term, [$active->id, $cancelled->id]);

    expect($rankings[$active->id]->rows)->toHaveCount(1)
        ->and($rankings[$cancelled->id]->rows)->toBe([]);
});
