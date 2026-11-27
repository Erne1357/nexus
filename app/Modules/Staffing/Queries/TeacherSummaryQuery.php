<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\TermQuery;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\TeacherOwnSummaryQuery;
use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Support\SessionLabels;
use Carbon\CarbonImmutable;

/**
 * El Resumen del docente (`/resumen`, tarea 1.85, IN-03; maqueta C «Tablero»): lo que le toca hoy, cómo va su carga y lo
 * que necesita su atención, todo de solo lectura y solo de la ficha ligada al usuario de la sesión (nunca un id del
 * navegador). Compone lo que ya existe y no repite sus reglas: los periodos son los de Mi carga (MyLoadTerms; el
 * periodo en planeación se muestra solo si la carga no está oculta para él, MN-05), las horas, el tope, la banda, los
 * días y los grupos son los de MyLoadQuery del mismo periodo (el Resumen nunca dice algo distinto de Mi carga, también
 * con 1.83: sin plaza docente), el turno de selección es el de MySelectionTurnQuery, las solicitudes las de
 * MyChangeRequestsQuery y la ficha, la experiencia y la disponibilidad las de TeacherOwnSummaryQuery.
 *
 * Supuestos por confirmar (IN-03): el orden de «Atención» (turno, horas sobre la banda sin resolver, solicitudes,
 * materias fuera de su experiencia; a lo más 5); una solicitud decidida se avisa 7 días desde `decided_at` o
 * `applied_at`; la experiencia se compara por clave de materia (la carga del SII no trae el id); la hora es la de
 * `$now` (se calcula al cargar, no en vivo). Un número constante de consultas, sin importar cuántos grupos tenga.
 */
final class TeacherSummaryQuery
{
    private const string TIMEZONE = 'America/Ciudad_Juarez';

    /** Cuántas materias de experiencia lleva la caja antes de «y N más». */
    private const int EXPERIENCE_SHOWN = 6;

    /** A lo más estos renglones en «Atención». */
    private const int ATTENTION_MAX = 5;

    /** Días que se avisa una solicitud ya decidida (supuesto por confirmar). */
    private const int REQUEST_NOTICE_DAYS = 7;

    /** Solicitudes que lleva la caja del periodo en planeación. */
    private const int REQUESTS_SHOWN = 5;

    public function __construct(
        private readonly MyLoadTerms $terms,
        private readonly MyLoadQuery $loads,
        private readonly MyChangeRequestsQuery $requests,
        private readonly MySelectionTurnQuery $turns,
        private readonly TeacherOwnSummaryQuery $own,
        private readonly TermQuery $termQuery,
        private readonly AcademicLoadVisibility $visibility,
    ) {}

    /**
     * Null si el usuario no tiene una ficha de docente activa ligada (como Mi carga).
     */
    public function for(Actor $context, CarbonImmutable $now): ?TeacherSummary
    {
        $user = $context->user();
        $teacher = $user === null ? null : Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();

        if ($user === null || $teacher === null) {
            return null;
        }

        $now = $now->setTimezone(self::TIMEZONE);
        $inProgress = $this->terms->inProgress($context);
        $planningTerm = $this->terms->planning($context);
        $planningOpen = $planningTerm !== null && ! $this->visibility->hiddenFrom($context);
        $same = $planningTerm !== null && $inProgress?->id === $planningTerm->id;

        $inLoad = $inProgress === null ? null : $this->loads->forTeacher($teacher, $inProgress);
        $planLoad = ! $planningOpen ? null : ($same ? $inLoad : $this->loads->forTeacher($teacher, $planningTerm));
        $planRequests = $planLoad === null ? null : $this->requests->forTeacher($teacher, (string) $user->getKey(), $planningTerm)['requests'];
        $turns = $planLoad === null ? [] : $this->turns->forTeacher($teacher, $planningTerm, $now);
        $experience = $this->own->experience($teacher);

        $during = $inProgress !== null && $now->toDateString() >= $inProgress->starts_on->toDateString() && $now->toDateString() <= $inProgress->ends_on->toDateString();
        $today = $this->today($now, $inProgress, $inLoad, $during, $planningTerm);

        return new TeacherSummary(
            identity: $this->own->identity($teacher),
            account: ['username' => (string) $user->username, 'email' => $teacher->institutional_email],
            terms: [
                'in_progress' => $inProgress === null ? null : ['id' => $inProgress->id, 'name' => $inProgress->name],
                'planning' => $planningTerm === null ? null : ['id' => $planningTerm->id, 'name' => $planningTerm->name],
            ],
            today: $today,
            tiles: $this->tiles($today, $inProgress, $inLoad, $planningTerm, $planLoad, $same),
            attention: $this->attention($now, $planningTerm, $planLoad, $planRequests ?? [], $turns, $experience),
            week: $this->week($now, $inProgress, $inLoad, $today['state'] !== 'between_terms', $planningTerm, $planLoad),
            planning: $planLoad === null || $same ? null : $this->planning($planningTerm, $planLoad, $planRequests ?? [], $turns),
            experience: [
                'total' => $experience['total'],
                'subjects' => array_slice($experience['subjects'], 0, self::EXPERIENCE_SHOWN),
                'rest' => array_slice($experience['subjects'], self::EXPERIENCE_SHOWN),
                'more' => max(0, $experience['total'] - self::EXPERIENCE_SHOWN),
            ],
            availability: $planningTerm === null ? null : $this->availability($teacher, $planningTerm),
        );
    }

    /**
     * «Hoy»: las clases del día del periodo en curso con su estado frente a la hora de ahora (listas, en curso, la
     * siguiente), la próxima clase si hoy no hay, o, fuera de las fechas del periodo (entre semestres), el que terminó y
     * el que empieza.
     *
     * @param  ?array<string, mixed>  $load
     * @return array<string, mixed>
     */
    private function today(CarbonImmutable $now, ?Term $inProgress, ?array $load, bool $during, ?Term $planningTerm): array
    {
        $base = ['date' => $now->startOfDay(), 'date_label' => $now->locale('es')->isoFormat('dddd D [de] MMMM'), 'items' => [], 'next' => null, 'ended' => null, 'upcoming' => null];

        if (! $during || $load === null) {
            $ended = $inProgress !== null && $inProgress->ends_on->toDateString() < $now->toDateString() ? $inProgress : $this->termQuery->lastEnded($now);
            $upcoming = $planningTerm !== null && $planningTerm->starts_on->toDateString() > $now->toDateString()
                ? $planningTerm
                : Term::query()->whereDate('starts_on', '>', $now)->orderBy('starts_on')->first();

            return [...$base, 'state' => 'between_terms',
                'ended' => $ended === null ? null : ['term' => $ended->name, 'ended_on' => CarbonImmutable::instance($ended->ends_on)],
                'upcoming' => $upcoming === null ? null : ['term' => $upcoming->name, 'starts_on' => CarbonImmutable::instance($upcoming->starts_on)],
            ];
        }

        $minute = $now->hour * 60 + $now->minute;
        $blocks = $load['days'][$now->isoWeekday()]['blocks'] ?? [];
        $upNext = false;
        $items = [];

        foreach ($blocks as $block) {
            $status = $block['end'] <= $minute ? 'done' : ($block['start'] <= $minute ? 'now' : ($upNext ? 'later' : 'next'));
            $upNext = $upNext || $status === 'next';
            $items[] = [
                'time' => $block['time'],
                'subject' => $block['subject_name'],
                'code' => $block['subject_code'],
                'group' => $block['group_code'],
                'room' => $block['room'],
                'campus' => $block['campus_code'],
                'status' => $status,
                'starts_in' => $status === 'next' ? 'en '.self::span($block['start'] - $minute) : null,
                'gap' => $block['gap'],
                'gap_label' => $block['gap'] === null ? null : 'Hueco de '.self::span($block['gap']),
                'transfer' => $block['transfer'],
            ];
        }

        if ($items !== []) {
            return [...$base, 'state' => 'classes', 'items' => $items];
        }

        return [...$base, 'state' => 'none', 'next' => $this->nextClass($now, $inProgress, $load)];
    }

    /**
     * La próxima clase después de hoy, dentro del periodo, en la semana que sigue: «Mañana» o «El lunes».
     *
     * @param  array<string, mixed>  $load
     * @return ?array{day_label: string, time: string, subject: string}
     */
    private function nextClass(CarbonImmutable $now, Term $term, array $load): ?array
    {
        for ($ahead = 1; $ahead <= 7; $ahead++) {
            $date = $now->addDays($ahead);

            if ($date->toDateString() > $term->ends_on->toDateString()) {
                return null;
            }

            $first = $load['days'][$date->isoWeekday()]['blocks'][0] ?? null;

            if ($first !== null) {
                return [
                    'day_label' => $ahead === 1 ? 'Mañana' : 'El '.mb_strtolower(SessionLabels::DAY_NAMES[$date->isoWeekday()]),
                    'time' => self::clock($first['start']),
                    'subject' => $first['subject_name'],
                ];
            }
        }

        return null;
    }

    /**
     * Las cuatro cifras. Con el mismo periodo en curso y en planeación solo sale la de planeación (no se repite).
     *
     * @param  array<string, mixed>  $today
     * @param  ?array<string, mixed>  $inLoad
     * @param  ?array<string, mixed>  $planLoad
     * @return array<string, mixed>
     */
    private function tiles(array $today, ?Term $inProgress, ?array $inLoad, ?Term $planningTerm, ?array $planLoad, bool $same): array
    {
        $current = collect($today['items'])->firstWhere('status', 'now');
        $upcoming = collect($today['items'])->firstWhere('status', 'next');
        $h = $planLoad['hours'] ?? null;

        return [
            'today_count' => count($today['items']),
            'today_caption' => match (true) {
                $today['state'] === 'between_terms' => 'sin clases',
                $today['state'] === 'none' => 'sin clases hoy',
                $current !== null => 'ahora: '.$current['subject'].' hasta las '.self::endOf($current['time']),
                $upcoming !== null => 'la siguiente a las '.self::startOf($upcoming['time']),
                default => count($today['items']) === 1 ? 'ya terminó' : 'ya terminaron',
            },
            'in_progress' => $inProgress === null || $inLoad === null || ($same && $planLoad !== null) ? null : [
                'term_name' => $inProgress->name,
                'hours' => $inLoad['hours']['assigned'],
                'groups' => count($inLoad['groups']),
            ],
            'planning' => $h === null ? null : [
                'term_name' => $planningTerm->name,
                'assigned' => $h['assigned'],
                'external' => $h['external'],
                'limit' => $h['limit'],
                'limit_label' => self::limitLabel($h),
                'band_text' => self::bandText($h),
                'band_min' => $h['band_from'],
                'band_max' => $h['band_to'],
                'over_band' => $h['over_band'],
                'accepted' => $h['accepted'],
                'no_plaza' => $h['no_plaza'],
            ],
            'remaining' => $h === null ? null : [
                'hours' => $h['remaining'],
                'over' => $h['remaining'] < 0 && ! $h['no_plaza'],
                'caption' => match (true) {
                    $h['no_plaza'] => TeacherHourLimit::NO_PLAZA_LABEL,
                    $h['remaining'] < 0 => $h['administrative'] !== null ? 'Pasas del tope de tu plaza' : 'Pasas de tu banda',
                    $h['remaining'] === 0 => 'Llegas a tu tope',
                    default => 'Para llegar a tu tope',
                },
            ],
        ];
    }

    /**
     * «Atención» en el orden del diseño y con tope: turno de selección, horas sobre la banda sin resolver, solicitudes
     * abiertas o decididas hace poco y materias de su carga fuera de su experiencia.
     *
     * @param  ?array<string, mixed>  $planLoad
     * @param  list<array<string, mixed>>  $requests
     * @param  list<array<string, mixed>>  $turns
     * @param  array{total: int, subjects: list<array{subject_id: string, code: string, name: string, level: string}>}  $experience
     * @return list<array<string, mixed>>
     */
    private function attention(CarbonImmutable $now, ?Term $planningTerm, ?array $planLoad, array $requests, array $turns, array $experience): array
    {
        if ($planLoad === null) {
            return [];
        }

        $rows = [];
        $offer = route('staffing.offer');
        $myLoad = route('staffing.my-load', $planLoad['source'] === 'nexus' && $planLoad['groups'] !== [] ? ['periodo' => $planningTerm->id] : []);

        foreach ($turns as $turn) {
            $start = $turn['starts_at']?->setTimezone(self::TIMEZONE);
            $end = $turn['ends_at']?->setTimezone(self::TIMEZONE);
            $place = 'Lugar '.$turn['position'].' de '.$turn['total'].' · '.$turn['department'];
            $slot = $start === null || $end === null ? null : $start->locale('es')->isoFormat('dddd D [de] MMMM').', '.$start->format('G:i').'–'.$end->format('G:i');

            $rows[] = [
                'kind' => 'selection_turn',
                'tone' => 'info',
                'icon' => 'o-clock',
                'title' => $turn['in_turn'] && $end !== null ? 'Es tu turno, hasta las '.$end->format('G:i') : ($slot === null ? 'Tu turno de selección' : 'Tu turno: '.$slot),
                'detail' => $place,
                'url' => $offer,
            ];
        }

        $h = $planLoad['hours'];

        if ($h['over_band'] && ! $h['accepted']) {
            $rows[] = [
                'kind' => 'hours',
                'tone' => 'warning',
                'icon' => 'o-document-text',
                'title' => 'Pasas de tu banda de horas',
                'detail' => $h['administrative'] !== null
                    ? "Pasas del tope de {$h['administrative_cap']} h de tu plaza administrativa: Nexus no deja asignarte más clases."
                    : 'Te pasas de tus horas de nombramiento: '.($h['extra_label'] ?? $h['extra'].' h extra · honorarios por autorizar (jefe de depto)').'.',
                'url' => $myLoad,
            ];
        }

        $since = $now->subDays(self::REQUEST_NOTICE_DAYS);

        foreach ($requests as $request) {
            $closedAt = $request['applied_at'] ?? $request['decided_at'];

            if ($request['status']->isOpen() || ($closedAt !== null && $closedAt >= $since)) {
                $rows[] = [
                    'kind' => 'request',
                    'tone' => $request['status'] === ChangeRequestStatus::Rejected ? 'warning' : 'info',
                    'icon' => $request['status']->icon(),
                    'title' => 'Solicitud del grupo '.$request['group_code'].': '.mb_strtolower($request['status']->label()),
                    'detail' => $request['status'] === ChangeRequestStatus::Rejected && ($request['comment'] ?? '') !== '' ? $request['comment'] : $request['subject_name'],
                    'url' => $myLoad,
                ];
            }
        }

        $known = array_column($experience['subjects'], 'code');
        $outside = collect($planLoad['groups'])
            ->reject(fn (array $group): bool => in_array($group['subject_code'], $known, true))
            ->unique('subject_code')
            ->pluck('subject_name');

        if ($outside->isNotEmpty()) {
            $rows[] = [
                'kind' => 'experience',
                'tone' => 'info',
                'icon' => 'o-academic-cap',
                'title' => $outside->count() === 1 ? 'Una materia de tu carga no está en tu experiencia académica' : $outside->count().' materias de tu carga no están en tu experiencia académica',
                'detail' => $outside->take(3)->implode(', ').($outside->count() > 3 ? ' y '.($outside->count() - 3).' más' : '').'. Es informativo: no bloquea tu carga.',
                'url' => null,
            ];
        }

        return array_slice($rows, 0, self::ATTENTION_MAX);
    }

    /**
     * «Tu semana»: los días del periodo en curso (la de hoy marcada) o, entre semestres, los del periodo en planeación,
     * provisional mientras no esté publicado.
     *
     * @param  ?array<string, mixed>  $inLoad
     * @param  ?array<string, mixed>  $planLoad
     * @return ?array<string, mixed>
     */
    private function week(CarbonImmutable $now, ?Term $inProgress, ?array $inLoad, bool $during, ?Term $planningTerm, ?array $planLoad): ?array
    {
        if ($during && $inLoad !== null && $inLoad['groups'] !== []) {
            $term = $inProgress;
            $load = $inLoad;
            $title = 'Tu semana';
            $provisional = false;
            $footer = ' · '.$term->name.' termina el '.$term->ends_on->locale('es')->isoFormat('D [de] MMMM');
            $today = $now->isoWeekday();
        } elseif (! $during && $planLoad !== null && $planLoad['groups'] !== []) {
            $term = $planningTerm;
            $load = $planLoad;
            $title = 'Tu semana desde el '.$term->starts_on->locale('es')->isoFormat('D [de] MMMM');
            $provisional = $planLoad['published'] === [];
            $footer = ' · '.$term->name;
            $today = 0;
        } else {
            return null;
        }

        $groups = count($load['groups']);

        return [
            'term_name' => $term->name,
            'title' => $title,
            'provisional' => $provisional,
            'days' => array_values(array_map(fn (int $number, array $day): array => [
                'day' => $number,
                'name' => $day['name'],
                'short' => $day['short'],
                'hours' => $day['hours'],
                'today' => $number === $today,
                'blocks' => array_map(fn (array $block): array => [
                    'time' => $block['time'],
                    'subject' => $block['subject_name'],
                    'code' => $block['subject_code'],
                    'group' => $block['group_code'],
                    'room' => $block['room'],
                    'campus' => $block['campus_code'],
                ], $day['blocks']),
            ], array_keys($load['days']), $load['days'])),
            'footer' => $load['hours']['assigned'].' h en '.$groups.($groups === 1 ? ' grupo' : ' grupos').$footer,
        ];
    }

    /**
     * La caja del periodo en planeación: insignia (la selección viva gana, luego lo publicado, luego «en preparación»),
     * sellos P8, sus grupos con salones, la ecuación de horas de Mi carga y sus solicitudes del periodo.
     *
     * @param  array<string, mixed>  $load
     * @param  list<array<string, mixed>>  $requests
     * @param  list<array<string, mixed>>  $turns
     * @return array<string, mixed>
     */
    private function planning(Term $term, array $load, array $requests, array $turns): array
    {
        $rooms = [];
        $missing = [];

        foreach ($load['days'] as $day) {
            foreach ($day['blocks'] as $block) {
                if ($block['room'] === null) {
                    $missing[$block['group_id']] = true;
                } else {
                    $rooms[$block['group_id']][$block['room']] = true;
                }
            }
        }

        $offered = $load['source'] === 'nexus' && $load['groups'] !== [];

        return [
            'term_id' => $term->id,
            'term_name' => $term->name,
            'badge' => $turns !== [] ? 'selection' : ($load['published'] !== [] ? 'published' : 'preparing'),
            'published' => $load['published'],
            'offered' => $offered,
            'groups' => array_map(fn (array $group): array => [
                'id' => $group['id'],
                'code' => $group['code'],
                'subject' => $group['subject_name'],
                'subject_code' => $group['subject_code'],
                'campus' => $group['campus_code'],
                'rooms' => array_keys($rooms[$group['id']] ?? []),
                'no_room' => isset($missing[$group['id']]),
                'schedule' => $group['schedule'],
                'hours' => $group['hours'],
                'counts_teacher_hours' => $group['counts_teacher_hours'],
            ], $load['groups']),
            'hours' => $load['hours'],
            'requests' => array_map(fn (array $request): array => [
                'group_code' => $request['group_code'],
                'subject' => $request['subject_name'],
                'status' => $request['status']->value,
                'status_label' => $request['status']->label(),
                'tone' => $request['status']->tone()->value,
                'icon' => $request['status']->icon(),
                'comment' => $request['comment'],
                'requested_at' => $request['requested_at'],
            ], array_slice($requests, 0, self::REQUESTS_SHOWN)),
            'requests_more' => max(0, count($requests) - self::REQUESTS_SHOWN),
            'my_load_url' => route('staffing.my-load', $offered ? ['periodo' => $term->id] : []),
        ];
    }

    /**
     * Las horas disponibles de la cuadrícula y las excepciones por día. Sin excepciones es «Disponible en todo el
     * horario»: en Nexus todo cuenta como disponible y lo guardado son excepciones (S12).
     *
     * @return array<string, mixed>
     */
    private function availability(Teacher $teacher, Term $term): array
    {
        $box = $this->own->availabilityBox($teacher, $term);

        return [
            'term_name' => $term->name,
            'available' => $box['available'],
            'total' => $box['total'],
            'all_available' => $box['days'] === [],
            'days' => $box['days'],
        ];
    }

    /**
     * El título de la cifra del tope, como el de Mi carga.
     *
     * @param  array<string, mixed>  $h
     */
    private static function limitLabel(array $h): string
    {
        return match (true) {
            $h['no_plaza'] => 'Sin plaza docente',
            $h['administrative'] !== null => 'Tope con tu plaza administrativa',
            $h['honorarios_cap'] !== null => 'Tope de honorarios',
            $h['limit'] > $h['band_limit'] => 'Autorizadas',
            default => 'Tope de tu banda',
        };
    }

    /**
     * La línea bajo el tope, como la de Mi carga; null si no lleva (honorarios).
     *
     * @param  array<string, mixed>  $h
     */
    private static function bandText(array $h): ?string
    {
        return match (true) {
            $h['no_plaza'] => TeacherHourLimit::NO_PLAZA_LABEL,
            $h['administrative'] !== null => "{$h['administrative_cap']} h − plaza administrativa {$h['administrative']} h",
            $h['honorarios_cap'] !== null => null,
            default => "frente a grupo {$h['teaching']} h ({$h['band_from']} a {$h['band_to']})".($h['tolerance'] > 0 ? " + {$h['tolerance']} de tolerancia" : '').($h['limit'] > $h['band_limit'] ? ' · con tu casilla' : ''),
        };
    }

    /**
     * «2 h», «1 h 30 min» o «0 min».
     */
    private static function span(int $minutes): string
    {
        $minutes = max($minutes, 0);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $minutes === 0 ? '0 min' : trim(($hours > 0 ? $hours.' h' : '').($rest > 0 ? ' '.$rest.' min' : ''));
    }

    /** «9:00» de unos minutos desde las 0:00. */
    private static function clock(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** El inicio de «9:00–11:00». */
    private static function startOf(string $span): string
    {
        return explode('–', $span)[0];
    }

    /** El fin de «9:00–11:00». */
    private static function endOf(string $span): string
    {
        return explode('–', $span)[1] ?? $span;
    }
}
