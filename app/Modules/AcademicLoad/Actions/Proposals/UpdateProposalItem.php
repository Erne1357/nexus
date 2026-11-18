<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\AcademicLoad\Events\ProposalItemReviewed;
use App\Modules\AcademicLoad\Events\ProposalItemUpdated;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\ProposalForecastQuery;
use App\Modules\AcademicLoad\Rules\ProposalItemRules;
use App\Modules\AcademicLoad\Rules\ProposalSuggestionState;
use App\Modules\AcademicLoad\Rules\SuggestedRowGroups;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\DemandSettings;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Corrige una fila de la propuesta de carrera, que es una materia en un campus (M6): alumnos esperados, grupos
 * solicitados y notas, en los rangos de ProposalItemRules (reglas-de-negocio.md, "Propuesta de carrera"). Los alumnos
 * posibles no se tocan.
 *
 * En una transacción bloquea la propuesta (el estado que vale es el de la base) y exige que esté en borrador o
 * regresada: enviada queda de solo lectura y aceptada ya no cambia; si no, una ValidationException sobre
 * `status`. Luego bloquea la parte del departamento de la materia, en el mismo orden que AcceptProposalReview y
 * ReturnProposalReview (propuesta y luego parte), para que una corrección y la aceptación de esa parte no se
 * crucen (supuesto por confirmar): si el departamento ya la aceptó, también con la propuesta regresada por otro
 * departamento, sus materias quedan bloqueadas (confirmado el 2 de noviembre de 2026) y es una
 * ValidationException sobre `item`. Al final relee la fila con bloqueo para que el valor anterior de la bitácora
 * sea el de la base. Sin cambios no escribe, no registra ni avisa; con cambios guarda, deja en la bitácora de la
 * fila el valor anterior y el nuevo de lo que cambió y, después del commit, avisa con ProposalItemUpdated. Quién
 * puede lo decide CareerProposalPolicy::update sobre la fila antes de llamarla: un coordinador con campus solo corrige las
 * de su campus (M7); quien corrige se recibe como parámetro. En la fila de un campus inactivo solo se dejan en cero los
 * grupos pedidos, también los que salen de paquetes (zeroRequestedGroups(), M14). Los grupos pedidos nunca bajan de los que salen
 * de paquetes (`package_groups`, tarea 5.6b): una ValidationException sobre `requested_groups` que manda a cambiar los paquetes. Si cambian los esperados o los grupos, la fila queda marcada como corregida
 * (`captured_at` y `captured_by`, tarea 5.5): desde ahí la proyección ya no la mueve sola; la nota sola no cuenta. Ese
 * cambio también borra la marca de revisada (`reviewed_at` y `reviewed_by`, tarea 5.6): lo revisado ya no es lo que hay
 * (supuesto por confirmar). «Usar sugerido» de «Revisar proyección» es useSuggested().
 */
final class UpdateProposalItem
{
    /** Las marcas de quién tocó la fila: se guardan, pero no van a la bitácora ni al aviso de lo que cambió. */
    private const array MARKS = ['captured_at', 'captured_by', 'reviewed_at', 'reviewed_by'];

    public function __construct(
        private readonly ProposalItemRules $rules,
        private readonly ProposalForecastQuery $forecasts,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(ProposalItem $item, int $expectedStudents, int $requestedGroups, ?string $notes, User $actor): ProposalItem
    {
        $data = $this->rules->normalize($expectedStudents, $requestedGroups, $notes);

        return $this->change($item, $actor, fn (ProposalItem $locked): array => $this->withCapture($locked, $data, $actor));
    }

    /**
     * M14 (confirmado el 12 de noviembre de 2026): deja en cero los grupos pedidos de una fila cuyo campus está inactivo o
     * borrado, sin tocar los alumnos esperados ni la nota, con las mismas guardas y la misma bitácora que una corrección.
     * Dentro de la transacción vuelve a leer el campus: si ya está activo, la fila se corrige como siempre y es una
     * ValidationException sobre `item`. Quién puede lo decide CareerProposalPolicy::zeroInactiveCampus antes de llamarla.
     *
     * @throws ValidationException
     */
    public function zeroRequestedGroups(ProposalItem $item, User $actor): ProposalItem
    {
        return $this->change($item, $actor, function (ProposalItem $locked) use ($actor): array {
            if ($locked->campus === null || ($locked->campus->is_active && ! $locked->campus->trashed())) {
                throw ValidationException::withMessages(['item' => ($locked->campus?->code ?? 'La fila').' está activo: corrige la fila como siempre.']);
            }

            return $this->withCapture($locked, ['requested_groups' => 0, 'package_groups' => 0], $actor);
        });
    }

    /**
     * «Usar sugerido» en «Revisar proyección» (tarea 5.6, opción C elegida por el usuario el 17 de noviembre de 2026): pone
     * los esperados y los grupos en lo que sugiere la proyección vigente y deja la fila corregida y revisada, con las guardas
     * de change(). Bajo el bloqueo revisa el estado de la fila (ProposalSuggestionState con la proyección que hay en ese
     * momento): solo se usa en una fila corregida cuyo `suggested_*` es el de la proyección vigente; si la proyección cambió
     * manda a «Usar N» (ResolveForecastChange), y sin proyección o sin tocar no hay nada que usar. Con paquetes, los grupos
     * son los de paquetes más los sueltos sugeridos (SuggestedRowGroups, regla de la 5.6b), sin pasar del tope de una fila.
     *
     * Deja bitácora también si solo cambian las marcas (corregida y revisada), y avisa con ProposalItemReviewed después del
     * commit. Todo error es una ValidationException sobre `item`, que la tarjeta muestra. Quién puede lo decide
     * CareerProposalPolicy::update sobre la fila antes de llamarla (M7).
     *
     * @throws ValidationException
     */
    public function useSuggested(ProposalItem $item, User $actor): ProposalItem
    {
        $valuesChanged = false;
        $oldMarks = [];

        try {
            return DB::transaction(function () use ($item, $actor, &$valuesChanged, &$oldMarks): ProposalItem {
                $this->change($item, $actor, function (ProposalItem $locked) use ($actor, &$valuesChanged, &$oldMarks): array {
                    $proposal = $locked->proposal;
                    $settings = DemandSettings::fromSettings();
                    $forecast = $this->forecasts->currentFor($proposal, $locked);
                    $state = ProposalSuggestionState::of($locked->expected_students, $locked->requested_groups, $locked->suggested_expected_students,
                        $locked->suggested_groups, $locked->captured_at, $forecast?->expected_students, $forecast?->suggested_groups, $settings);

                    if ($state->state === SuggestionState::Changed) {
                        throw ValidationException::withMessages(['item' => 'La proyección cambió después de corregirla: usa «Usar N» para tomar lo que sugiere ahora.']);
                    }

                    if ($forecast === null || $state->state === SuggestionState::None) {
                        throw ValidationException::withMessages(['item' => 'Ya no hay proyección de esta materia: corrígela en el panel.']);
                    }

                    if ($state->state === SuggestionState::Suggested) {
                        throw ValidationException::withMessages(['item' => 'Esta materia ya sigue lo que sugiere Nexus.']);
                    }

                    $groups = SuggestedRowGroups::for($forecast->suggested_groups, $forecast->expected_students, $locked->package_groups, $forecast->method,
                        $this->forecasts->intakes($proposal)[(string) $locked->campus_id] ?? 0, (bool) $locked->subject->needs_lab, $settings->groupSizes);

                    if ($groups > ProposalItemRules::MAX_REQUESTED_GROUPS) {
                        throw ValidationException::withMessages(['item' => 'Con los grupos de paquetes pasaría de '.ProposalItemRules::MAX_REQUESTED_GROUPS.' grupos: cambia los paquetes.']);
                    }

                    $valuesChanged = $locked->expected_students !== $forecast->expected_students || $locked->requested_groups !== $groups;
                    $oldMarks = $locked->only(self::MARKS);

                    return [
                        'expected_students' => $forecast->expected_students,
                        'requested_groups' => $groups,
                        'captured_at' => now(),
                        'captured_by' => $actor->getKey(),
                        'reviewed_at' => now(),
                        'reviewed_by' => $actor->getKey(),
                    ];
                });

                if (! $valuesChanged) {
                    $proposal = $item->proposal()->with(['career' => fn ($query) => $query->withTrashed(), 'modality' => fn ($query) => $query->withTrashed()])->firstOrFail();

                    activity()
                        ->performedOn($item)
                        ->causedBy($actor)
                        ->event(ActivityEvent::Updated)
                        ->withChanges(['attributes' => $item->only(self::MARKS), 'old' => $oldMarks])
                        ->withProperties(['career_proposal_id' => $proposal->getKey(), 'subject_id' => $item->subject_id, 'campus_id' => $item->campus_id])
                        ->log("Usó lo sugerido en {$item->subject->code}".($item->campus === null ? '' : " de {$item->campus->code}")." en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");
                }

                ProposalItemReviewed::dispatch($item);

                return $item;
            });
        } catch (ValidationException $e) {
            if (array_keys($e->errors()) === ['item']) {
                throw $e;
            }

            throw ValidationException::withMessages(['item' => (string) collect($e->errors())->flatten()->first()]);
        }
    }

    /**
     * Si `$values` cambia esperados o grupos de la fila, agrega la marca de que el coordinador la corrigió
     * (`captured_at` y `captured_by`; tarea 5.5) y borra la de revisada (tarea 5.6); la nota sola no cuenta.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withCapture(ProposalItem $locked, array $values, User $actor): array
    {
        foreach (['expected_students', 'requested_groups'] as $column) {
            if (array_key_exists($column, $values) && $values[$column] !== $locked->{$column}) {
                return [...$values, 'captured_at' => now(), 'captured_by' => $actor->getKey(), 'reviewed_at' => null, 'reviewed_by' => null];
            }
        }

        return $values;
    }

    /**
     * La corrección en una transacción: bloquea la propuesta y la parte, relee la fila con bloqueo y escribe lo que
     * devuelve `$data` con la fila ya leída (con su materia y su campus), con la bitácora y el aviso si algo cambió.
     * Es la misma guarda de ResolveForecastChange y de MarkProposalItemReviewed. Las marcas de captura y de revisión se
     * guardan pero no entran a la bitácora ni al aviso: la bitácora lleva lo que cambió en la fila, no quién la tocó.
     *
     * @param  Closure(ProposalItem): array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function change(ProposalItem $item, User $actor, Closure $data): ProposalItem
    {
        return DB::transaction(function () use ($item, $data, $actor): ProposalItem {
            $proposal = CareerProposal::query()
                ->with(['career' => fn ($query) => $query->withTrashed(), 'modality' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->findOrFail($item->career_proposal_id);

            if (! $proposal->status->isEditable()) {
                throw ValidationException::withMessages(['status' => $proposal->status === ProposalStatus::Accepted
                    ? 'La propuesta ya se aceptó: ya no cambia.'
                    : 'La propuesta ya se envió: solo se cambia si un departamento la regresa.']);
            }

            $item->load([
                'subject' => fn ($query) => $query->withTrashed()->with(['department' => fn ($department) => $department->withTrashed()]),
                'campus' => fn ($query) => $query->withTrashed(),
            ]);
            $subject = $item->subject;

            $part = ProposalReview::query()
                ->where('career_proposal_id', $proposal->getKey())
                ->where('department_id', $subject->department_id)
                ->lockForUpdate()
                ->first();

            if ($part?->status === ProposalReviewStatus::Accepted) {
                throw ValidationException::withMessages(['item' => "{$subject->department->code} ya aceptó su parte: sus materias ya no cambian."]);
            }

            $item->setRawAttributes($item->newQuery()->lockForUpdate()->findOrFail($item->getKey())->getAttributes(), sync: true);

            $values = $data($item);

            if (isset($values['requested_groups']) && ! array_key_exists('package_groups', $values) && $values['requested_groups'] < $item->package_groups) {
                throw ValidationException::withMessages(['requested_groups' => "{$item->package_groups} grupos salen de paquetes; cambia los paquetes para bajarlos."]);
            }

            $old = $item->only(array_keys($values));
            $item->fill($values);
            $dirty = array_keys($item->getDirty());
            $changed = array_values(array_diff($dirty, self::MARKS));

            if ($dirty === []) {
                return $item;
            }

            $item->save();

            if ($changed === []) {
                return $item;
            }

            activity()
                ->performedOn($item)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $item->only($changed), 'old' => Arr::only($old, $changed)])
                ->withProperties(['career_proposal_id' => $proposal->getKey(), 'subject_id' => $item->subject_id, 'campus_id' => $item->campus_id])
                ->log("Cambió {$subject->code}".($item->campus === null ? '' : " de {$item->campus->code}")." en la propuesta de {$proposal->career->code} en {$proposal->modality->name}");

            ProposalItemUpdated::dispatch($item, $changed);

            return $item;
        });
    }
}
