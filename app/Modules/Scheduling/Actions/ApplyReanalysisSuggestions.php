<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use App\Modules\Scheduling\Rules\Reanalysis\SuggestionBatch;
use App\Modules\Scheduling\Rules\Reanalysis\SuggestionBatchResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aplica varias sugerencias de un bloque del re-análisis a la vez, las que el jefe eligió en el drawer («Aplicar las N
 * elegidas», seguimiento de la tarea 8.3a).
 *
 * **Una por una** (supuesto por confirmar): cada sugerencia va con su Action de siempre, en su propia transacción y con
 * todas sus guardas (ApplyReanalysisSuggestion; «Abrir un grupo», OpenReanalysisGroup con el lugar que encontró el motor):
 * la carga en re-análisis, GroupPolicy, los grupos como se leyeron (`expected`) y el deadlock como error de validación
 * (LoadEditability::guard()). Si una ya no se puede (otra persona cambió su grupo, le asignaron docente al que se cierra,
 * su salón se ocupó), no se aplica y se dice por qué; las demás sí. Se eligió sobre «todo o nada» porque el asesor calcula
 * cada sugerencia para que sirva sola o junto con las demás (ReanalysisAdvisor): aplicar una parte no deja la carga a
 * medias, solo menos ajustada, y lo que falta se vuelve a sugerir; y porque una sola transacción tendría bloqueados todos
 * los grupos del bloque mientras corren todas.
 *
 * Antes de escribir nada, y para todo el lote: quien actúa ajusta la carga (RA-07, `startReanalysis`), la carga sigue en
 * re-análisis, dos elegidas no chocan (SuggestionBatch: rechaza el lote con las dos y el motivo) y GroupPolicy deja hacer
 * cada una sobre cada grupo que toca (ApplyReanalysisSuggestion::abilities(); el que nace de abrir un grupo,
 * OpenReanalysisGroup::ABILITIES): un permiso que falta no aplica ninguna. Abrir un grupo va primero (SuggestionBatch::
 * inOrder()). La bitácora es la de cada Action; no hay una del lote.
 *
 * Una vez que empieza a escribir, nada detiene el lote a medias: si la Action de una niega el permiso (otra cosa que pide
 * al aplicar, como el préstamo de un salón) o falla la base, esa se cuenta como no aplicada con su motivo (y el error se
 * reporta) y siguen las demás, porque las anteriores ya se guardaron y la pantalla tiene que decirlo.
 */
final class ApplyReanalysisSuggestions
{
    public function __construct(
        private readonly ApplyReanalysisSuggestion $apply,
        private readonly OpenReanalysisGroup $open,
    ) {}

    /**
     * @param  list<ReanalysisSuggestion>  $suggestions  las de un mismo bloque, calculadas en el servidor
     * @param  array<string, NewGroupPlacement>  $placements  el lugar que encontró el motor para cada «Abrir un grupo», por llave
     *
     * @throws AuthorizationException si quien actúa no ajusta la carga o no puede hacer alguna con su grupo: no aplica ninguna
     * @throws ValidationException si el lote está vacío, la carga ya no está en re-análisis o dos elegidas chocan: no aplica ninguna
     */
    public function handle(AcademicLoad $load, array $suggestions, array $placements, User $actor): SuggestionBatchResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('startReanalysis', $load);

        if ($suggestions === []) {
            throw ValidationException::withMessages(['suggestions' => 'Elige al menos una sugerencia.']);
        }

        if (AcademicLoad::query()->whereKey($load->getKey())->first(['id', 'status'])?->status !== LoadStatus::Reanalysis) {
            throw ValidationException::withMessages(['suggestions' => 'La carga ya no está en re-análisis: las sugerencias ya no se aplican.']);
        }

        if (($clash = SuggestionBatch::firstClash($suggestions)) !== null) {
            throw ValidationException::withMessages(['suggestions' => $clash]);
        }

        $this->authorizeAll($gate, $load, $suggestions);

        $applied = [];
        $failed = [];
        $opened = [];

        foreach (SuggestionBatch::inOrder($suggestions) as $suggestion) {
            try {
                if ($suggestion->kind === ReanalysisSuggestionKind::OpenGroup) {
                    $placement = $placements[$suggestion->key]
                        ?? throw ValidationException::withMessages(['suggestion' => 'Busca otra vez el horario y el salón del grupo nuevo.']);
                    $opened[$suggestion->key] = $this->open->handle($load, $suggestion, $placement, $actor);
                } else {
                    $this->apply->handle($load, $suggestion, $actor);
                }

                $applied[] = $suggestion;
            } catch (ValidationException $exception) {
                $failed[] = ['suggestion' => $suggestion, 'reason' => implode(' ', $exception->validator->errors()->all())];
            } catch (AuthorizationException) {
                $failed[] = ['suggestion' => $suggestion, 'reason' => 'No tienes permiso para aplicarla: la ajusta la jefatura de su departamento.'];
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = ['suggestion' => $suggestion, 'reason' => 'No se pudo aplicar por un error inesperado; intenta de nuevo.'];
            }
        }

        return new SuggestionBatchResult($applied, $failed, $opened);
    }

    /**
     * GroupPolicy sobre cada grupo vivo de la carga que toca cada sugerencia (en una consulta) y sobre el que nacería de
     * abrir uno. Un grupo que ya no está (otra pestaña lo canceló) no se autoriza aquí: su Action dice que la sugerencia
     * ya no aplica, sin detener las demás.
     *
     * @param  list<ReanalysisSuggestion>  $suggestions
     *
     * @throws AuthorizationException
     */
    private function authorizeAll(GateContract $gate, AcademicLoad $load, array $suggestions): void
    {
        $groups = Group::query()
            ->active()
            ->where('term_id', $load->term_id)
            ->where('modality_id', $load->modality_id)
            ->where('department_id', $load->department_id)
            ->whereIn('id', array_values(array_unique(array_merge(...array_map(fn (ReanalysisSuggestion $suggestion): array => $suggestion->groupIds(), $suggestions)))))
            ->get()
            ->keyBy('id');

        foreach ($suggestions as $suggestion) {
            if ($suggestion->newGroup !== null) {
                $draft = OpenReanalysisGroup::draft($load, $suggestion->newGroup);

                foreach (OpenReanalysisGroup::ABILITIES as $ability) {
                    $gate->authorize($ability, $draft);
                }
            }

            foreach (ApplyReanalysisSuggestion::abilities($suggestion) as $groupId => $abilities) {
                foreach (isset($groups[$groupId]) ? $abilities : [] as $ability) {
                    $gate->authorize($ability, $groups[$groupId]);
                }
            }
        }
    }
}
