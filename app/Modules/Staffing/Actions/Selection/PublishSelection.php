<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\OpenLoadForSelection;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Jobs\InviteSelectionTeachersJob;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Notifications\SelectionTurnPublished;
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
 */
final class PublishSelection
{
    public function __construct(
        private readonly OpenLoadForSelection $openLoad,
        private readonly RecomputeSelectionOrder $recompute,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Selection $selection, User $actor): Selection
    {
        $published = DB::transaction(function () use ($selection, $actor): Selection {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$selection->getKey()}"]);

            $selection->setRawAttributes(Selection::query()->lockForUpdate()->findOrFail($selection->getKey())->getAttributes(), sync: true);

            if ($selection->status !== SelectionStatus::Draft) {
                throw ValidationException::withMessages(['status' => "La selección está {$selection->status->label()}: solo se publica un borrador."]);
            }

            // La cola se congela con lo de este momento (SEL-10): un docente adscrito después del último CSV o de la
            // última programación entra al final. Si algo de abajo rechaza publicar, la transacción lo deshace.
            $this->recompute->handle($selection);

            $this->guard($selection);

            $loads = $selection->loads()->get();

            foreach ($loads as $load) {
                $this->openLoad->handle($load, $actor);
            }

            $selection->update(['status' => SelectionStatus::Published, 'published_at' => now()]);

            activity()
                ->performedOn($selection)
                ->causedBy($actor)
                ->event('selection_published')
                ->withProperties(['loads' => $loads->pluck('id')->all(), 'entries' => $selection->entries()->count()])
                ->log('Publicó la selección y abrió '.$loads->count().' cargas a la asignación');

            return $selection;
        });

        // SEL-13: los docentes de la cola sin acceso reciben su invitación, en la cola y ya confirmada la publicación.
        InviteSelectionTeachersJob::dispatch((string) $published->getKey(), (string) $actor->getKey());

        $department = (string) $published->department()->value('name');

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
     * @throws ValidationException
     */
    private function guard(Selection $selection): void
    {
        if ($selection->status !== SelectionStatus::Draft) {
            throw ValidationException::withMessages(['status' => "La selección está {$selection->status->label()}: solo se publica un borrador."]);
        }

        $reasons = [];

        if ($selection->starts_at === null || ! $selection->starts_at->isFuture()) {
            $reasons[] = 'La fecha de inicio debe estar en el futuro.';
        }

        if (! $selection->entries()->exists()) {
            $reasons[] = 'La cola no tiene docentes: importa el CSV.';
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
