<?php

use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\MarkChangeRequestApplied;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| El orden de los candados de las Actions de solicitudes de cambio: modalidad FOR SHARE, carga FOR UPDATE, grupo FOR
| UPDATE y, al final, la solicitud; así aprobar y rechazar la misma solicitud a la vez no se traban (LocksChangeRequestScope).
*/

/**
 * Las tablas que `$work` bloquea, en el orden en que toma el primer candado de cada una.
 *
 * @return list<string>
 */
function lockedTables(Closure $work): array
{
    $tables = [];
    DB::listen(function ($query) use (&$tables): void {
        if (preg_match('/from "(\w+)".*for (update|share)/s', $query->sql, $match) && ! in_array($match[1], $tables, true)) {
            $tables[] = $match[1];
        }
    });
    $work();

    return $tables;
}

it('aprobar toma modalidad, carga, grupo y solicitud en ese orden', function () {
    $s = changeScenario();
    $request = requestFor($s['group']);

    $tables = lockedTables(fn () => app(ApproveChangeRequest::class)->handle($request, $s['jefe']));

    expect(array_slice($tables, 0, 4))->toBe(['modalities', 'academic_loads', 'groups', 'change_requests']);
});

it('rechazar toma los mismos candados en el mismo orden', function () {
    $s = changeScenario();
    $request = requestFor($s['group']);

    $tables = lockedTables(fn () => app(RejectChangeRequest::class)->handle($request, $s['jefe'], 'No hay horario'));

    expect(array_slice($tables, 0, 4))->toBe(['modalities', 'academic_loads', 'groups', 'change_requests']);
});

it('marcar como aplicada toma los mismos candados en el mismo orden', function () {
    $s = changeScenario();
    $request = requestFor($s['group'], 'approved');

    $tables = lockedTables(fn () => app(MarkChangeRequestApplied::class)->handle($request, $s['coordinador']));

    expect(array_slice($tables, 0, 4))->toBe(['modalities', 'academic_loads', 'groups', 'change_requests']);
});

it('pedir un cambio toma modalidad, carga y grupo antes de crear la solicitud', function () {
    $s = changeScenario();

    $tables = lockedTables(fn () => app(RequestChange::class)->handle($s['group'], $s['teacherUser'], ChangeRequestKind::Schedule, 'Otro día', 'Motivo'));

    expect(array_slice($tables, 0, 3))->toBe(['modalities', 'academic_loads', 'groups']);
});
