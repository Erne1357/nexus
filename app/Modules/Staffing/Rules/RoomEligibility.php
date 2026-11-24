<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Facilities\Enums\RoomType;

/**
 * Qué salones puede elegir un docente para un grupo al seleccionar su carga (SEL-02, SEL-03 y SEL-04). Es pura: recibe
 * los salones ya revisados (libre y bloqueo, SEL-01) y la reserva de salas, y decide.
 *
 * - **SEL-02, tipo.** Laboratorio solo en `laboratorio`; sala solo en `sala_computo`; aula o materia sin revisar, en
 *   `aula` y, solo con reserva (SEL-04), en `sala_computo`; nunca `laboratorio`. `taller` y `auditorio` nunca se
 *   ofrecen.
 * - **SEL-03, capacidad en salas y laboratorios.** Cupo ≤ capacidad ≤ cupo + 10. Si ningún salón libre cae en el
 *   rango, se ofrecen el o los libres de menor capacidad que aún alcance al cupo. En aulas basta capacidad ≥ cupo
 *   (HC-05).
 * - **SEL-04, reserva de salas.** `$saleReserveBySlot` trae, por «día|hora», las salas libres ofrecidas menos los
 *   grupos de sala pendientes a esa hora. Una materia sin requisito solo toma sala si queda al menos 1 en todas sus
 *   horas.
 */
final class RoomEligibility
{
    public const int CAPACITY_MARGIN = 10;

    /**
     * @param  list<array{id: string, code: string, type: RoomType, capacity: int, free: bool, blocked: bool}>  $rooms
     * @param  array<string, int>  $saleReserveBySlot
     * @return array{eligible: list<string>, rejected: array<string, string>}
     */
    public static function evaluate(?RequiredSpace $need, int $capacity, array $rooms, array $saleReserveBySlot): array
    {
        $need ??= RequiredSpace::Aula;
        $reserved = $saleReserveBySlot !== [] && min($saleReserveBySlot) < 1;
        $eligible = [];
        $rejected = [];
        $oversized = [];

        foreach ($rooms as $room) {
            $reason = self::typeReason($need, $room['type'], $reserved);

            if ($reason === false) {
                continue;
            }

            if ($reason !== null) {
                $rejected[$room['id']] = $reason;

                continue;
            }

            if ($room['blocked']) {
                $rejected[$room['id']] = 'Bloqueado';
            } elseif (! $room['free']) {
                $rejected[$room['id']] = 'Ocupado a esa hora';
            } elseif ($room['capacity'] < $capacity) {
                $rejected[$room['id']] = 'No le cabe el grupo';
            } elseif ($room['type'] !== RoomType::Aula && $room['capacity'] > $capacity + self::CAPACITY_MARGIN) {
                $rejected[$room['id']] = 'Le sobran más de 10 lugares';
                $oversized[] = $room;
            } else {
                $eligible[] = $room['id'];
            }
        }

        if ($eligible === [] && $oversized !== []) {
            // SEL-03: ninguno libre cae en el rango; se ofrecen los de menor capacidad que alcanza al cupo.
            $smallest = min(array_column($oversized, 'capacity'));

            foreach ($oversized as $room) {
                if ($room['capacity'] === $smallest) {
                    $eligible[] = $room['id'];
                    unset($rejected[$room['id']]);
                }
            }
        }

        return ['eligible' => $eligible, 'rejected' => $rejected];
    }

    /**
     * Nulo si el tipo sirve; el motivo si es un salón que se muestra rechazado; `false` si ni se muestra.
     */
    private static function typeReason(RequiredSpace $need, RoomType $type, bool $reserved): string|false|null
    {
        return match ($need) {
            RequiredSpace::Laboratorio => $type === RoomType::Laboratorio ? null : false,
            RequiredSpace::SalaComputo => $type === RoomType::SalaComputo ? null : false,
            RequiredSpace::Aula => match ($type) {
                RoomType::Aula => null,
                RoomType::SalaComputo => $reserved ? 'Apartada para materias de sala' : null,
                RoomType::Laboratorio => 'Solo para materias de laboratorio',
                RoomType::Taller, RoomType::Auditorio => false,
            },
        };
    }
}
