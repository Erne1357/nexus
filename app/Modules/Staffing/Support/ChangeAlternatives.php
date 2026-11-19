<?php

namespace App\Modules\Staffing\Support;

use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\SessionCandidate;

/**
 * Cómo se leen las alternativas del motor que guarda una solicitud de cambio (`change_requests.alternatives`, CR-02):
 * la letra de cada una en la pantalla (A, B, C, en el orden en que se guardaron), la elegida (CR-03), sus sesiones
 * como candidatas del validador o de ReplaceGroupSessions, y cómo se escribe lo que le pasa a un grupo («Lu Mi
 * 7:00–9:00 · A-1 → Ma Ju 9:00–11:00 · A-2»).
 */
final class ChangeAlternatives
{
    private const array LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

    /**
     * La letra de una alternativa («A»), o null si no está entre las guardadas.
     *
     * @param  array<string, mixed>|null  $alternatives
     */
    public static function letter(?array $alternatives, string $id): ?string
    {
        foreach (array_values($alternatives['items'] ?? []) as $index => $item) {
            if ($item['id'] === $id) {
                return self::LETTERS[$index] ?? (string) ($index + 1);
            }
        }

        return null;
    }

    /**
     * La alternativa elegida (CR-03), o null.
     *
     * @param  array<string, mixed>|null  $alternatives
     * @return array<string, mixed>|null
     */
    public static function chosen(?array $alternatives): ?array
    {
        $id = $alternatives['chosen']['id'] ?? null;

        return $id === null ? null : collect($alternatives['items'] ?? [])->firstWhere('id', $id);
    }

    /**
     * Las sesiones de cada grupo de una alternativa como candidatas, por id del grupo. Con `$replacing`, cada una dice
     * qué sesión viva reemplaza (para el validador); sin él, van sueltas (para ReplaceGroupSessions).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, list<SessionCandidate>>
     */
    public static function candidates(array $item, bool $replacing = true): array
    {
        $candidates = [];

        foreach ($item['groups'] as $group) {
            $candidates[$group['group_id']] = array_map(fn (array $session): SessionCandidate => new SessionCandidate($session['day'], $session['starts_at'], $session['ends_at'],
                $session['room_id'], SessionKind::from($session['kind']), $replacing ? $session['replaces_session_id'] : null), $group['sessions']);
        }

        return $candidates;
    }

    /**
     * El horario de un grupo en una línea: los días con las mismas horas y el mismo salón van juntos («Lu Mi 7:00–9:00
     * · A-1; Vi 7:00–8:00 · A-1»).
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string, room?: ?string}>  $sessions
     */
    public static function line(array $sessions): string
    {
        $lines = array_map(fn (array $line): string => trim("{$line['days']} {$line['time']}".($line['room'] === null ? '' : " · {$line['room']}")), SessionLabels::snapshot($sessions));

        return $lines === [] ? 'Sin sesiones' : implode('; ', $lines);
    }

    /**
     * Cómo se nombra un grupo en una alternativa: «SCD-1015 · 5A» (la clave de la materia y la del grupo; el grupo trae
     * `subject`).
     */
    public static function label(Group $group): string
    {
        return $group->subject?->code === null ? $group->code : "{$group->subject->code} · {$group->code}";
    }

    /**
     * Las sesiones vivas de un grupo para line() (con la clave del salón; el grupo trae `sessions.room`).
     *
     * @return list<array{day: int, starts_at: string, ends_at: string, room: ?string}>
     */
    public static function live(Group $group): array
    {
        return $group->sessions->map(fn (GroupSession $session): array => [
            'day' => $session->day,
            'starts_at' => substr((string) $session->starts_at, 0, 5),
            'ends_at' => substr((string) $session->ends_at, 0, 5),
            'room' => $session->room?->code,
        ])->values()->all();
    }
}
