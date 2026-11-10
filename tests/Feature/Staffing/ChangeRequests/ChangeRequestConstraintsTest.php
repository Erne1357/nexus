<?php

use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| change_requests: restricciones de la base (tarea 3.11). Una solicitud abierta (pendiente o aprobada sin aplicar)
| por docente y grupo; el motivo y, al rechazar, el comentario, no pueden ir en blanco. Las filas nunca se borran.
*/

it('la base rechaza una segunda solicitud abierta del mismo docente en el mismo grupo', function () {
    $s = changeScenario();
    requestFor($s['group'], 'pending', ['teacher_id' => $s['teacher']->id]);

    expect(fn () => DB::transaction(fn () => requestFor($s['group'], 'approved', ['teacher_id' => $s['teacher']->id])))->toThrow(QueryException::class);
});

it('el índice único no cuenta las solicitudes cerradas, otro grupo ni las del coordinador', function () {
    $s = changeScenario();
    requestFor($s['group'], 'rejected', ['teacher_id' => $s['teacher']->id]);
    requestFor($s['group'], 'applied', ['teacher_id' => $s['teacher']->id]);
    requestFor($s['group'], 'pending', ['teacher_id' => $s['teacher']->id]);
    requestFor($s['group'], 'pending', ['teacher_id' => null]);
    requestFor($s['group'], 'pending', ['teacher_id' => null]);

    expect(ChangeRequest::query()->count())->toBe(5);
});

it('la base rechaza un motivo en blanco', function () {
    $s = changeScenario();

    expect(fn () => DB::transaction(fn () => requestFor($s['group'], 'pending', ['reason' => '  '])))->toThrow(QueryException::class);
});

it('la base rechaza una solicitud rechazada sin comentario', function () {
    $s = changeScenario();

    expect(fn () => DB::transaction(fn () => requestFor($s['group'], 'rejected', ['decision_comment' => ' '])))->toThrow(QueryException::class);
});

it('la base rechaza un tipo o un estado desconocido', function () {
    $s = changeScenario();
    $row = requestFor($s['group'])->getAttributes();
    DB::table('change_requests')->delete();

    foreach ([['kind' => 'teacher'], ['status' => 'done']] as $override) {
        expect(fn () => DB::transaction(fn () => DB::table('change_requests')->insert([...$row, 'id' => (string) Str::uuid7(), ...$override])))->toThrow(QueryException::class);
    }
});
