<?php

use App\Modules\AcademicLoad\Actions\RequestForecastEvaluation;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Queries\ForecastEvaluationsQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\ModalityQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Precisión de la proyección (tarea 5.9c; DESIGN.md, «Precisión de la proyección»: variante B «Contra la meta», elegida
 * por el usuario el 17 de noviembre de 2026). Compara lo que Nexus habría proyectado antes de un periodo ya cursado con
 * los alumnos que de verdad llevaron cada materia y con la regla de R1 (DM-20 a DM-23): cada carrera es una regla del
 * error contra la meta del 15 % (DM-22), agrupadas por veredicto, con la vista «Tabla» como gemela accesible, los filtros
 * de campus (`?campus=`) y de departamento de la materia (`?departamento=`), y sus materias en un drawer.
 *
 * - La evaluación elegida va en `?evaluacion=` (así llega la campana, ForecastEvaluationFinished); sin ella, la que sigue
 *   en curso o la última lista (ForecastEvaluationsQuery::chosen). Las demás, en el drawer «Evaluaciones».
 * - «Evaluar un periodo» (drawer): la modalidad, un periodo con kárdex de ella y el campus opcional; llama a
 *   RequestForecastEvaluation (DM-28 y DM-29), cuyos errores salen en su campo.
 * - Mientras una evaluación está en cola o corriendo, la página consulta cada 2 s: las carreras aparecen conforme
 *   terminan y lo global sale al final.
 *
 * La ven Subdirección y el admin (DM-24; ForecastEvaluationPolicy::viewAny) y solo quien tiene `create` lanza; cada
 * método vuelve a autorizar.
 */
new #[Title('Precisión de la proyección')] class extends Component
{
    use Toast;

    /** Los errores de RequestForecastEvaluation y el campo del drawer donde salen. */
    private const array FORM_FIELDS = ['term' => 'newTerm', 'modality' => 'newModality', 'campus' => 'newCampus'];

    #[Url(as: 'evaluacion', except: '')]
    public string $evaluation = '';

    /** La clave del campus del filtro; '' es «Todos». */
    #[Url(as: 'campus', except: '')]
    public string $campus = '';

    /** La clave del departamento de la materia; '' es todos. */
    #[Url(as: 'departamento', except: '')]
    public string $department = '';

    /** 'grafica' o 'tabla'. */
    #[Url(as: 'vista', except: 'grafica')]
    public string $view = 'grafica';

    public bool $showEvaluations = false;

    public bool $showSubjects = false;

    public ?string $openedCareer = null;

    /** El campus del drawer de materias; '' es el de la página (o todos los de la carrera). */
    public string $subjectCampus = '';

    public bool $showEvaluate = false;

    public string $newModality = '';

    public string $newTerm = '';

    public string $newCampus = '';

    public function mount(ForecastEvaluationsQuery $query): void
    {
        Gate::authorize('viewAny', ForecastEvaluation::class);

        $this->evaluation = $query->chosen($query->list(), $this->evaluation)?->getKey() ?? '';
    }

    /**
     * Elige otra evaluación del drawer «Evaluaciones».
     */
    public function select(string $evaluationId): void
    {
        Gate::authorize('viewAny', ForecastEvaluation::class);

        $evaluation = KeyLookup::findOrFail(ForecastEvaluation::query(), $evaluationId);
        $this->evaluation = $evaluation->getKey();
        $this->showEvaluations = false;
        $this->closeSubjects();
    }

    public function openEvaluations(): void
    {
        Gate::authorize('viewAny', ForecastEvaluation::class);

        $this->showEvaluations = true;
    }

    /**
     * Abre el drawer de las materias de una carrera de la evaluación lista, en el campus de la página.
     */
    public function openCareer(string $careerId): void
    {
        Gate::authorize('viewAny', ForecastEvaluation::class);

        $query = app(ForecastEvaluationsQuery::class);
        $evaluation = $query->chosen($query->list(), $this->evaluation);
        abort_unless($evaluation?->status === ForecastEvaluationStatus::Done, 404);

        $this->openedCareer = $careerId;
        $this->subjectCampus = $this->campus;
        $this->showSubjects = true;
    }

    public function closeSubjects(): void
    {
        $this->showSubjects = false;
        $this->openedCareer = null;
        $this->subjectCampus = '';
    }

    /**
     * Esc o el velo cierran el drawer por wire:model: también se olvida la carrera.
     */
    public function updatedShowSubjects(bool $open): void
    {
        if (! $open) {
            $this->closeSubjects();
        }
    }

    /**
     * Otro filtro de la página cierra las materias, que eran de lo de antes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['campus', 'department'], true)) {
            $this->closeSubjects();
        }
    }

    /**
     * «Evaluar un periodo» (o «Volver a evaluar» con el periodo, la modalidad y el campus de una evaluación fallida).
     */
    public function openEvaluate(string $termId = '', string $modalityCode = '', string $campusCode = ''): void
    {
        Gate::authorize('create', ForecastEvaluation::class);

        $this->resetErrorBag();
        $options = app(ModalityQuery::class)->active();
        $modality = $options->firstWhere('code', $modalityCode) ?? $options->first();
        $terms = $modality === null ? collect() : app(ForecastEvaluationsQuery::class)->evaluableTerms($modality);
        $this->newModality = $modality->code ?? '';
        $this->newTerm = ($terms->firstWhere('id', $termId) ?? $terms->first())?->getKey() ?? '';
        $this->newCampus = $campusCode;
        $this->showEvaluations = false;
        $this->showEvaluate = true;
    }

    /**
     * Otra modalidad: su periodo con kárdex más reciente y todos los campus.
     */
    public function updatedNewModality(): void
    {
        $modality = app(ModalityQuery::class)->active()->firstWhere('code', $this->newModality);
        $this->newTerm = $modality === null ? '' : (app(ForecastEvaluationsQuery::class)->evaluableTerms($modality)->first()?->getKey() ?? '');
        $this->newCampus = '';
        $this->resetErrorBag();
    }

    /**
     * Pide la evaluación (RequestForecastEvaluation; DM-28 y DM-29) y la deja elegida. Si ya había una del mismo periodo,
     * modalidad y campus en curso, elige esa. Lo que rechaza la Action sale en su campo y el drawer sigue abierto.
     */
    public function evaluate(RequestForecastEvaluation $request, ModalityQuery $modalities, CampusQuery $campuses): void
    {
        $this->authorize('create', ForecastEvaluation::class);

        $modality = $modalities->active()->firstWhere('code', $this->newModality);
        $term = filled($this->newTerm) ? KeyLookup::find(Term::query(), $this->newTerm) : null;

        if ($modality === null || $term === null) {
            $this->resetErrorBag();
            $modality === null
                ? $this->addError('newModality', 'Elige una modalidad.')
                : $this->addError('newTerm', 'Elige un periodo con kárdex.');

            return;
        }

        $campus = $this->campusOf($modality, $campuses);

        if (filled($this->newCampus) && $campus === null) {
            $this->addError('newCampus', "Elige un campus de {$modality->name}.");

            return;
        }

        try {
            $evaluation = $request->handle($term, $modality, $campus, auth()->user());
        } catch (ValidationException $exception) {
            $this->resetErrorBag();

            foreach ($exception->errors() as $field => $messages) {
                $this->addError(self::FORM_FIELDS[$field] ?? 'newTerm', $messages[0]);
            }

            return;
        }

        $this->evaluation = $evaluation->getKey();
        $this->campus = '';
        $this->department = '';
        $this->showEvaluate = false;
        $this->closeSubjects();
        $scope = "{$term->name} · {$modality->name} · ".($campus->name ?? 'Todos los campus');

        match (true) {
            ! $evaluation->wasRecentlyCreated => $this->info('Ya había una evaluación en curso', e($scope)),
            $evaluation->status === ForecastEvaluationStatus::Done => $this->success('Evaluación lista', e($scope)),
            $evaluation->status === ForecastEvaluationStatus::Failed => $this->error('La evaluación no terminó', e((string) $evaluation->error)),
            default => $this->success('Evaluación en cola', e($scope)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ForecastEvaluationsQuery $query, ModalityQuery $modalities, CampusQuery $campuses): array
    {
        Gate::authorize('viewAny', ForecastEvaluation::class);

        $list = $query->list();
        $chosen = $query->chosen($list, $this->evaluation);
        $done = $chosen?->status === ForecastEvaluationStatus::Done;

        return [
            'items' => $list->map(fn (ForecastEvaluation $evaluation) => $query->item($evaluation)),
            'current' => $chosen === null ? null : $query->item($chosen),
            'report' => $chosen === null ? null : $query->report($chosen, $this->campus, $this->department),
            'subjects' => $done && $this->showSubjects && $this->openedCareer !== null
                ? $query->subjects($chosen, $this->openedCareer, $this->subjectCampus, $this->department)
                : null,
            'polling' => $list->contains(fn (ForecastEvaluation $evaluation): bool => $evaluation->status->isPending()) || ($chosen?->status->isPending() ?? false),
            'canRun' => Gate::allows('create', ForecastEvaluation::class),
            'form' => $this->showEvaluate ? $this->form($query, $modalities, $campuses) : null,
        ];
    }

    /**
     * Lo que pinta «Evaluar un periodo»: las modalidades activas, los periodos con kárdex de la elegida, sus campus (si
     * usa salones, MS4) y cuándo se evaluó por última vez cada periodo en la modalidad y el campus elegidos.
     *
     * @return array{modalities: Collection<int, Modality>, modality: ?Modality, terms: Collection<int, Term>, campuses: Collection<int, Campus>, evaluated: array<string, string>}
     */
    private function form(ForecastEvaluationsQuery $query, ModalityQuery $modalities, CampusQuery $campuses): array
    {
        $options = $modalities->active();
        $modality = $options->firstWhere('code', $this->newModality);
        $usesRooms = $modality?->valid_profile?->usesRooms === true;
        $campus = $modality === null ? null : $this->campusOf($modality, $campuses);

        return [
            'modalities' => $options,
            'modality' => $modality,
            'terms' => $modality === null ? collect() : $query->evaluableTerms($modality),
            'campuses' => $usesRooms ? $campuses->of($modality) : collect(),
            'evaluated' => $modality === null ? [] : $query->evaluatedOn($modality, $campus),
        ];
    }

    private function campusOf(Modality $modality, CampusQuery $campuses): ?Campus
    {
        return blank($this->newCampus) ? null : $campuses->of($modality)->firstWhere('code', $this->newCampus);
    }
};
