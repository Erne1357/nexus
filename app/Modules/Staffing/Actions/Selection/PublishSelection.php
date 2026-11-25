<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\OpenLoadForSelection;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\GrantHourAllowance;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Jobs\InviteSelectionTeachersJob;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Notifications\PriorChoiceHoursPending;
use App\Modules\Staffing\Notifications\SelectionTurnPublished;
use App\Modules\Staffing\Queries\PriorChoiceHoursQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * SEL-11. Publica la selección: exige borrador, un docente en cada renglón, hora de inicio futura y que cada materia de
 * los grupos de las cargas incluidas tenga revisado su tipo de espacio (`subjects.required_space` no nulo; confirmar un
 * aula guarda `aula`). En una transacción abre cada carga incluida a la asignación sin HC-14
 * (OpenLoadForSelection), deja la selección publicada y, ya confirmada, avisa a cada docente con cuenta su hora.
 *
 * Toma primero el candado de la selección (pg_advisory_xact_lock sobre `staffing_selection:{id}`), el mismo que usan
 * los turnos de los docentes y el reloj, antes de cualquier bloqueo de fila. Ya con el candado recalcula la cola
 * (RecomputeSelectionOrder): así entra al final quien se adscribió al departamento después del último CSV (SEL-10).
 * Ya confirmada, encola las invitaciones de acceso de la cola (InviteSelectionTeachersJob, SEL-13).
 *
 * SEL-16: los renglones de elección previa (`prior_choice_at`, ya `done`) quedan cerrados al publicar, con `closed_at` y las horas de
 * sus grupos en las cargas incluidas (`closed_hours`); se autoriza el total de HC-08 en el periodo con la
 * justificación «Elección previa», igual que SEL-12 al terminar la carga. No reciben aviso de
 * turno (no tienen) pero sí la invitación de acceso. Si todos los de la cola son elección previa, la selección no
 * tiene turnos: se publica ya terminada (`finished_at`), sin pedir hora de inicio.
 */
final class PublishSelection
{
    public function __construct(
        private readonly OpenLoadForSelection $openLoad,
        private readonly RecomputeSelectionOrder $recompute,
        private readonly GrantHourAllowance $grantHours,
        private readonly ScheduleValidator $validator,
        private readonly PriorChoiceHoursQuery $priorHours,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Selection $selection, User $actor): Selection
    {
        /** @var Collection<int, array{entry: SelectionEntry, hours: int, base: int}> $pendingConfirmation SEL-16: elección previa arriba de su banda, a quien se avisa */
        $pendingConfirmation = collect();

        $published = DB::transaction(function () use ($selection, $actor, &$pendingConfirmation): Selection {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$selection->getKey()}"]);

            $selection->setRawAttributes(Selection::query()->lockForUpdate()->findOrFail($selection->getKey())->getAttributes(), sync: true);

            if ($selection->status !== SelectionStatus::Draft) {
                throw ValidationException::withMessages(['status' => "La selección está {$selection->status->label()}: solo se publica un borrador."]);
            }

            // La cola se congela con lo de este momento (SEL-10): un docente adscrito después del último CSV o de la
            // última programación entra al final. Si algo de abajo rechaza publicar, la transacción lo deshace.
            $this->recompute->handle($selection);

            // SEL-16: si todos los de la cola son elección previa no hay turnos que correr: no se pide hora de inicio y la
            // selección termina de una vez, con sus renglones cerrados.
            $withoutTurns = ! $selection->entries()->whereNull('prior_choice_at')->exists();

            $this->guard($selection, requireStart: ! $withoutTurns);

            $loads = $selection->loads()->get();

            foreach ($loads as $load) {
                $this->openLoad->handle($load, $actor);
            }

            $pendingConfirmation = $this->closePriorChoices($selection, $actor);

            $selection->update($withoutTurns
                ? ['status' => SelectionStatus::Finished, 'published_at' => now(), 'finished_at' => now()]
                : ['status' => SelectionStatus::Published, 'published_at' => now()]);

            activity()
                ->performedOn($selection)
                ->causedBy($actor)
                ->event('selection_published')
                ->withProperties(['loads' => $loads->pluck('id')->all(), 'entries' => $selection->entries()->count()])
                ->log($withoutTurns
                    ? 'Publicó la selección, sin turnos porque todos tienen elección previa (SEL-16): terminó y abrió '.$loads->count().' cargas a la asignación'
                    : 'Publicó la selección y abrió '.$loads->count().' cargas a la asignación');

            return $selection;
        });

        // SEL-13: los docentes de la cola sin acceso reciben su invitación, en la cola y ya confirmada la publicación.
        InviteSelectionTeachersJob::dispatch((string) $published->getKey(), (string) $actor->getKey());

        $department = (string) $published->department()->value('name');

        // SEL-16 (1.45): un aviso único a quien debe confirmar sus horas de más (llamada del coordinador, supuesto por confirmar).
        foreach ($pendingConfirmation as ['entry' => $entry, 'hours' => $hours, 'base' => $base]) {
            if ($entry->teacher?->user === null) {
                continue;
            }

            try {
                $entry->teacher->user->notify(new PriorChoiceHoursPending($published->term_id, $published->modality_id, $department, $hours, $base));
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach ($published->entries()->with('teacher.user')->get() as $entry) {
            if ($entry->slot_starts_at === null || $entry->slot_ends_at === null || $entry->teacher?->user === null) {
                continue;
            }

            try {
                $entry->teacher->user->notify(new SelectionTurnPublished($published->term_id, $published->modality_id, $department, $entry->slot_starts_at, $entry->slot_ends_at));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $published;
    }

    /**
     * SEL-16: cierra los renglones de elección previa con las horas de sus grupos y justifica en HC-08 las que queden
     * (SEL-12), salvo a quien quedó arriba de la banda de su base sin confirmar: ese queda cerrado pero con HC-08 como
     * choque hasta que confirme con la casilla (ConfirmPriorChoiceHours, ampliación de SEL-16 del 25 de noviembre de 2026).
     *
     * @return Collection<int, array{entry: SelectionEntry, hours: int, base: int}> los renglones que deben confirmar
     */
    private function closePriorChoices(Selection $selection, User $actor): Collection
    {
        $entries = $selection->entries()->with('teacher.user')->whereNotNull('prior_choice_at')->get();

        if ($entries->isEmpty()) {
            return collect();
        }

        $term = Term::query()->findOrFail($selection->term_id);
        $measured = $this->priorHours->forSelection($selection, $entries);
        $pending = collect();

        foreach ($entries as $entry) {
            $taken = $measured[$entry->getKey()]['hours'] ?? 0;
            $base = $measured[$entry->getKey()]['base'] ?? $entry->base_hours;

            $entry->update(['closed_at' => now(), 'closed_hours' => $taken]);

            if ($measured[$entry->getKey()]['needs_confirmation'] ?? false) {
                $pending->push(['entry' => $entry, 'hours' => $taken, 'base' => $base]);

                continue;
            }

            // Igual que SEL-12 al terminar: se autoriza todo lo que HC-08 le cuenta en el periodo.
            $this->validator->forget();
            $count = $this->validator->teacherTermHours($entry->teacher, $term);
            $total = $count === null ? 0 : $count['assigned_hours'] + $count['external_hours'];

            if ($total > 0) {
                $this->grantHours->handle($entry->teacher, $term, $total, "Elección previa: {$total} h de HC-08 (asignadas y externas) con base de {$base} h (SEL-16, como SEL-12).", $actor);
                $this->validator->forget();
            }
        }

        return $pending;
    }

    /**
     * @throws ValidationException
     */
    private function guard(Selection $selection, bool $requireStart = true): void
    {
        if ($selection->status !== SelectionStatus::Draft) {
            throw ValidationException::withMessages(['status' => "La selección está {$selection->status->label()}: solo se publica un borrador."]);
        }

        $reasons = [];

        if ($requireStart && ($selection->starts_at === null || ! $selection->starts_at->isFuture())) {
            $reasons[] = 'La fecha de inicio debe estar en el futuro.';
        }

        if (! $selection->entries()->where('source', SelectionEntrySource::Csv)->exists()) {
            $reasons[] = 'La cola no tiene renglones del CSV: importa el CSV de criterios.';
        }

        $unresolved = $selection->entries()->whereNull('teacher_id')->count();

        if ($unresolved > 0) {
            $reasons[] = "Hay {$unresolved} nombres sin empatar con un docente: resuélvelos o descártalos.";
        }

        $loads = $selection->loads()->with('department')->get();

        if ($loads->isEmpty()) {
            $reasons[] = 'La selección no tiene cargas incluidas.';
        }

        foreach ($loads as $load) {
            if (! $load->status->opensForSelection() && $load->status !== LoadStatus::Staffing) {
                $reasons[] = "La carga de {$load->department->code} {$load->status->predicate()}: ya no puede abrirse a la selección.";
            }
        }

        $unreviewed = $loads->isEmpty() ? [] : $this->unreviewedSubjects($loads);

        if ($unreviewed !== []) {
            $reasons[] = 'Falta revisar el tipo de espacio de: '.implode(', ', $unreviewed).'.';
        }

        if ($reasons !== []) {
            throw ValidationException::withMessages(['status' => $reasons]);
        }
    }

    /**
     * @param  Collection<int, AcademicLoad>  $loads
     * @return list<string> códigos de las materias de los grupos de las cargas con el tipo de espacio sin revisar
     */
    private function unreviewedSubjects($loads): array
    {
        $loads->load('department');

        $subjects = Subject::query()
            ->whereNull('required_space')
            ->whereIn('id', Group::query()
                ->where('status', '!=', GroupStatus::Cancelled->value)
                ->where(fn ($query) => $loads->each(fn (AcademicLoad $load) => $query->orWhere(fn ($pair) => $pair
                    ->where('term_id', $load->term_id)
                    ->where('modality_id', $load->modality_id)
                    ->where('department_id', $load->department_id)))
                )
                ->select('subject_id'))
            ->orderBy('code')
            ->pluck('code');

        return $subjects->all();
    }
}
