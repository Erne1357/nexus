<?php

namespace App\Modules\Staffing\Rules;

/**
 * SEL-07 y SEL-10. Orden de selección: primero los del CSV por escalón ascendente y antigüedad descendente (mismo
 * escalón y misma antigüedad comparten turno, SEL-07); después los que no vienen en el CSV (`appended`) por fecha de
 * contratación ascendente, con los nulos al final por nombre (misma fecha comparten turno, SEL-10). Puro.
 */
final readonly class SelectionOrder
{
    /**
     * @param  list<array{key: string, source: string, tier: ?int, seniority: ?float, hired_on: ?string, name: string}>  $entries
     * @return list<array{key: string, position: int, slot_index: int}>
     */
    public static function rank(array $entries): array
    {
        $csv = array_values(array_filter($entries, fn (array $e): bool => $e['source'] === 'csv'));
        $appended = array_values(array_filter($entries, fn (array $e): bool => $e['source'] !== 'csv'));

        usort($csv, fn (array $a, array $b): int => [$a['tier'] ?? PHP_INT_MAX, $b['seniority'] ?? 0.0, TeacherNameMatcher::normalize($a['name']), $a['key']]
            <=> [$b['tier'] ?? PHP_INT_MAX, $a['seniority'] ?? 0.0, TeacherNameMatcher::normalize($b['name']), $b['key']]);

        usort($appended, fn (array $a, array $b): int => [$a['hired_on'] === null ? 1 : 0, $a['hired_on'] ?? '', $a['hired_on'] === null ? TeacherNameMatcher::normalize($a['name']) : '', $a['key']]
            <=> [$b['hired_on'] === null ? 1 : 0, $b['hired_on'] ?? '', $b['hired_on'] === null ? TeacherNameMatcher::normalize($b['name']) : '', $b['key']]);

        $ranked = [];
        $slot = -1;
        $previous = null;
        $position = 0;

        foreach ([...$csv, ...$appended] as $entry) {
            $group = $entry['source'] === 'csv'
                ? ['csv', $entry['tier'], $entry['seniority']]
                : ['appended', $entry['hired_on'], $entry['hired_on'] === null ? TeacherNameMatcher::normalize($entry['name']) : null];

            if ($group !== $previous) {
                $slot++;
                $previous = $group;
            }

            $ranked[] = ['key' => $entry['key'], 'position' => ++$position, 'slot_index' => $slot];
        }

        return $ranked;
    }
}
