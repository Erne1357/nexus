<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Actions\Sessions\RemoveGroupSession;
use App\Modules\Scheduling\Actions\Sessions\UpdateGroupSession;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ScheduleEditorQuery;
use App\Modules\Scheduling\Support\WeekGrid;
use App\Modules\Scheduling\Validation\Clock;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * El cajón de una sesión del editor de horarios (tarea 3.5; DESIGN.md, "Editor de horarios"): día, inicio, fin y tipo;
 * «Guardar» la mueve (UpdateGroupSession) y «Quitar sesión» la quita completa (RemoveGroupSession), y los dos avisan a
 * la página con `schedule-changed` para que vuelva a pintar la semana. Abrirlo (`open-session`) no la vuelve a pintar.
 * Muestra el salón, quién lo asigna y lo que dice el validador de la sesión (ScheduleEditorQuery::sessionPanel), leído
 * al abrir y al guardar; el tipo se elige en el navegador y viaja al guardar.
 *
 * El periodo y el campus son los de la página (`#[Locked]`); abrir pide ver el grupo (ScheduleEditorQuery::sees) y
 * guardar o quitar, editSessions, cada vez.
 */
new class extends Component
{
    use Toast;

    #[Locked]
    public string $termId = '';

    #[Locked]
    public string $campusId = '';

    public bool $show = false;

    #[Locked]
    public ?string $sessionId = null;

    public string $day = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $kind = '';

    /** «Quitamos el salón X…» tras moverla a una hora donde su salón estaba ocupado. */
    #[Locked]
    public ?string $roomNotice = null;

    /**
     * Lo que pinta el cajón (ScheduleEditorQuery::sessionPanel), leído al abrir y al guardar: cambiar el día, las horas o
     * el tipo no vuelve a validar el grupo.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $panel = null;

    public function mount(string $termId, string $campusId): void
    {
        $this->termId = $termId;
        $this->campusId = $campusId;
    }

    #[On('open-session')]
    public function open(string $id, ScheduleEditorQuery $editor, Actor $context): void
    {
        [$term, $campus] = $this->context($editor);
        $session = $editor->sessionOrFail($term, $campus, $id);
        abort_unless($editor->sees($context, $term, $campus, $session->group), 403);

        $this->sessionId = $session->id;
        $this->fillFrom($session);
        $this->roomNotice = null;
        $this->panel = $editor->sessionPanel($context, $term, $campus, $session->id);
        $this->resetErrorBag();
        $this->show = true;
    }

    public function save(UpdateGroupSession $update, ScheduleEditorQuery $editor, Actor $context): void
    {
        [$term, $campus] = $this->context($editor);
        $session = $editor->sessionOrFail($term, $campus, (string) $this->sessionId);
        $this->authorize('editSessions', $session->group);

        $grid = WeekGrid::of($campus->profile);
        $this->validate([
            'day' => ['required', Rule::in(array_map('strval', array_column($grid->days, 'day')))],
            'startsAt' => ['required', Rule::in([...array_column($grid->blocks, 'starts_at'), Clock::of($session->starts_at)])],
            'endsAt' => ['required', Rule::in([...array_column($grid->blocks, 'ends_at'), Clock::of($session->ends_at)])],
            'kind' => ['required', Rule::enum(SessionKind::class)],
        ], attributes: ['day' => 'día', 'startsAt' => 'inicio', 'endsAt' => 'fin', 'kind' => 'tipo']);

        $room = $session->room?->code;

        try {
            $result = $update->handle($session, (int) $this->day, $this->startsAt, $this->endsAt, SessionKind::from($this->kind), $context->user());
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();
            $this->addError('session', $message);
            $this->error('No se guardó', e($message));

            return;
        }

        $editor->forget();
        $this->sessionId = $result->session->id;
        $this->fillFrom($result->session);
        $this->panel = $editor->sessionPanel($context, $term, $campus, $result->session->id);
        $this->roomNotice = $result->roomDropped ? "Quitamos el salón {$room}: estaba ocupado a esa hora." : null;
        $this->dispatch('schedule-changed');

        if ($this->roomNotice !== null) {
            $this->warning('Sesión guardada sin salón', e($this->labelOf($result->session).". {$this->roomNotice}"));
        } else {
            $this->success('Sesión guardada', e($this->labelOf($result->session)));
        }
    }

    public function remove(RemoveGroupSession $remove, ScheduleEditorQuery $editor, Actor $context): void
    {
        [$term, $campus] = $this->context($editor);
        $session = $editor->sessionOrFail($term, $campus, (string) $this->sessionId);
        $this->authorize('editSessions', $session->group);
        $label = $this->labelOf($session);

        try {
            $remove->handle($session, $context->user());
        } catch (ValidationException $exception) {
            $this->error('No se quitó', e((string) collect($exception->errors())->flatten()->first()));

            return;
        }

        $editor->forget();
        $this->show = false;
        $this->sessionId = null;
        $this->panel = null;
        $this->dispatch('schedule-changed');
        $this->success('Sesión quitada', e($label));
    }

    private function fillFrom(GroupSession $session): void
    {
        $this->day = (string) $session->day;
        $this->startsAt = Clock::of($session->starts_at);
        $this->endsAt = Clock::of($session->ends_at);
        $this->kind = $session->kind->value;
    }

    private function labelOf(GroupSession $session): string
    {
        $session->loadMissing(['group.subject' => fn ($query) => $query->withTrashed()]);

        return "{$session->group->subject->code} · {$session->group->code}: ".Minutes::dayName($session->day).' '.WeekGrid::range(Clock::of($session->starts_at), Clock::of($session->ends_at));
    }

    /**
     * @return array{0: Term, 1: Campus}
     */
    private function context(ScheduleEditorQuery $editor): array
    {
        return [$editor->term($this->termId), $editor->campus($this->campusId)];
    }
};
