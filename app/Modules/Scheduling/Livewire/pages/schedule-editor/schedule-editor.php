<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityCampusFilter;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Scheduling\Actions\Sessions\PlaceGroupSession;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\ScheduleEditorQuery;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Editor de horarios (tarea 3.5; maqueta A «Semana con bandeja», decidida por el usuario el 7 de noviembre de 2026;
 * DESIGN.md, "Editor de horarios"; reglas-de-negocio.md, "Editor de horarios"). Se trabaja una carrera, una de sus
 * retículas y un semestre del periodo y campus activos (fijos al abrir la página, como la disponibilidad): a la
 * izquierda la bandeja de grupos por materia y los choques del semestre; a la derecha la semana. Elegir un grupo pinta
 * dónde cabe y tocar una celda coloca un bloque (PlaceGroupSession). La semana también se ve por docente o por salón.
 * Todo lo que pinta sale de ScheduleEditorQuery, que memoriza por petición el semestre y el encaje.
 *
 * Los cajones son componentes aparte (`scheduling::components.session-drawer` y `.group-drawer`): abrirlos no vuelve a
 * pintar ni a calcular la semana; al guardar o quitar una sesión avisan con `schedule-changed` y la página se vuelve a
 * pintar. Cambiar de día en celular, «Ver» de un choque y plegar la bandeja son del navegador (`scheduleEditor`).
 *
 * Entra quien ve los horarios (SchedulingPermission::ScheduleView); lo que llega por la URL pasa por careerOrFail y
 * planOrFail. Elegir un grupo pide que el semestre lo muestre; colocar, editSessions.
 * La URL guarda la vista: `?carrera=`, `?reticula=`, `?semestre=`, `?grupo=` (el elegido; al entrar abre la carrera,
 * retícula y semestre desde donde el usuario lo ve, como lo usan el tablero de choques y las solicitudes de cambio; se
 * conserva al ver por docente o por salón), `?vista=semestre|docente|salon`, `?docente=`, `?salon=` y `?dia=` (el día
 * de la lista en celular).
 *
 * El alcance (maqueta U2, opción A «Selectores en la página», con la regla del editor de la opción D; elegida por el
 * usuario el 12 de noviembre de 2026; WithModalityCampusFilter): `?modalidad=` es la carga que se acomoda (M1; el select
 * «Modalidad» solo con más de una, MS16) y solo cuentan sus grupos, aunque el semestre mezcle modalidades. El campus es
 * un filtro de un campus a la vez (`?campus=`, sin «Todos»): HC-03 y los salones son de un campus, y dos sesiones de
 * campus distintos a la misma hora no chocan. Sus opciones son los campus de la modalidad, con los grupos del semestre
 * en cada uno; quien tiene un nombramiento con campus lee los demás de solo lectura y abre en el suyo (MS6). Un
 * `?grupo=` abre la modalidad y el campus del grupo. Cambiar de modalidad o de campus reabre la página sin lo elegido.
 */
new #[Title('Horarios')] class extends Component
{
    use Toast;
    use WithModalityCampusFilter;

    private const array VIEWS = ['semestre', 'docente', 'salon'];

    #[Url(as: 'carrera')]
    public string $careerId = '';

    #[Url(as: 'reticula')]
    public string $planId = '';

    #[Url(as: 'semestre')]
    public string $semester = '';

    /** El grupo elegido; '' ninguno. */
    #[Url(as: 'grupo')]
    public string $groupId = '';

    #[Url(as: 'vista')]
    public string $view = 'semestre';

    #[Url(as: 'docente')]
    public string $teacherId = '';

    #[Url(as: 'salon')]
    public string $roomId = '';

    /** El día de la lista del celular; '' es el primero del perfil. Lo cambia el navegador. */
    #[Url(as: 'dia')]
    public string $day = '';

    /** El periodo, el campus y la modalidad de la página al abrirla; null si faltaba alguno. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $campusId = null;

    #[Locked]
    public ?string $modalityId = null;

    #[Locked]
    public ?string $message = null;

    public function mount(Actor $context, ScheduleEditorQuery $editor): void
    {
        abort_unless($context->allowsAny(SchedulingPermission::ScheduleView), 403);

        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'semestre';
        $term = $context->activeTerm();

        if ($term !== null && $this->groupId !== '') {
            $this->followGroup($editor->groupInTerm($term, $this->groupId));
        }

        $campus = $this->requiredCampus();
        $modality = $this->requiredModality();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $this->campusId = $campus->id;
        $this->modalityId = $modality->id;
        $this->termId = $term->id;

        if ($this->groupId !== '') {
            $entry = $editor->entryOf($context, $term, $campus, $modality, $editor->groupOrFail($term, $campus, $this->groupId));
            abort_if($entry === null, 403);

            $this->careerId = $entry['career'];
            $this->planId = $entry['plan'];
            $this->semester = $entry['semester'];
        }
    }

    /**
     * Otro campus: la página se abre en él, con la misma modalidad, sin la carrera ni el grupo del anterior.
     */
    public function updatedCampus(): void
    {
        $this->reopenOnCampus('scheduling.editor', ['modalidad' => $this->requiredModality()->code]);
    }

    /**
     * Otra modalidad: la página se abre en ella, en el mismo campus si la otra lo tiene, sin la carrera ni el grupo.
     */
    public function updatedModalidad(): void
    {
        $modality = $this->requiredModality();

        if ($modality->id === $this->modalityId) {
            return;
        }

        $campus = $this->campusFilter();
        $this->redirectRoute('scheduling.editor', array_filter(['modalidad' => $modality->code, 'campus' => $campus?->code]), navigate: true);
    }

    public function updatedCareerId(): void
    {
        $this->reset('planId', 'semester', 'groupId', 'teacherId', 'roomId');
    }

    public function updatedSemester(): void
    {
        $this->reset('groupId', 'teacherId', 'roomId');
    }

    public function choosePlan(string $planId): void
    {
        $this->planId = $planId;
        $this->reset('semester', 'groupId', 'teacherId', 'roomId');
    }

    /**
     * Cambia la vista sin soltar el grupo elegido: por docente muestra a su docente y por salón el de su primera sesión
     * que tiene uno; si no tiene, el que ya estaba o el primero de la lista. Al volver al semestre sigue elegido.
     */
    public function setView(string $view): void
    {
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'semestre';

        if ($this->groupId !== '' && $this->view !== 'semestre') {
            [$term, $campus, $editor, $context, $modality] = $this->context();
            $lens = $editor->lensOf($context, $term, $campus, $modality, $this->selection(), $this->groupId);
            $this->teacherId = $lens['teacher'] ?? $this->teacherId;
            $this->roomId = $lens['room'] ?? $this->roomId;
        }
    }

    /**
     * Elige un grupo del semestre para pintar dónde cabe; elegirlo otra vez desde la bandeja lo suelta (`$toggle`).
     * Se elige lo que el semestre muestra (SemesterBoardQuery: los grupos que el actor ve y los de contexto, de solo
     * lectura); uno de otro semestre o carrera da 403 y la URL nunca lleva un grupo ajeno.
     */
    public function pick(string $groupId, bool $toggle = true): void
    {
        [$term, $campus, $editor, $context, $modality] = $this->context();
        $group = $editor->groupOrFail($term, $campus, $groupId);
        abort_unless($editor->inSemester($context, $term, $campus, $modality, $this->selection(), $group->id), 403);

        $this->groupId = $toggle && $this->groupId === $group->id ? '' : $group->id;
        $this->view = 'semestre';
    }

    public function clearSelection(): void
    {
        $this->groupId = '';
    }

    /**
     * Coloca un bloque del grupo en un día y hora (se une con los contiguos del mismo tipo). Teoría hasta cubrir las
     * horas de teoría de la materia y luego práctica (supuesto por confirmar); el cajón de la sesión lo cambia. Avisa
     * «Colocada con choque» si el grupo rompe una regla dura que antes no rompía, aunque no nombre la sesión (HC-08).
     */
    public function place(string $groupId, int $day, string $startsAt, PlaceGroupSession $place): void
    {
        [$term, $campus, $editor, $context] = $this->context();
        $group = $editor->groupOrFail($term, $campus, $groupId);
        $this->authorize('editSessions', $group);

        $grid = WeekGrid::of($group->modality()->withTrashed()->firstOrFail()->profile);
        $block = collect($grid->blocks)->firstWhere('starts_at', $startsAt);

        if ($block === null || ! in_array($day, array_column($grid->days, 'day'), true)) {
            $this->error('No se colocó', e('Esa hora no está en el horario de la modalidad.'));

            return;
        }

        $placement = $editor->placement($group);
        $before = $editor->hardSignatures($group);

        try {
            $result = $place->handle($group, $day, $block['starts_at'], $block['ends_at'], $placement['kind'], $context->user());
        } catch (ValidationException $exception) {
            $this->error('No se colocó', e((string) collect($exception->errors())->flatten()->first()));

            return;
        }

        $editor->forget();
        $this->groupId = $group->id;
        $this->dispatch('editor-placed', id: $result->session->id);

        $hours = WeekGrid::hours($placement['minutes'] + Minutes::of($block['ends_at']) - Minutes::of($block['starts_at']));
        $where = "{$placement['label']}: ".Minutes::dayName($day).' '.WeekGrid::clock($block['starts_at']);
        $clash = $editor->firstNewHard($before, $result->validation->violations());

        if ($clash !== null) {
            $this->warning('Colocada con choque', e("{$where} · {$clash->rule} · {$clash->message}"));
        } else {
            $this->success('Sesión colocada', e("{$where} · {$hours} de {$placement['required']} h"));
        }
    }

    /**
     * Un cajón guardó o quitó una sesión, o cambiaron los cupos o el salón: se vuelve a pintar con lo guardado (si el
     * grupo elegido ya no es del semestre, la selección se suelta al pintar).
     */
    #[On('schedule-changed')]
    #[On('quotas-saved')]
    #[On('session-room-saved')]
    public function scheduleChanged(): void {}

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        if ($this->termId === null) {
            return ['term' => null, 'careers' => [], 'career' => null, 'message' => $this->message, 'campusCounts' => null, ...$this->scopeViewData(), 'campusCode' => $this->campus];
        }

        [$term, $campus, $editor, $context, $modality] = $this->context();
        $data = $editor->editor($context, $term, $campus, $modality, [...$this->selection(), 'group' => $this->groupId, 'view' => $this->view, 'teacher' => $this->teacherId, 'room' => $this->roomId]);

        if ($data['board'] !== null) {
            $this->groupId = $this->view === 'semestre' && $data['selected'] === null ? '' : $this->groupId;
            $this->semester = (string) $data['semester'];
            $this->teacherId = $this->view === 'docente' ? ($data['person']['id'] ?? '') : $this->teacherId;
            $this->roomId = $this->view === 'salon' ? ($data['person']['id'] ?? '') : $this->roomId;
        }

        $days = isset($data['grid']) ? array_column($data['grid']->days, 'day') : [1];

        return [
            ...$data,
            'term' => $term,
            'campusCounts' => $data['campusCounts'] ?? null,
            ...$this->scopeViewData(),
            'shownModality' => $modality,
            'shownCampus' => $campus,
            'campusCode' => $campus->code,
            'firstDay' => $days[0],
            'mobileDay' => in_array((int) $this->day, $days, true) ? (int) $this->day : $days[0],
            'chainClasses' => WeekGrid::CHAINS,
            'fills' => WeekGrid::FILLS,
        ];
    }

    /**
     * @return array{career: string, plan: string, semester: string}
     */
    private function selection(): array
    {
        return ['career' => $this->careerId, 'plan' => $this->planId, 'semester' => $this->semester];
    }

    /**
     * El periodo, el campus y la modalidad de la página, la Query del editor (la misma en toda la petición) y el actor.
     *
     * @return array{0: Term, 1: Campus, 2: ScheduleEditorQuery, 3: Actor, 4: Modality}
     */
    private function context(): array
    {
        abort_if($this->termId === null || $this->campusId === null || $this->modalityId === null, 404);
        $editor = app(ScheduleEditorQuery::class);

        $modality = $this->modalityOptions()->first(fn (Modality $option): bool => $option->id === $this->modalityId) ?? $editor->modality($this->modalityId);

        return [$editor->term($this->termId), $editor->campus($this->campusId), $editor, app(Actor::class), $modality];
    }

    /**
     * Un `?grupo=` abre la modalidad y el campus del grupo, si están entre las opciones; si no, la página se queda en los
     * suyos y el grupo de otro campus da 404 al buscarlo, como antes.
     */
    private function followGroup(?Group $group): void
    {
        if ($group === null || ! $this->modalityOptions()->contains('id', $group->modality_id)) {
            return;
        }

        $this->modalidad = $group->modality->code;

        if ($group->campus !== null && $this->campusOptions()->contains('id', $group->campus_id)) {
            $this->campus = $group->campus->code;
        }
    }
};
