<?php

use App\Modules\Solver\Contract\ContractException;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;

/**
 * @return array<string, mixed>
 */
function contractBaseInput(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/tiny/input.json'), true, flags: JSON_THROW_ON_ERROR);
}

dataset('contractBrokenInputs', [
    'versión distinta de 1' => [fn (array $d) => array_replace($d, ['version' => 2]), 'version', 'versión no soportada'],
    'campus desconocido en un grupo' => [function (array $d) {
        $d['groups'][1]['campus_code'] = 'C9';

        return $d;
    }, 'groups[1].campus_code', 'no está en campuses'],
    'id de grupo repetido' => [function (array $d) {
        $d['groups'][2]['id'] = $d['groups'][0]['id'];

        return $d;
    }, 'groups[2].id', 'repetido'],
    'docente que no está en teachers' => [function (array $d) {
        $d['groups'][0]['teacher_id'] = '0190a000-0000-7000-8000-999999999999';

        return $d;
    }, 'groups[0].teacher_id', 'teachers'],
    'alcance campus' => [function (array $d) {
        $d['scope']['kind'] = 'campus';

        return $d;
    }, 'scope.kind', 'department, career'],
    'día 7' => [function (array $d) {
        $d['teachers'][0]['preferred'][0]['day'] = 7;

        return $d;
    }, 'teachers[0].preferred[0].day', 'entre 1 y 6'],
    'bloque que no divide la jornada' => [function (array $d) {
        $d['campuses'][0]['block_minutes'] = 50;

        return $d;
    }, 'campuses[0].day_end', 'no se divide en bloques'],
    'fin antes del inicio' => [function (array $d) {
        $d['rooms'][1]['blocks'][0]['ends_at'] = '12:00';

        return $d;
    }, 'rooms[1].blocks[0].ends_at', 'posterior'],
    'peso blando negativo' => [function (array $d) {
        $d['soft_weights']['SC-03'] = -1;

        return $d;
    }, 'soft_weights.SC-03', 'entero de 0 en adelante'],
    'peso blando sobre el tope' => [function (array $d) {
        $d['soft_weights']['SC-01'] = 10001;

        return $d;
    }, 'soft_weights.SC-01', 'debe estar entre 0 y 10 000'],
    'peso blando desconocido' => [function (array $d) {
        $d['soft_weights']['SC-10'] = 1;

        return $d;
    }, 'soft_weights.SC-10', 'desconocida'],
    'regla dura mal escrita' => [function (array $d) {
        $d['hard_rules'][2] = 'HC-3';

        return $d;
    }, 'hard_rules[2]', 'HC-'],
    'salón candidato inexistente' => [function (array $d) {
        $d['groups'][0]['candidate_room_ids'] = ['0190a000-0000-7000-8000-999999999999'];

        return $d;
    }, 'groups[0].candidate_room_ids[0]', 'rooms'],
    'horas semanales en cero' => [function (array $d) {
        $d['groups'][2]['weekly_hours'] = 0;

        return $d;
    }, 'groups[2].weekly_hours', 'debe ser un entero de 1 en adelante'],
    'pista de un grupo desconocido' => [function (array $d) {
        $d['hint_sessions'] = [['group_id' => 'no-existe', 'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'kind' => 'teoria']];

        return $d;
    }, 'hint_sessions[0].group_id', 'groups'],
    'pista de un salón desconocido' => [function (array $d) {
        $d['hint_sessions'] = [['group_id' => $d['groups'][0]['id'], 'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room_id' => 'no-existe', 'kind' => 'teoria']];

        return $d;
    }, 'hint_sessions[0].room_id', 'rooms'],
    'pista con fin antes del inicio' => [function (array $d) {
        $d['hint_sessions'] = [['group_id' => $d['groups'][0]['id'], 'day' => 1, 'starts_at' => '11:00', 'ends_at' => '09:00', 'kind' => 'teoria']];

        return $d;
    }, 'hint_sessions[0].ends_at', 'posterior'],
    'pista con hora mal escrita' => [function (array $d) {
        $d['hint_sessions'] = [['group_id' => $d['groups'][0]['id'], 'day' => 1, 'starts_at' => '9:00', 'ends_at' => '11:00', 'kind' => 'teoria']];

        return $d;
    }, 'hint_sessions[0].starts_at', 'HH:MM'],
    'pista en el día 7' => [function (array $d) {
        $d['hint_sessions'] = [['group_id' => $d['groups'][0]['id'], 'day' => 7, 'starts_at' => '09:00', 'ends_at' => '11:00', 'kind' => 'teoria']];

        return $d;
    }, 'hint_sessions[0].day', 'entre 1 y 6'],
]);

test('una entrada rota se rechaza con la ruta del campo', function (Closure $break, string $path, string $fragment) {
    try {
        SolverInput::fromArray($break(contractBaseInput()));
    } catch (ContractException $e) {
        expect($e->path)->toBe($path)
            ->and($e->getMessage())->toStartWith($path.': ')
            ->and($e->getMessage())->toContain($fragment);

        return;
    }

    $this->fail('Se esperaba ContractException.');
})->with('contractBrokenInputs');

test('una salida feasible con violaciones duras se rechaza', function () {
    $data = json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/tiny/output.json'), true, flags: JSON_THROW_ON_ERROR);
    $data['hard_violations'] = [['rule' => 'HC-01', 'group_ids' => [$data['sessions'][0]['group_id']], 'message' => 'Docente empalmado.']];

    expect(fn () => SolverOutput::fromArray($data))->toThrow(ContractException::class, 'hard_violations');
});
