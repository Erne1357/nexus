<?php

namespace App\Modules\Staffing\Rules;

/**
 * SEL-07 y SEL-10. Orden de selección: primero los docentes con puesto administrativo (`admin`, SEL-07; decisión del
 * usuario, confirmado el 26 de noviembre de 2026) y luego los demás; dentro de cada grupo, los del CSV por escalón ascendente, criterios fallados ascendente
 * (cumple más, va antes) y antigüedad descendente (escalón, criterios y antigüedad iguales comparten turno, SEL-07); después los que no vienen en el CSV (`appended`) por fecha de
 * contratación ascendente, con los nulos al final por nombre (misma fecha comparten turno, SEL-10). Puro.
 */
final readonly class SelectionOrder
{
    /**
     * @param  list<array{key: string, source: string, tier: ?int, failed?: ?int, seniority: ?float, hired_on: ?string, name: string, admin?: bool}>  $entries
     * @return list<array{key: string, position: int, slot_index: int}>
     */
    public static function rank(array $entries): array
    {
        $ordered = [];

        foreach ([true, false] as $admin) {
            $group = array_values(array_filter($entries, fn (array $e): bool => ($e['admin'] ?? false) === $admin));
            $csv = array_values(array_filter($group, fn (array $e): bool => $e['source'] === 'csv'));
            $appended = array_values(array_filter($group, fn (array $e): bool => $e['source'] !== 'csv'));

            usort($csv, fn (array $a, array $b): int => [$a['tier'] ?? PHP_INT_MAX, $a['failed'] ?? 0, $b['seniority'] ?? 0.0, TeacherNameMatcher::normalize($a['name']), $a['key']]
                <=> [$b['tier'] ?? PHP_INT_MAX, $b['failed'] ?? 0, $a['seniority'] ?? 0.0, TeacherNameMatcher::normalize($b['name']), $b['key']]);

            usort($appended, fn (array $a, array $b): int => [$a['hired_on'] === null ? 1 : 0, $a['hired_on'] ?? '', $a['hired_on'] === null ? TeacherNameMatcher::normalize($a['name']) : '', $a['key']]
                <=> [$b['hired_on'] === null ? 1 : 0, $b['hired_on'] ?? '', $b['hired_on'] === null ? TeacherNameMatcher::normalize($b['name']) : '', $b['key']]);

            array_push($ordered, ...$csv, ...$appended);
        }

        $ranked = [];
        $slot = -1;
        $previous = null;
        $position = 0;

        foreach ($ordered as $entry) {
            $admin = $entry['admin'] ?? false;
            $group = $entry['source'] === 'csv'
                ? [$admin, 'csv', $entry['tier'], $entry['failed'] ?? 0, $entry['seniority']]
                : [$admin, 'appended', $entry['hired_on'], $entry['hired_on'] === null ? TeacherNameMatcher::normalize($entry['name']) : null];

            if ($group !== $previous) {
                $slot++;
                $previous = $group;
            }

            $ranked[] = ['key' => $entry['key'], 'position' => ++$position, 'slot_index' => $slot];
        }

        return $ranked;
    }
}
