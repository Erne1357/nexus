<?php

use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorGroup;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorRoom;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorSession;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupSpec;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use App\Modules\Scheduling\Rules\Reanalysis\SuggestionBatch;

/*
| Aplicar en lote las sugerencias del re-análisis (seguimiento de la tarea 8.3a): qué elegidas chocan entre sí (dos que
| cambian el mismo grupo, o dos que abren un grupo), en qué orden se aplican (abrir un grupo primero) y lo que dice la
| confirmación (cuántas de cada tipo, qué grupos se cierran y qué horario pierden).
*/

function batchSuggestion(ReanalysisSuggestionKind $kind, string $title, ?string $groupId = null, array $receivers = [], bool $opens = false): ReanalysisSuggestion
{
    return new ReanalysisSuggestion(
        key: $kind->value.':'.($groupId ?? 'nuevo').':'.$title,
        kind: $kind,
        title: $title,
        evidence: [],
        effect: [],
        groupId: $groupId,
        relocations: array_map(fn (string $receiver): array => ['groupId' => $receiver, 'groupCode' => $receiver, 'careerId' => 'ISIC', 'careerCode' => 'ISIC', 'seats' => 5], $receivers),
        newGroup: $opens ? new NewGroupSpec('S', 'ACF-0902', 'C2', 'C2', 'ISIC', 'ISIC', 'P', 2, 35, 20) : null,
    );
}

it('dos que cambian el mismo grupo chocan, también un cierre que reparte en el grupo que otra amplía', function () {
    $expandA = batchSuggestion(ReanalysisSuggestionKind::ExpandQuota, 'Ampliar el cupo de ISIC en el grupo A', 'A');
    $expandAForIind = batchSuggestion(ReanalysisSuggestionKind::ExpandQuota, 'Ampliar el cupo de IIND en el grupo A', 'A');
    $roomA = batchSuggestion(ReanalysisSuggestionKind::ChangeRoom, 'Cambiar el salón del grupo A', 'A');
    $closeE = batchSuggestion(ReanalysisSuggestionKind::CloseGroup, 'Cerrar el grupo E', 'E', ['A']);
    $expandB = batchSuggestion(ReanalysisSuggestionKind::ExpandQuota, 'Ampliar el cupo de ISIC en el grupo B', 'B');

    expect(SuggestionBatch::clash($expandA, $expandAForIind))->toBe('las dos cambian el mismo grupo')
        ->and(SuggestionBatch::clash($expandA, $roomA))->toBe('las dos cambian el mismo grupo')
        ->and(SuggestionBatch::clash($closeE, $expandA))->toBe('las dos cambian el mismo grupo')
        ->and(SuggestionBatch::clash($expandA, $expandB))->toBeNull()
        ->and(SuggestionBatch::clash($closeE, $expandB))->toBeNull()
        ->and(SuggestionBatch::firstClash([$expandB, $closeE, $expandA]))->toBe('«Cerrar el grupo E» y «Ampliar el cupo de ISIC en el grupo A»: las dos cambian el mismo grupo. Aplica una y revisa la otra con las sugerencias de ahora.')
        ->and(SuggestionBatch::firstClash([$expandA, $expandB, $roomA]))->toStartWith('«Ampliar el cupo de ISIC en el grupo A» y «Cambiar el salón del grupo A»')
        ->and(SuggestionBatch::firstClash([$expandA, $expandB]))->toBeNull();
});

it('se abre un grupo por vez; abrir uno no choca con lo que cambia los grupos que ya hay', function () {
    $openIsic = batchSuggestion(ReanalysisSuggestionKind::OpenGroup, 'Abrir un grupo para ISIC', opens: true);
    $openIind = batchSuggestion(ReanalysisSuggestionKind::OpenGroup, 'Abrir un grupo para IIND', opens: true);
    $expandA = batchSuggestion(ReanalysisSuggestionKind::ExpandQuota, 'Ampliar el cupo de ISIC en el grupo A', 'A');
    $closeE = batchSuggestion(ReanalysisSuggestionKind::CloseGroup, 'Cerrar el grupo E', 'E');

    expect(SuggestionBatch::clash($openIsic, $openIind))->toBe('las dos abren un grupo, y se abre uno por vez')
        ->and(SuggestionBatch::clash($openIsic, $expandA))->toBeNull()
        ->and(SuggestionBatch::clash($closeE, $openIsic))->toBeNull();
});

it('aplica primero abrir un grupo, que revisa todos los grupos del bloque como se leyeron, y luego las demás en su orden', function () {
    $expandA = batchSuggestion(ReanalysisSuggestionKind::ExpandQuota, 'Ampliar A', 'A');
    $closeE = batchSuggestion(ReanalysisSuggestionKind::CloseGroup, 'Cerrar E', 'E');
    $open = batchSuggestion(ReanalysisSuggestionKind::OpenGroup, 'Abrir', opens: true);

    expect(array_map(fn (ReanalysisSuggestion $suggestion): string => $suggestion->title, SuggestionBatch::inOrder([$expandA, $closeE, $open])))
        ->toBe(['Abrir', 'Ampliar A', 'Cerrar E']);
});

it('la confirmación dice cuántas de cada tipo, qué grupos se cierran y qué horario pierden', function () {
    $groups = [
        new AdvisorGroup('A', 'A', 40, ['ISIC' => 40], [new AdvisorSession('s-a', 1, '07:00', '09:00', new AdvisorRoom('r', 'B-12', 50))]),
        new AdvisorGroup('E', 'E', 20, ['IIND' => 20], [
            new AdvisorSession('s-e1', 2, '07:00', '09:00', new AdvisorRoom('r1', 'B-14', 50)),
            new AdvisorSession('s-e2', 4, '07:00', '08:00', new AdvisorRoom('r2', 'B-14', 50)),
        ]),
        new AdvisorGroup('F', 'F', 20, ['IIND' => 20]),
    ];
    $chosen = [
        batchSuggestion(ReanalysisSuggestionKind::ExpandQuota, 'Ampliar A', 'A'),
        batchSuggestion(ReanalysisSuggestionKind::ExpandQuota, 'Ampliar B', 'B'),
        batchSuggestion(ReanalysisSuggestionKind::OpenGroup, 'Abrir', opens: true),
        batchSuggestion(ReanalysisSuggestionKind::CloseGroup, 'Cerrar E', 'E'),
        batchSuggestion(ReanalysisSuggestionKind::CloseGroup, 'Cerrar F', 'F'),
    ];

    expect(SuggestionBatch::summary($chosen, $groups))->toBe(
        'Se amplían 2 cupos, se abre 1 grupo y se cierran 2 grupos. El grupo E se cancela y pierde su horario: mar 7:00–9:00 y jue 7:00–8:00. '
        .'El grupo F se cancela; no tenía horario. Cada una se aplica por separado: si una ya no se puede, se aplican las demás y te dice cuál no.'
    )
        ->and(SuggestionBatch::summary([batchSuggestion(ReanalysisSuggestionKind::ChangeRoom, 'Cambiar A', 'A')], $groups))
        ->toBe('1 grupo cambia de salón. Cada una se aplica por separado: si una ya no se puede, se aplican las demás y te dice cuál no.');
});
