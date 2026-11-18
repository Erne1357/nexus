<?php

use App\Modules\AcademicLoad\Queries\ReanalysisRow;
use App\Modules\Demand\Enums\DemandBalance;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorGroup;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorRoom;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorSession;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use App\Modules\Scheduling\Rules\ReanalysisAdvisor;

/*
| Las sugerencias del re-análisis de una materia en un campus (tarea 8.3a): con lo esperado de cada carrera (RA-04) y los
| grupos vivos, propone ampliar un cupo dentro de HC-05 y HC-07, cambiar a un salón más grande libre a la misma hora,
| avisa que hace falta abrir un grupo (lo trae el motor, 8.3b) y propone cerrar un grupo sobrante reubicando sus lugares.
| Las carreras de primer semestre no se clasifican (RA-09) y no dan sugerencias.
*/

function advisorRow(string $career, int $expected, bool $firstSemester = false): ReanalysisRow
{
    return new ReanalysisRow(
        campusId: 'C2', campusCode: 'C2', campusName: 'Campus II',
        careerId: $career, careerCode: $career, careerName: $career,
        subjectId: 'S', subjectCode: 'ACF-0902', subjectName: 'Cálculo Integral',
        semester: $firstSemester ? 1 : 2, forecasted: true, expectedStudents: $expected, suggestedGroups: 1, newStudents: null,
        offeredSeats: 0, offeredGroups: 0, isFirstSemester: $firstSemester,
        balance: $firstSemester ? null : DemandBalance::Adjusted, needsReview: false, insufficientHistory: false, computedAt: null,
    );
}

function advisorRoom(string $code, int $capacity, ?string $loanFrom = null): AdvisorRoom
{
    return new AdvisorRoom("room-{$code}", $code, $capacity, $loanFrom);
}

/**
 * @param  list<AdvisorRoom>  $larger
 */
function advisorSession(string $id, ?AdvisorRoom $room, array $larger = [], int $day = 1, string $startsAt = '07:00', string $endsAt = '09:00'): AdvisorSession
{
    return new AdvisorSession($id, $day, $startsAt, $endsAt, $room, $larger);
}

/**
 * @param  array<string, int>  $quotas
 * @param  list<AdvisorSession>|null  $sessions  por omisión, una sesión en un salón de 50
 */
function advisorGroup(string $code, int $capacity, array $quotas, ?array $sessions = null, ?string $teacher = null, int $held = 0): AdvisorGroup
{
    return new AdvisorGroup("group-{$code}", $code, $capacity, $quotas, $sessions ?? [advisorSession("s-{$code}", advisorRoom("R-{$code}", 50))], $held, $teacher);
}

/**
 * @param  list<ReanalysisRow>  $rows
 * @param  list<AdvisorGroup>  $groups
 * @return array<string, ReanalysisSuggestion>
 */
function adviseBlock(array $rows, array $groups): array
{
    $suggestions = (new ReanalysisAdvisor)->advise($rows, $groups, [], DemandSettings::fromValues([]));

    return array_column(array_map(fn (ReanalysisSuggestion $suggestion): array => [$suggestion->key, $suggestion], $suggestions), 1, 0);
}

describe('faltante', function () {
    it('HC-07: amplía el cupo de la carrera con lo que el grupo tiene libre, sin subir la capacidad', function () {
        $suggestions = adviseBlock([advisorRow('ISIC', 50)], [advisorGroup('A', 50, ['ISIC' => 40])]);

        $expand = $suggestions['expand_quota:group-A:ISIC'];

        expect(array_keys($suggestions))->toBe(['expand_quota:group-A:ISIC'])
            ->and($expand->kind)->toBe(ReanalysisSuggestionKind::ExpandQuota)
            ->and($expand->title)->toBe('Ampliar el cupo de ISIC en el grupo A de 40 a 50')
            ->and($expand->seats)->toBe(10)
            ->and($expand->maxSeats)->toBe(10)
            ->and($expand->evidence)->toContain('ISIC: 50 esperados · 40 lugares · faltan 10')
            ->and($expand->effect)->not->toContain('La capacidad del grupo A sube de 50 a 50.');
    });

    it('HC-05: si el grupo está lleno, sube su capacidad hasta su salón y da los lugares nuevos a la carrera', function () {
        $suggestions = adviseBlock([advisorRow('ISIC', 50)], [advisorGroup('A', 40, ['ISIC' => 40], teacher: 'Ana Ruiz')]);

        $expand = $suggestions['expand_quota:group-A:ISIC'];

        expect($expand->seats)->toBe(10)
            ->and($expand->evidence)->toContain('Grupo A: 40 de 40 lugares repartidos · salón R-A de 50 (HC-05)')
            ->and($expand->effect)->toContain('La capacidad del grupo A sube de 40 a 50, dentro de su salón (HC-05, HC-07).')
            ->and($expand->effect)->toContain('Toca a ISIC y a Ana Ruiz, que conserva su horario.');
    });

    it('HC-07: lo que apartan las propuestas de grupo compartido no se reparte', function () {
        $suggestions = adviseBlock([advisorRow('ISIC', 60)], [advisorGroup('A', 50, ['ISIC' => 40], held: 5)]);

        expect($suggestions['expand_quota:group-A:ISIC']->seats)->toBe(5);
    });

    it('si no cabe en su salón, sugiere uno más grande libre a la misma hora y subir el cupo hasta él', function () {
        $sessions = [advisorSession('s-A', advisorRoom('R-A', 50), [advisorRoom('L-60', 60), advisorRoom('L-80', 80)])];
        $suggestions = adviseBlock([advisorRow('ISIC', 65)], [advisorGroup('A', 50, ['ISIC' => 50], $sessions)]);

        $room = $suggestions['change_room:group-A:ISIC'];

        expect(array_keys($suggestions))->toBe(['change_room:group-A:ISIC'])
            ->and($room->title)->toBe('Cambiar el salón del grupo A: R-A → L-80')
            ->and($room->seats)->toBe(15)
            ->and($room->maxSeats)->toBe(30)
            ->and($room->moves)->toHaveCount(1)
            ->and($room->moves[0]['to']->code)->toBe('L-80')
            ->and($room->evidence)->toContain('L-80: 80 lugares · libre lun 7:00–9:00')
            ->and($room->effect)->toContain('La capacidad del grupo A sube de 50 a 65 y ISIC pasa de 50 a 65 lugares.');
    });

    it('elige el salón más chico que alcanza', function () {
        $sessions = [advisorSession('s-A', advisorRoom('R-A', 40), [advisorRoom('L-60', 60), advisorRoom('L-80', 80)])];
        $suggestions = adviseBlock([advisorRow('ISIC', 55)], [advisorGroup('A', 40, ['ISIC' => 40], $sessions)]);

        $room = $suggestions['change_room:group-A:ISIC'];

        expect($room->moves[0]['to']->code)->toBe('L-60')
            ->and($room->seats)->toBe(15)
            ->and($room->maxSeats)->toBe(20)
            ->and($room->effect)->not->toContain('Pide el préstamo de L-60 a Sistemas (HC-10).');
    });

    it('HC-10: un salón de otro departamento se sugiere diciendo que pide préstamo', function () {
        $sessions = [advisorSession('s-A', advisorRoom('R-A', 40), [advisorRoom('L-60', 60, 'Sistemas')])];
        $suggestions = adviseBlock([advisorRow('ISIC', 55)], [advisorGroup('A', 40, ['ISIC' => 40], $sessions)]);

        expect($suggestions['change_room:group-A:ISIC']->effect)->toContain('Pide el préstamo de L-60 a Sistemas (HC-10).');
    });

    it('si nada de lo anterior alcanza, avisa que hace falta abrir un grupo, sin acción', function () {
        $suggestions = adviseBlock([advisorRow('ISIC', 70), advisorRow('IIND', 20)], [
            advisorGroup('A', 40, ['ISIC' => 40]),
            advisorGroup('B', 10, ['IIND' => 10], [advisorSession('s-B', null)]),
        ]);

        $open = $suggestions['open_group'];

        expect(array_keys($suggestions))->toBe(['expand_quota:group-A:ISIC', 'open_group'])
            ->and($open->isApplicable())->toBeFalse()
            ->and($open->title)->toBe('Hace falta abrir un grupo')
            ->and($open->evidence)->toBe(['ISIC: faltan 20 lugares aunque se amplíen los grupos que caben', 'IIND: faltan 10 lugares aunque se amplíen los grupos que caben'])
            ->and($open->effect)->toBe(['Llega con la sugerencia del motor (tarea 8.3b): horario, salón y cupos por carrera.']);
    });

    it('dos grupos no se llevan el mismo salón a la misma hora', function () {
        $big = advisorRoom('L-90', 90);
        $suggestions = adviseBlock([advisorRow('ISIC', 150)], [
            advisorGroup('A', 50, ['ISIC' => 50], [advisorSession('s-A', advisorRoom('R-A', 50), [$big])]),
            advisorGroup('B', 50, ['ISIC' => 50], [advisorSession('s-B', advisorRoom('R-B', 50), [$big])]),
        ]);

        expect(array_keys($suggestions))->toBe(['change_room:group-A:ISIC', 'open_group'])
            ->and($suggestions['change_room:group-A:ISIC']->seats)->toBe(40)
            ->and($suggestions['open_group']->evidence)->toBe(['ISIC: faltan 10 lugares aunque se amplíen los grupos que caben']);
    });
});

describe('sobrante', function () {
    it('cierra el grupo que sobra y reubica en los que quedan los lugares que hacen falta (HC-07)', function () {
        $suggestions = adviseBlock([advisorRow('ISIC', 50)], [
            advisorGroup('D', 50, ['ISIC' => 40], teacher: 'Ana Ruiz'),
            advisorGroup('E', 45, ['ISIC' => 25]),
        ]);

        $close = $suggestions['close_group:group-E'];

        expect(array_keys($suggestions))->toBe(['close_group:group-E'])
            ->and($close->title)->toBe('Cerrar el grupo E')
            ->and($close->warning)->toBeNull()
            ->and($close->relocations)->toBe([['groupId' => 'group-D', 'groupCode' => 'D', 'careerId' => 'ISIC', 'careerCode' => 'ISIC', 'seats' => 10]])
            ->and($close->evidence)->toContain('ISIC: 50 esperados · 65 lugares · sobran 15')
            ->and($close->effect)->toContain('Sus lugares pasan a: grupo D (ISIC +10).')
            ->and($close->effect)->toContain('Toca a ISIC.');
    });

    it('cierra el que no deja a ninguna carrera en faltante, aunque tenga docente, y lo advierte', function () {
        $suggestions = adviseBlock([advisorRow('ISIC', 30)], [
            advisorGroup('D', 45, ['ISIC' => 45], teacher: 'Ana Ruiz'),
            advisorGroup('E', 20, ['ISIC' => 20], teacher: 'Luis Soto'),
        ]);

        $close = $suggestions['close_group:group-E'];

        expect(array_keys($suggestions))->toBe(['close_group:group-E'])
            ->and($close->relocations)->toBe([])
            ->and($close->effect)->toContain('Nadie pierde lugar: los grupos que quedan alcanzan.')
            ->and($close->warning)->toBe('Luis Soto deja de impartir el grupo E (2 h a la semana). Se le avisa al republicar (RA-05).');
    });

    it('no sugiere cerrar si dejaría a una carrera en faltante', function () {
        expect(adviseBlock([advisorRow('ISIC', 30)], [advisorGroup('D', 45, ['ISIC' => 45])]))->toBe([]);
    });

    it('al cerrar, una carrera sin clasificar conserva todos sus lugares o no se cierra', function () {
        $suggestions = adviseBlock([advisorRow('ISIC', 10), advisorRow('IIND', 30, firstSemester: true)], [
            advisorGroup('D', 45, ['ISIC' => 10, 'IIND' => 15]),
            advisorGroup('E', 45, ['ISIC' => 30]),
        ]);

        expect(array_keys($suggestions))->toBe(['close_group:group-E']);
    });
});

it('RA-09: una materia de primer semestre no se clasifica ni da sugerencias', function () {
    expect(adviseBlock([advisorRow('ISIC', 90, firstSemester: true)], [advisorGroup('A', 40, ['ISIC' => 40])]))->toBe([]);
});

it('una carrera ajustada no da sugerencias', function () {
    expect(adviseBlock([advisorRow('ISIC', 44)], [advisorGroup('A', 40, ['ISIC' => 40])]))->toBe([]);
});
