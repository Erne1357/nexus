<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Validation\Minutes;

/**
 * Aplicar en lote las sugerencias de un bloque del re-análisis (seguimiento de la tarea 8.3a): qué elegidas no se aplican
 * juntas, en qué orden se aplican y lo que dice la confirmación. Puro: no lee la base.
 *
 * Cada sugerencia guarda los grupos que toca como se leyeron (`expected`) y quien la aplica no escribe si cambiaron. Por
 * eso, supuestos por confirmar:
 *
 * - **Chocan** dos que cambian el mismo grupo (dos cupos del mismo grupo, ampliar y cambiar de salón el mismo, o un cierre
 *   que reparte sus lugares en un grupo que otra amplía): la segunda ya no lo encontraría como se leyó. El asesor ya evita
 *   cerrar un grupo que otra amplía, no lo demás. Se aplica una y la otra se vuelve a calcular.
 * - **Chocan** dos que abren un grupo: el motor buscó el lugar de cada uno sin el otro (pueden quedar en el mismo salón a
 *   la misma hora) y la segunda ya no encontraría los grupos del bloque como se leyeron.
 * - **Chocan** abrir un grupo y cambiar de salón otro si el lugar ya buscado del grupo nuevo quiere el salón al que se
 *   mueve una sesión a una hora que se empalma: el motor buscó el lugar leyendo la base, sin el cambio pendiente, y
 *   aplicar las dos dejaría el grupo nuevo y el cambio fallido (lo caro sin lo barato).
 * - **Abrir un grupo va primero:** revisa todos los grupos vivos del bloque como se leyeron, así que después de cambiar
 *   uno ya no se aplicaría. Lo demás solo revisa los grupos que toca, que el grupo nuevo no cambia: no chocan.
 */
final class SuggestionBatch
{
    /**
     * Por qué dos sugerencias del mismo bloque no se aplican juntas, o null si sí.
     *
     * @param  array<string, NewGroupPlacement>  $placements  el lugar ya buscado de cada «Abrir un grupo», por llave
     */
    public static function clash(ReanalysisSuggestion $one, ReanalysisSuggestion $other, array $placements = []): ?string
    {
        if ($one->kind === ReanalysisSuggestionKind::OpenGroup && $other->kind === ReanalysisSuggestionKind::OpenGroup) {
            return 'las dos abren un grupo, y se abre uno por vez';
        }

        if ($one->kind === ReanalysisSuggestionKind::OpenGroup || $other->kind === ReanalysisSuggestionKind::OpenGroup) {
            [$opening, $moving] = $one->kind === ReanalysisSuggestionKind::OpenGroup ? [$one, $other] : [$other, $one];

            return self::sameRoom($placements[$opening->key] ?? null, $moving);
        }

        return array_intersect($one->groupIds(), $other->groupIds()) === [] ? null : 'las dos cambian el mismo grupo';
    }

    /**
     * Lo que el jefe vio de una sugerencia: su tipo, su título, «Qué cambia», los grupos que toca, los lugares, el salón
     * al que va cada sesión que se mueve, los lugares que se reparten y el grupo nuevo. Si al aplicar ya no es la misma,
     * no se aplica sin que la vea. No cuentan «Por qué» (la evidencia) ni `expected`, que cambian con cualquier otro
     * ajuste del bloque sin cambiar lo que hace.
     */
    public static function signature(ReanalysisSuggestion $suggestion): string
    {
        return hash('xxh128', serialize([
            $suggestion->kind->value,
            $suggestion->title,
            $suggestion->effect,
            $suggestion->groupIds(),
            $suggestion->seats,
            array_map(fn (array $move): array => [$move['sessionId'], $move['to']->id], $suggestion->moves),
            $suggestion->relocations,
            $suggestion->newGroup,
        ]));
    }

    /**
     * Si el lugar del grupo nuevo quiere, a una hora que se empalma, el salón al que el cambio mueve una sesión.
     */
    private static function sameRoom(?NewGroupPlacement $placement, ReanalysisSuggestion $moving): ?string
    {
        foreach ($moving->moves as $move) {
            foreach ($placement?->sessions ?? [] as $session) {
                if ($session['roomId'] === $move['to']->id && $session['day'] === $move['day']
                    && Minutes::overlap(Minutes::of($session['startsAt']), Minutes::of($session['endsAt']), Minutes::of($move['startsAt']), Minutes::of($move['endsAt']))) {
                    return "las dos quieren {$move['to']->code} a la misma hora ({$move['when']})";
                }
            }
        }

        return null;
    }

    /**
     * La primera pareja de elegidas que chocan, dicha para quien aplica, o null si ninguna.
     *
     * @param  list<ReanalysisSuggestion>  $suggestions
     * @param  array<string, NewGroupPlacement>  $placements  el lugar ya buscado de cada «Abrir un grupo», por llave
     */
    public static function firstClash(array $suggestions, array $placements = []): ?string
    {
        foreach ($suggestions as $index => $one) {
            foreach (array_slice($suggestions, $index + 1) as $other) {
                if (($reason = self::clash($one, $other, $placements)) !== null) {
                    return "«{$one->title}» y «{$other->title}»: {$reason}. Aplica una y revisa la otra con las sugerencias de ahora.";
                }
            }
        }

        return null;
    }

    /**
     * En qué orden se aplican: abrir un grupo primero y luego las demás como vienen.
     *
     * @param  list<ReanalysisSuggestion>  $suggestions
     * @return list<ReanalysisSuggestion>
     */
    public static function inOrder(array $suggestions): array
    {
        $opens = fn (ReanalysisSuggestion $suggestion): bool => $suggestion->kind === ReanalysisSuggestionKind::OpenGroup;

        return [...array_filter($suggestions, $opens), ...array_filter($suggestions, fn (ReanalysisSuggestion $suggestion): bool => ! $opens($suggestion))];
    }

    /**
     * Lo que dice la confirmación: cuántas de cada tipo, qué grupos se cierran y qué horario pierden, y que cada una va
     * por separado.
     *
     * @param  list<ReanalysisSuggestion>  $suggestions
     * @param  list<AdvisorGroup>  $groups  los grupos vivos del bloque
     */
    public static function summary(array $suggestions, array $groups): string
    {
        $count = fn (ReanalysisSuggestionKind $kind): int => count(array_filter($suggestions, fn (ReanalysisSuggestion $suggestion): bool => $suggestion->kind === $kind));
        $parts = array_filter([
            self::phrase($count(ReanalysisSuggestionKind::ExpandQuota), 'se amplía 1 cupo', 'se amplían %d cupos'),
            self::phrase($count(ReanalysisSuggestionKind::ChangeRoom), '1 grupo cambia de salón', '%d grupos cambian de salón'),
            self::phrase($count(ReanalysisSuggestionKind::OpenGroup), 'se abre 1 grupo', 'se abren %d grupos'),
            self::phrase($count(ReanalysisSuggestionKind::CloseGroup), 'se cierra 1 grupo', 'se cierran %d grupos'),
        ]);
        $lines = [self::capitalized(self::listed($parts)).'.'];

        foreach ($suggestions as $suggestion) {
            if ($suggestion->kind !== ReanalysisSuggestionKind::CloseGroup) {
                continue;
            }

            $group = array_find($groups, fn (AdvisorGroup $group): bool => $group->id === $suggestion->groupId);
            $sessions = $group === null ? [] : array_map(fn (AdvisorSession $session): string => $session->label(), $group->sessions);
            $code = $group->code ?? $suggestion->groupId;

            $lines[] = $sessions === []
                ? "El grupo {$code} se cancela; no tenía horario."
                : "El grupo {$code} se cancela y pierde su horario: ".self::listed($sessions).'.';
        }

        $lines[] = 'Cada una se aplica por separado: si una ya no se puede, se aplican las demás y te dice cuál no.';

        return implode(' ', $lines);
    }

    private static function phrase(int $count, string $one, string $many): ?string
    {
        return match (true) {
            $count === 0 => null,
            $count === 1 => $one,
            default => sprintf($many, $count),
        };
    }

    /**
     * «a», «a y b», «a, b y c».
     *
     * @param  array<int, string>  $items
     */
    private static function listed(array $items): string
    {
        $items = array_values($items);
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items)." y {$last}";
    }

    private static function capitalized(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
