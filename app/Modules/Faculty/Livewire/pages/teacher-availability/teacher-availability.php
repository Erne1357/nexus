<?php

use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TermQuery;
use App\Modules\Faculty\Actions\SetTeacherAvailability;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Queries\AvailabilityGridQuery;
use App\Modules\Faculty\Queries\TeacherQuery;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Editor de disponibilidad de un docente (tarea 1.12, spec S12; DESIGN.md, "Cuadrícula día x hora"), página hija de su
 * ficha: la cuadrícula día × hora del periodo activo, con la variante A que eligió el usuario el 30 de octubre de
 * 2026 («herramienta y pintar»). Se elige «No disponible», «Preferido» o «Borrar» (teclas N, P y B) y se pinta con
 * clic o arrastre; el día o la hora pintan su columna o su renglón. Lo pintado queda pendiente en el navegador (el
 * componente Alpine `availabilityEditor`, en resources/js/app.js) hasta «Guardar disponibilidad», que llama a save();
 * «Copiar de <periodo>» trae la del semestre anterior como punto de partida, sin guardarla, y currentKinds() da lo
 * guardado cuando el navegador restaura la página de su caché.
 *
 * El periodo y el campus son los activos al abrir la página y se quedan fijos (`#[Locked]`): lo que se guarda es lo
 * que se vio aunque la credencial cambie en otra pestaña. El perfil de la cuadrícula es el de ese campus o, sin él, el
 * presencial por omisión (S12, supuesto por confirmar). Sin periodo activo pide elegirlo.
 *
 * Lo abren y guardan quienes administran al docente (TeacherPolicy::manageAvailability); autoriza en mount(), en
 * with() y en cada método. save() y copyPrevious() no vuelven a pintar la página (la cuadrícula es de Alpine): dan
 * su resultado y avisan con un toast.
 */
new #[Title('Disponibilidad')] class extends Component
{
    use Toast;

    /** El docente del editor. El navegador no lo puede cambiar. */
    #[Locked]
    public string $teacherId;

    /** El periodo activo al abrir la página; null si no había. */
    #[Locked]
    public ?string $termId = null;

    /** El campus activo al abrir la página, cuyo perfil arma la cuadrícula; null si no había. */
    #[Locked]
    public ?string $campusId = null;

    public function mount(string $teacher, TeacherQuery $teachers, ScopedContext $context): void
    {
        $model = $teachers->findOrFail($teacher);
        $this->authorize('manageAvailability', $model);
        $this->teacherId = $model->id;
        $this->termId = $context->activeTerm()?->id;
        $this->campusId = $context->activeCampus()?->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(TeacherQuery $teachers, TermQuery $terms, AvailabilityGridQuery $grids): array
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('manageAvailability', $teacher);

        $term = $this->term($terms);
        $campus = $grids->campus($this->campusId);
        $profile = $grids->profile($campus);
        $grid = $term === null ? null : $grids->for($teacher, $term, $profile);
        $previous = $term === null ? null : $grids->previousTerm($term);

        return [
            'teacher' => $teacher,
            'term' => $term,
            'campus' => $campus,
            'grid' => $grid,
            'blocks' => $profile->blockMinutes === 60 ? 'una hora' : "{$profile->blockMinutes} minutos",
            'previous' => $previous,
            'copyReason' => match (true) {
                $previous === null => 'No hay un semestre anterior.',
                ! $grids->hasAvailability($teacher, $previous, $profile) => "{$previous->name} no tiene disponibilidad en este horario.",
                default => null,
            },
            'editor' => $grid === null ? null : $this->editorConfig($grid),
        ];
    }

    /**
     * Guarda lo pintado en el periodo de la página: `$cells` son los bloques marcados, `{day, starts_at, kind}`. Da
     * true si se guardó (o no había nada que cambiar); si una regla lo rechaza, lo dice en un toast y da false.
     *
     * @param  array<int, mixed>  $cells
     */
    #[Renderless]
    public function save(array $cells, TeacherQuery $teachers, TermQuery $terms, AvailabilityGridQuery $grids, SetTeacherAvailability $setAvailability): bool
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('manageAvailability', $teacher);
        $term = $this->term($terms);

        if ($term === null) {
            $this->error('No se guardó la disponibilidad', 'Elige un periodo en tu credencial.');

            return false;
        }

        $profile = $grids->profile($grids->campus($this->campusId));

        try {
            $marked = $setAvailability->handle($teacher, $term, $cells, $profile);
        } catch (ValidationException $exception) {
            $this->error('No se guardó la disponibilidad', e(collect($exception->errors())->flatten()->implode(' ')));

            return false;
        }

        $unavailable = AvailabilityGridQuery::hours($marked['unavailable']);
        $preferred = AvailabilityGridQuery::hours($marked['preferred']);
        $this->success('Disponibilidad guardada', e("{$term->name}: {$unavailable} h no disponibles y {$preferred} h preferidas."));

        return true;
    }

    /**
     * La disponibilidad del semestre anterior (sin verano) en la cuadrícula de la página, como tipos por día y bloque
     * (null es disponible), para que el editor la pinte como pendiente. No guarda nada. Null, con un toast, si no hay
     * periodo anterior o no tiene disponibilidad en el horario de la cuadrícula.
     *
     * @return list<list<?string>>|null
     */
    #[Renderless]
    public function copyPrevious(TeacherQuery $teachers, TermQuery $terms, AvailabilityGridQuery $grids): ?array
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('manageAvailability', $teacher);
        $term = $this->term($terms);
        $previous = $term === null ? null : $grids->previousTerm($term);
        $profile = $grids->profile($grids->campus($this->campusId));

        if ($previous === null || ! $grids->hasAvailability($teacher, $previous, $profile)) {
            $this->error('No se copió la disponibilidad', $previous === null ? 'No hay un semestre anterior.' : e("{$previous->name} no tiene disponibilidad en este horario."));

            return null;
        }

        $this->info(e("Se copió la de {$previous->name}"), 'Falta guardarla.');

        return $this->editorConfig($grids->for($teacher, $previous, $profile))['kinds'];
    }

    /**
     * La disponibilidad guardada del periodo de la página, como tipos por día y bloque (null es disponible). El editor
     * la pide cuando el navegador lo restaura de su caché (Atrás o Adelante), porque esa página trae los tipos de cuando
     * se abrió y no lo guardado después. Null sin periodo.
     *
     * @return list<list<?string>>|null
     */
    #[Renderless]
    public function currentKinds(TeacherQuery $teachers, TermQuery $terms, AvailabilityGridQuery $grids): ?array
    {
        $teacher = $teachers->findOrFail($this->teacherId);
        $this->authorize('manageAvailability', $teacher);
        $term = $this->term($terms);

        return $term === null ? null : $this->editorConfig($grids->for($teacher, $term, $grids->profile($grids->campus($this->campusId))))['kinds'];
    }

    private function term(TermQuery $terms): ?Term
    {
        return $this->termId === null ? null : $terms->findOrFail($this->termId);
    }

    /**
     * Lo que necesita el componente Alpine: los días, los bloques, el tipo de cada celda y los nombres de los tipos.
     *
     * @param  array{days: list<array{day: int, name: string}>, blocks: list<array{starts_at: string, label: string, end_label: string}>, blockMinutes: int, cells: list<list<array{kind: ?AvailabilityKind}>>}  $grid
     * @return array{days: list<array{day: int, name: string}>, blocks: list<array{starts_at: string, label: string, end_label: string}>, blockMinutes: int, kinds: list<list<?string>>, labels: array<string, string>}
     */
    private function editorConfig(array $grid): array
    {
        return [
            'days' => array_map(fn (array $day): array => ['day' => $day['day'], 'name' => $day['name']], $grid['days']),
            'blocks' => array_map(fn (array $block): array => ['starts_at' => $block['starts_at'], 'label' => $block['label'], 'end_label' => $block['end_label']], $grid['blocks']),
            'blockMinutes' => $grid['blockMinutes'],
            'kinds' => array_map(fn (array $cells): array => array_map(fn (array $cell): ?string => $cell['kind']?->value, $cells), $grid['cells']),
            'labels' => ['' => 'Disponible', ...array_column(array_map(fn (AvailabilityKind $kind): array => [$kind->value, $kind->label()], AvailabilityKind::cases()), 1, 0)],
        ];
    }
};
