<?php

use App\Modules\Core\Actions\OpenNotification;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Notifications\ChangeRequestApproved;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| Los avisos de una solicitud de cambio son de la modalidad (MS14): `modality_id` es el alcance y el campus del grupo va
| en el filtro de la pantalla (`parameters.campus`), como en los préstamos de salón. Así, si el grupo cambia de campus
| (M8), quien pidió el cambio con un nombramiento del campus de antes sigue abriendo su aviso. La migración
| 2026_11_12_211201 pasa los avisos guardados a esa forma.
*/

const CHANGE_REQUEST_NOTICE_MIGRATION = 'app/Modules/Staffing/Database/Migrations/2026_11_12_211201_move_change_request_notice_campus_to_parameters.php';

it('MS14: el aviso lleva la modalidad como alcance y el campus del grupo solo como filtro de la pantalla', function () {
    $s = changeScenario();
    $request = requestFor($s['group']);

    $data = (new ChangeRequestApproved($request))->message($s['coordinador'])->toArray();

    expect($data['campus_id'])->toBeNull()
        ->and($data['modality_id'])->toBe(modalityOf('PRES')->id)
        ->and($data['parameters'])->toBe(['solicitud' => $request->id, 'campus' => $s['group']->campus->code]);
});

it('MS14, M8: el coordinador del campus de antes abre el aviso de su solicitud aunque el grupo ya se cambió de campus', function () {
    $s = changeScenario();
    $request = requestFor($s['group'], 'pending', ['requested_by' => $s['coordinador']->id]);
    $to = Campus::factory()->create();
    $s['group']->update(['campus_id' => $to->id]);
    $s['coordinador']->notify(new ChangeRequestApproved($request->fresh()));

    $this->actingAs($s['coordinador']);
    $target = app(OpenNotification::class)->handle($s['coordinador'], $s['coordinador']->notifications()->value('id'));
    parse_str((string) parse_url((string) $target->url, PHP_URL_QUERY), $query);

    expect($target->problem)->toBeNull()
        ->and(parse_url((string) $target->url, PHP_URL_PATH))->toBe(parse_url(route('staffing.change-requests'), PHP_URL_PATH))
        ->and($query)->toEqual(['solicitud' => $request->id, 'campus' => $to->code, 'modalidad' => 'PRES']);
});

/**
 * Un aviso guardado con la forma de antes: el campus como alcance.
 */
function changeNoticeWithCampus(string $type, string $campusId, string $modalityId): string
{
    $id = (string) Str::uuid7();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => $type,
        'notifiable_type' => User::class,
        'notifiable_id' => User::factory()->create()->id,
        'data' => json_encode([
            'title' => 'Aviso', 'body' => 'Cuerpo', 'icon' => 'o-bell', 'tone' => 'info', 'route' => 'staffing.change-requests',
            'parameters' => ['solicitud' => 'abc'], 'term_id' => null, 'campus_id' => $campusId, 'modality_id' => $modalityId,
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @return array<string, mixed>
 */
function changeNoticeData(string $id): array
{
    return json_decode(DB::table('notifications')->where('id', $id)->value('data'), true);
}

it('MS14: los avisos guardados de solicitudes de cambio pasan su campus al filtro de la pantalla; los de otros tipos no cambian', function () {
    $migration = require base_path(CHANGE_REQUEST_NOTICE_MIGRATION);
    $campus = Campus::factory()->create(['code' => 'C2']);
    $modality = modalityOf('PRES')->id;
    $types = ['ChangeRequested', 'ChangeRequestApproved', 'ChangeRequestRejected', 'ChangeRequestApplied', 'ChangeRequestPendingApplication'];
    $notices = array_map(fn (string $type): string => changeNoticeWithCampus("App\\Modules\\Staffing\\Notifications\\{$type}", $campus->id, $modality), $types);
    $closing = changeNoticeWithCampus('App\\Modules\\AcademicLoad\\Notifications\\LoadReopened', $campus->id, $modality);

    $migration->up();
    $migration->up();

    foreach ($notices as $notice) {
        expect(changeNoticeData($notice))
            ->campus_id->toBeNull()
            ->modality_id->toBe($modality)
            ->parameters->toEqual(['solicitud' => 'abc', 'campus' => 'C2']);
    }

    expect(changeNoticeData($closing))->campus_id->toBe($campus->id)->parameters->toBe(['solicitud' => 'abc']);

    $migration->down();

    expect(changeNoticeData($notices[0]))->campus_id->toBe($campus->id)->parameters->toBe(['solicitud' => 'abc']);
});
