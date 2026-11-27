<?php

namespace App\Modules\Staffing\Queries;

use Carbon\CarbonImmutable;

/**
 * Lo que pinta el Resumen del docente (tarea 1.85, IN-03; TeacherSummaryQuery): el tablero de solo lectura de un docente.
 * Cada propiedad es un arreglo con forma fija; las formas están en los `@param`. Las horas, el tope, la banda y los
 * grupos de los periodos son los de Mi carga (MyLoadQuery), tal cual.
 */
final readonly class TeacherSummary
{
    /**
     * @param  array{name: string, active: bool, department: ?array{code: string, name: string}, other_departments: int, positions: list<array{kind: string, base_hours: int}>, degree: ?string, hired_on: ?CarbonImmutable}  $identity  la ficha (Faculty)
     * @param  array{username: string, email: ?string}  $account  «Tu acceso» (IN-01)
     * @param  array{in_progress: ?array{id: string, name: string}, planning: ?array{id: string, name: string}}  $terms  el periodo en curso y el activo (MyLoadTerms), aunque el segundo no se muestre
     * @param  array{
     *     date: CarbonImmutable,
     *     date_label: string,
     *     state: 'classes'|'none'|'between_terms',
     *     items: list<array{time: string, subject: string, code: string, group: string, room: ?string, campus: string, status: 'done'|'now'|'next'|'later', starts_in: ?string, gap: ?int, gap_label: ?string, transfer: ?array{from: string, free_minutes: int, short: bool}}>,
     *     next: ?array{day_label: string, time: string, subject: string},
     *     ended: ?array{term: string, ended_on: CarbonImmutable},
     *     upcoming: ?array{term: string, starts_on: CarbonImmutable}
     * }  $today  «Hoy»: `items` solo con `state` 'classes'; `next` (la próxima clase) solo con 'none'; `ended` y `upcoming` solo con 'between_terms'
     * @param  array{
     *     today_count: int,
     *     today_caption: string,
     *     in_progress: ?array{term_name: string, hours: int, groups: int},
     *     planning: ?array{term_name: string, assigned: int, external: int, limit: int, limit_label: string, band_text: ?string, band_min: int, band_max: int, over_band: bool, accepted: bool, no_plaza: bool},
     *     remaining: ?array{hours: int, caption: ?string, over: bool}
     * }  $tiles  las cuatro cifras; `in_progress` es null si es el mismo periodo que `planning`; `planning` y `remaining` son null si la carga está oculta (MN-05) o no hay periodo activo
     * @param  list<array{kind: 'selection_turn'|'hours'|'request'|'experience', tone: 'info'|'warning', icon: string, title: string, detail: string, url: ?string}>  $attention  a lo más 5, en el orden del diseño; vacía es «Nada que atender»
     * @param  ?array{term_name: string, title: string, provisional: bool, days: list<array{day: int, name: string, short: string, hours: int, today: bool, blocks: list<array{time: string, subject: string, code: string, group: string, room: ?string, campus: string}>}>, footer: string}  $week  «Tu semana»; null sin grupos
     * @param  ?array{
     *     term_id: string,
     *     term_name: string,
     *     badge: 'preparing'|'selection'|'published',
     *     published: list<array{scope: ?string, version: int, published_at: CarbonImmutable}>,
     *     offered: bool,
     *     groups: list<array{id: string, code: string, subject: string, subject_code: string, campus: string, rooms: list<string>, no_room: bool, schedule: list<array{days: string, time: string}>, hours: int, counts_teacher_hours: bool}>,
     *     hours: array<string, mixed>,
     *     requests: list<array{group_code: string, subject: string, status: string, status_label: string, tone: string, icon: string, comment: ?string, requested_at: CarbonImmutable}>,
     *     requests_more: int,
     *     my_load_url: string
     * }  $planning  la caja del periodo en planeación (distinto del en curso, con la carga visible); `hours` es `MyLoadQuery::forTeacher()['hours']`; `offered` dice si Mi carga ofrece ese periodo (grupos de Nexus en él)
     * @param  array{total: int, subjects: list<array{subject_id: string, code: string, name: string, level: string}>, rest: list<array{subject_id: string, code: string, name: string, level: string}>, more: int}  $experience  las primeras 6 y el resto (`rest`, que la vista despliega en el lugar)
     * @param  ?array{term_name: string, available: int|float, total: int|float, all_available: bool, days: list<array{day: int, name: string, ranges: list<array{label: string, kind: string, kind_label: string}>}>}  $availability  del periodo activo; `all_available` es «Disponible en todo el horario» (sin excepciones)
     */
    public function __construct(
        public array $identity,
        public array $account,
        public array $terms,
        public array $today,
        public array $tiles,
        public array $attention,
        public ?array $week,
        public ?array $planning,
        public array $experience,
        public ?array $availability,
    ) {}
}
