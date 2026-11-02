<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| HC-18: ninguna transición se edita ni se borra; la base rechaza UPDATE, DELETE y TRUNCATE.
*/

it('HC-18: rechaza UPDATE de una transición', function () {
    $row = LoadTransition::factory()->create();

    expect(fn () => DB::table('load_transitions')->where('id', $row->id)->update(['comment' => 'otro']))
        ->toThrow(QueryException::class, 'load_transitions es inmutable');
});

it('HC-18: rechaza DELETE de una transición', function () {
    $row = LoadTransition::factory()->create();

    expect(fn () => DB::table('load_transitions')->where('id', $row->id)->delete())
        ->toThrow(QueryException::class, 'load_transitions es inmutable');
});

it('HC-18: rechaza TRUNCATE de la tabla', function () {
    LoadTransition::factory()->create();

    expect(fn () => DB::statement('truncate table load_transitions'))
        ->toThrow(QueryException::class, 'load_transitions es inmutable');
});

it('acepta INSERT y solo con estados del diagrama', function () {
    expect(LoadTransition::factory()->create()->exists)->toBeTrue()
        ->and(fn () => DB::table('load_transitions')->insert([...['academic_load_id' => AcademicLoad::factory()->create()->id, 'user_id' => User::factory()->create()->id, 'from_status' => 'draft'], 'id' => fakeKey(1), 'to_status' => 'inventado']))->toThrow(QueryException::class);
});
