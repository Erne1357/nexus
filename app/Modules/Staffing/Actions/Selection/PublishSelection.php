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
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Notifications\SelectionTurnPublished;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEL-11. Publica la selección: exige borrador, un docente en cada renglón, hora de inicio futura y que cada materia de
 * los grupos de las cargas incluidas tenga revisado su tipo de espacio (`subjects.required_space` no nulo; confirmar un
 * aula guarda `aula`). En una transacción abre cada carga incluida a la asignación sin HC-14
 * (OpenLoadForSelection), deja la selección publicada y, ya confirmada, avisa a cada docente con cuenta su hora.
 */
final class PublishSelection
{
    public function __construct(private readonly OpenLoadForSelection $openLoad) {}

    /**
     * @throws ValidationException
     */
    public function handle(Selection $selection, User $actor): Selection
    {
        $published = DB::transaction(function () use ($selection, $actor): Selection {
            $selection->setRawAttributes(Selection::query()->lockForUpdate()->findOrFail($selection->getKey())->getAttributes(), sync: true);

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

        foreach ($published->entries()->with('teacher.user')->get() as $entry) {
            $entry->teacher?->user?->notify(new SelectionTurnPublished($published, $entry));
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

        $loads = $selection->loads()->get();

        if ($loads->isEmpty()) {
            $reasons[] = 'La selección no incluye ninguna carga.';
        }

        foreach ($loads as $load) {
            if (! $load->status->opensForSelection() && $load->status !== LoadStatus::Staffing) {
                $reasons[] = "La carga de {$load->department->code} {$load->status->predicate()}: ya no puede abrirse a la selección.";
            }
        }

        $unreviewed = $this->unreviewedSubjects($loads);

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
