<?php

namespace App\Modules\Staffing\Support;

use Carbon\CarbonImmutable;

/**
 * La línea discreta «Publicado el 8 dic. 2026 · v2» de «Mi carga» y de la oferta (P8, decisión del usuario del 10 de
 * noviembre de 2026): una por carga publicada de las que se ven en la pantalla, con la fecha y la versión de lo que se
 * ve. Si la pantalla muestra más de una carga, cada línea dice de cuál: el departamento si se ven varios, el campus si
 * se ven varios («Ciencias Básicas en C1»). Sin cargas publicadas a la vista, no hay línea.
 */
final class PublishedStamps
{
    /**
     * @param  list<array{department: string, campus: string, published: ?array{version: int, at: CarbonImmutable}}>  $loads  la carga de cada grupo que se ve; `published` es null si sale de lo vivo
     * @return list<array{scope: ?string, version: int, published_at: CarbonImmutable}>
     */
    public static function of(array $loads): array
    {
        $unique = [];

        foreach ($loads as $load) {
            $unique[$load['department']."\0".$load['campus']] = $load;
        }

        $departments = count(array_unique(array_column($unique, 'department')));
        $campuses = count(array_unique(array_column($unique, 'campus')));
        $stamps = [];

        foreach ($unique as $load) {
            if ($load['published'] === null) {
                continue;
            }

            $scope = array_filter([$departments > 1 ? $load['department'] : null, $campuses > 1 ? $load['campus'] : null]);
            $stamps[] = [
                'scope' => $scope === [] ? null : implode(' en ', $scope),
                'version' => $load['published']['version'],
                'published_at' => $load['published']['at'],
            ];
        }

        usort($stamps, fn (array $a, array $b): int => strcmp((string) $a['scope'], (string) $b['scope']));

        return $stamps;
    }
}
