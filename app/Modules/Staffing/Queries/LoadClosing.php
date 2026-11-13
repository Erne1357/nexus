<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Queries\ClosingBlockers;
use Carbon\CarbonImmutable;

/**
 * Lo que «Cierre de la carga» y el detalle de «Avance del cierre» muestran de una carga (LoadClosingQuery; tareas 4.1 y
 * 4.2): lo que le falta para cerrar (HC-15, contado por grupo como el veto), los grupos que se justifican, el historial
 * de sus estados y cuántos grupos activos confirma el cierre.
 *
 * `blockers` es null cuando no se calcula: una carga en borrador no tiene grupos y una publicada ya pasó el cierre.
 * Lo demás va siempre.
 */
final readonly class LoadClosing
{
    /**
     * @param  list<string>  $inactiveTeachers  los docentes dados de baja que siguen en algún grupo, por nombre
     * @param  list<string>  $sharedWaitingOn  a quién esperan los grupos compartidos que propuso el departamento
     * @param  array{room_loan: int, shared_group: int}  $awaiting  los acuerdos que esperan la respuesta del departamento, por tipo
     * @param  list<array{id: string, code: string, subject_code: string, subject_name: string, career_code: string, semester: int, schedule: string, lacks_teacher: bool, roomless_sessions: int, justification: ?string, justified_by: ?string, justified_at: ?CarbonImmutable}>  $items
     * @param  list<array{from: LoadStatus, to: LoadStatus, by: string, at: CarbonImmutable, comment: ?string}>  $history  del más reciente al más antiguo
     */
    public function __construct(
        public AcademicLoad $load,
        public int $activeGroups,
        public ?ClosingBlockers $blockers,
        public int $roomlessSessions,
        public array $inactiveTeachers,
        public int $pendingRequests,
        public int $approvedRequests,
        public array $sharedWaitingOn,
        public array $awaiting,
        public array $items,
        public array $history,
    ) {}

    /**
     * Lo que falta, en el orden en que se corrige, con la pantalla donde se arregla (`screen`: conflicts, editor,
     * assign, change-requests o agreements). Sin bloqueos calculados, nada. Sin horario y sin salón solo aparecen si el
     * perfil de la modalidad de la carga usa bloques de tiempo y salones.
     *
     * @return list<array{key: string, label: string, count: int, tone: string, icon: string, detail: ?string, screen: string}>
     */
    public function conditions(): array
    {
        $blockers = $this->blockers;

        if ($blockers === null) {
            return [];
        }

        $profile = $this->load->modality->profile;
        $justifiedTeacher = $this->justifiedTeacher();
        $justifiedRoom = $this->justifiedRoom();
        $roomDetail = array_filter([
            $this->roomlessSessions > 0 ? self::plural($this->roomlessSessions, 'sesión presencial', 'sesiones presenciales') : null,
            $justifiedRoom > 0 ? self::plural($justifiedRoom, 'justificado', 'justificados') : null,
        ]);
        $requestsDetail = array_filter([
            $this->pendingRequests > 0 ? "{$this->pendingRequests} por decidir" : null,
            $this->approvedRequests > 0 ? self::plural($this->approvedRequests, 'aprobada sin aplicar', 'aprobadas sin aplicar') : null,
        ]);
        $awaitingDetail = array_filter([
            $this->awaiting['room_loan'] > 0 ? self::plural($this->awaiting['room_loan'], 'préstamo de salón', 'préstamos de salón') : null,
            $this->awaiting['shared_group'] > 0 ? self::plural($this->awaiting['shared_group'], 'grupo compartido', 'grupos compartidos') : null,
        ]);

        return array_values(array_filter([
            self::row('conflicted', 'Grupos con choque duro', $blockers->conflicted, 'error', 'o-x-circle', null, 'conflicts'),
            $profile->usesTimeBlocks ? self::row('unscheduled', 'Grupos sin horario', $blockers->unscheduled, 'warning', 'o-clock', null, 'editor') : null,
            self::row('teacher', 'Grupos sin docente', $blockers->withoutTeacher, 'warning', 'o-user', $justifiedTeacher > 0 ? self::plural($justifiedTeacher, 'justificado', 'justificados') : null, 'assign'),
            $profile->usesRooms ? self::row('room', 'Grupos con sesiones sin salón', $blockers->withoutRoom, 'warning', 'o-map-pin', $roomDetail === [] ? null : implode(' · ', $roomDetail), 'editor') : null,
            self::row('inactive', 'Grupos con docente dado de baja', $blockers->inactiveTeacher, 'warning', 'o-user-minus', $this->inactiveTeachers === [] ? null : implode(', ', $this->inactiveTeachers), 'assign'),
            self::row('requests', 'Solicitudes de cambio pendientes', $this->pendingRequests + $this->approvedRequests, 'warning', 'o-arrow-path-rounded-square', $requestsDetail === [] ? null : implode(' · ', $requestsDetail), 'change-requests'),
            self::row('shared', 'Grupos compartidos propuestos sin respuesta', count($this->sharedWaitingOn), 'warning', 'o-arrows-right-left', $this->sharedWaitingOn === [] ? null : (count($this->sharedWaitingOn) === 1 ? 'Espera' : 'Esperan').' a '.implode(', ', array_unique($this->sharedWaitingOn)), 'agreements'),
            self::row('awaiting', 'Acuerdos que esperan la respuesta del departamento', array_sum($this->awaiting), 'warning', 'o-inbox-arrow-down', $awaitingDetail === [] ? null : implode(' · ', $awaitingDetail), 'agreements'),
        ]));
    }

    /**
     * Los grupos distintos que faltan para cerrar (DESIGN.md: las cifras cuentan grupos): uno sin docente ni salón cuenta
     * en dos condiciones y aquí una vez.
     */
    public function missingGroups(): int
    {
        return $this->blockers?->groups ?? 0;
    }

    /**
     * Los trámites que faltan, aparte de los grupos: solicitudes de cambio por decidir o sin aplicar y acuerdos (los
     * compartidos que propuso sin respuesta y los que esperan su respuesta). Sin bloqueos calculados, ninguno.
     */
    public function procedures(): int
    {
        return $this->blockers === null ? 0 : $this->pendingRequests + $this->approvedRequests + count($this->sharedWaitingOn) + array_sum($this->awaiting);
    }

    /**
     * Si falta algo: grupos o trámites.
     */
    public function missing(): int
    {
        return $this->missingGroups() + $this->procedures();
    }

    /**
     * «Faltan 255 grupos y 3 trámites».
     */
    public function missingLabel(): string
    {
        return self::missingPhrase($this->missingGroups(), $this->procedures());
    }

    /**
     * Lista para cerrar: en asignación, con grupos y sin nada que falte (sin grupos, headline() lo dice).
     */
    public function isReady(): bool
    {
        return $this->load->status === LoadStatus::Staffing && $this->activeGroups > 0 && $this->blockers !== null && $this->missing() === 0;
    }

    /**
     * Si alguna cifra que falta es un choque duro (el tono de la insignia «Faltan N»).
     */
    public function hasHardConflicts(): bool
    {
        return ($this->blockers?->conflicted ?? 0) > 0;
    }

    /**
     * Los grupos sin docente que pasan con justificación.
     */
    public function justifiedTeacher(): int
    {
        return count(array_filter($this->items, fn (array $item): bool => $item['justification'] !== null && $item['lacks_teacher']));
    }

    /**
     * Los grupos con docente y sesiones sin salón que pasan con justificación (uno sin docente ni salón cuenta en
     * justifiedTeacher()).
     */
    public function justifiedRoom(): int
    {
        return count(array_filter($this->items, fn (array $item): bool => $item['justification'] !== null && ! $item['lacks_teacher'] && $item['roomless_sessions'] > 0));
    }

    public function justifiedCount(): int
    {
        return count(array_filter($this->items, fn (array $item): bool => $item['justification'] !== null));
    }

    public function pendingJustifications(): int
    {
        return count($this->items) - $this->justifiedCount();
    }

    /**
     * La última vez que la carga llegó a `$status`, o null.
     *
     * @return array{from: LoadStatus, to: LoadStatus, by: string, at: CarbonImmutable, comment: ?string}|null
     */
    public function lastReached(LoadStatus $status): ?array
    {
        foreach ($this->history as $entry) {
            if ($entry['to'] === $status) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Lo que dice la línea del estado: su tono y su icono (el color nunca va solo), el título («Faltan 21 para cerrar»,
     * «Lista para cerrar», «Sin grupos», «Cerrada el 07/12/2026») y una línea de detalle.
     *
     * @return array{tone: string, icon: string, title: string, detail: string}
     */
    public function headline(): array
    {
        $load = $this->load;
        $missing = $this->missing();
        $reached = $this->lastReached($load->status);
        $on = $reached === null ? '' : ' el '.$reached['at']->format('d/m/Y');
        $by = $reached === null ? '' : "Por {$reached['by']} · ";

        return match ($load->status) {
            LoadStatus::Draft => ['tone' => 'neutral', 'icon' => 'o-pencil', 'title' => 'En borrador', 'detail' => 'La carga no tiene grupos: nacen al aceptar la parte del departamento en una propuesta de carrera.'],
            LoadStatus::Proposed => ['tone' => 'info', 'icon' => 'o-document-text', 'title' => $missing > 0 ? 'En Propuesta · '.mb_strtolower($this->missingLabel()) : 'En Propuesta', 'detail' => $missing > 0 ? $this->shortList() : 'Para cerrarla, primero pásala a asignación docente.'],
            LoadStatus::Staffing => match (true) {
                $missing > 0 => ['tone' => $this->hasHardConflicts() ? 'error' : 'warning', 'icon' => $this->hasHardConflicts() ? 'o-x-circle' : 'o-exclamation-triangle', 'title' => $this->missingLabel().' para cerrar', 'detail' => $this->shortList()],
                $this->activeGroups === 0 => ['tone' => 'neutral', 'icon' => 'o-minus-circle', 'title' => 'Sin grupos', 'detail' => 'La carga no tiene grupos activos: no hay nada que cerrar.'],
                default => ['tone' => 'success', 'icon' => 'o-check-circle', 'title' => 'Lista para cerrar', 'detail' => self::plural($this->activeGroups, 'grupo activo', 'grupos activos').' · '.self::plural($this->justifiedCount(), 'justificado', 'justificados')],
            },
            LoadStatus::Adjusting => [
                'tone' => 'info',
                'icon' => 'o-arrow-path-rounded-square',
                'title' => $reached !== null && in_array($reached['from'], [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published], true) ? "Reabierta{$on}" : 'En Ajustes',
                'detail' => $missing > 0 ? $this->missingLabel().": {$this->shortList()}" : 'Para cerrarla otra vez, vuelve a asignación.',
            ],
            LoadStatus::Closed => ['tone' => 'success', 'icon' => 'o-lock-closed', 'title' => "Cerrada{$on}", 'detail' => $by.($load->modality->profile->requiresSubdireccionApproval ? 'espera el visto bueno de Subdirección' : 'falta publicarla')],
            LoadStatus::Approved => ['tone' => 'success', 'icon' => 'o-check-badge', 'title' => "Con visto bueno{$on}", 'detail' => "{$by}falta publicarla"],
            LoadStatus::Published => ['tone' => 'success', 'icon' => 'o-check-circle', 'title' => self::statusLabel($load).$on, 'detail' => $reached === null ? 'Ya se consulta y se exporta.' : "Por {$reached['by']}"],
            LoadStatus::Reanalysis => ['tone' => 'warning', 'icon' => 'o-arrow-path', 'title' => 'En re-análisis', 'detail' => 'Al terminar se publica otra versión.'],
        };
    }

    /**
     * Lo que falta en una línea: «3 choques duros · 7 sin docente · 2 solicitudes».
     */
    public function shortList(): string
    {
        $short = [
            'conflicted' => ['choque duro', 'choques duros'],
            'unscheduled' => ['sin horario', 'sin horario'],
            'teacher' => ['sin docente', 'sin docente'],
            'room' => ['sin salón', 'sin salón'],
            'inactive' => ['con docente de baja', 'con docente de baja'],
            'requests' => ['solicitud', 'solicitudes'],
            'shared' => ['compartido sin respuesta', 'compartidos sin respuesta'],
            'awaiting' => ['acuerdo por responder', 'acuerdos por responder'],
        ];

        return collect($this->conditions())
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->map(fn (array $row): string => self::plural($row['count'], ...$short[$row['key']]))
            ->implode(' · ');
    }

    /**
     * La línea de estados: Borrador, Propuesta, Asignación, Ajustes, Cerrada, Aprobada y Publicada, cada uno con la fecha
     * en que la carga llegó a él por última vez (`dd/mm`) y si ya pasó, es el actual, se visitó después (Ajustes, que es
     * una vuelta), se saltó (Ajustes, si la carga nunca pasó por ahí) o sigue. Aprobada va si la modalidad exige el visto
     * bueno o si la carga está o estuvo ahí (el perfil pudo cambiar), así que todo estado tiene su paso; re-análisis es
     * el de Publicada.
     *
     * @return list<array{status: LoadStatus, label: string, state: 'past'|'current'|'seen'|'skipped'|'next', date: ?string}>
     */
    public function steps(): array
    {
        $order = [LoadStatus::Draft, LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting, LoadStatus::Closed];

        if ($this->load->modality->profile->requiresSubdireccionApproval || $this->load->status === LoadStatus::Approved || $this->lastReached(LoadStatus::Approved) !== null) {
            $order[] = LoadStatus::Approved;
        }

        $order[] = LoadStatus::Published;
        $current = array_search($this->load->status === LoadStatus::Reanalysis ? LoadStatus::Published : $this->load->status, $order, true);

        return array_map(function (LoadStatus $status, int $index) use ($current): array {
            $reached = $this->lastReached($status);

            return [
                'status' => $status,
                'label' => $status === $this->load->status ? self::statusLabel($this->load) : $status->label(),
                'state' => match (true) {
                    $index === $current => 'current',
                    $index < $current && $status === LoadStatus::Adjusting && $reached === null => 'skipped',
                    $index < $current => 'past',
                    $reached !== null => 'seen',
                    default => 'next',
                },
                'date' => $reached === null || $status === LoadStatus::Draft ? null : $reached['at']->format('d/m'),
            ];
        }, $order, array_keys($order));
    }

    /**
     * El título de un paso del historial: «Cerrada», «Reabierta», «Volvió a Asignación», «Pasó a Ajustes»…
     *
     * @param  array{from: LoadStatus, to: LoadStatus, by: string, at: CarbonImmutable, comment: ?string}  $entry
     */
    public static function stepTitle(array $entry): string
    {
        return match (true) {
            $entry['to'] === LoadStatus::Closed => 'Cerrada',
            $entry['to'] === LoadStatus::Approved => 'Visto bueno de Subdirección',
            $entry['to'] === LoadStatus::Published => 'Publicada',
            $entry['to'] === LoadStatus::Adjusting && in_array($entry['from'], [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published], true) => 'Reabierta',
            $entry['to'] === LoadStatus::Staffing && $entry['from'] === LoadStatus::Adjusting => 'Volvió a Asignación',
            default => "Pasó a {$entry['to']->label()}",
        };
    }

    /**
     * El tono del punto de un paso del historial: success al cerrar, aprobar o publicar; warning al reabrir; neutral lo
     * demás.
     *
     * @param  array{from: LoadStatus, to: LoadStatus, by: string, at: CarbonImmutable, comment: ?string}  $entry
     */
    public static function stepTone(array $entry): string
    {
        return match (true) {
            in_array($entry['to'], [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published], true) => 'success',
            $entry['to'] === LoadStatus::Adjusting && in_array($entry['from'], [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published], true) => 'warning',
            default => 'neutral',
        };
    }

    /**
     * Lo que falta en grupos y en trámites: «Falta 1 grupo», «Faltan 3 trámites», «Faltan 255 grupos y 3 trámites».
     */
    public static function missingPhrase(int $groups, int $procedures): string
    {
        $parts = array_values(array_filter([
            $groups > 0 ? self::plural($groups, 'grupo', 'grupos') : null,
            $procedures > 0 ? self::plural($procedures, 'trámite', 'trámites') : null,
        ]));

        return ($groups + $procedures === 1 ? 'Falta ' : 'Faltan ').implode(' y ', $parts);
    }

    /**
     * El estado con su versión publicada: «Publicada · v2».
     */
    public static function statusLabel(AcademicLoad $load): string
    {
        return $load->status === LoadStatus::Published && $load->current_version > 0
            ? "{$load->status->label()} · v{$load->current_version}"
            : $load->status->label();
    }

    /**
     * El icono de la insignia del estado, el mismo de la línea del estado y de la insignia de «Avance del cierre», para
     * que un estado no cambie de icono entre las dos pantallas (el del tono, `o-eye`, es el de «En revisión»).
     */
    public static function statusIcon(AcademicLoad $load): string
    {
        return match ($load->status) {
            LoadStatus::Draft => 'o-pencil',
            LoadStatus::Proposed => 'o-document-text',
            LoadStatus::Staffing => 'o-user-plus',
            LoadStatus::Adjusting => 'o-arrow-path-rounded-square',
            LoadStatus::Closed => 'o-lock-closed',
            LoadStatus::Approved => 'o-check-badge',
            LoadStatus::Published => 'o-check-circle',
            LoadStatus::Reanalysis => 'o-arrow-path',
        };
    }

    /**
     * @return array{key: string, label: string, count: int, tone: string, icon: string, detail: ?string, screen: string}
     */
    private static function row(string $key, string $label, int $count, string $tone, string $icon, ?string $detail, string $screen): array
    {
        return compact('key', 'label', 'count', 'tone', 'icon', 'detail', 'screen');
    }

    private static function plural(int $count, string $one, string $many): string
    {
        return $count.' '.($count === 1 ? $one : $many);
    }
}
