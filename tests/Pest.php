<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/*
| Feature y Browser corren con la aplicación de Laravel arrancada y la base de
| pruebas refrescada; Unit y Arch, sin ella.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser');

/*
| Los browser tests cargan el CSS y el JS compilados (`npm run build`), porque la banda, la credencial
| y el menú no funcionan sin ellos (TestCase los quita para el resto). Usan siempre el manifiesto,
| aunque corra el servidor de desarrollo de Vite: Chromium, dentro del contenedor, no llega a él.
*/
pest()->beforeEach(function () {
    $this->withVite();
    app(Vite::class)->useHotFile(storage_path('framework/testing/vite-hot-ignorado'));
})->in('Browser');

/**
 * El último toast que el componente mandó mostrar: MaryUI lo evalúa en el navegador como `toast({...})`.
 *
 * @return array{type: string, title: string, description: ?string}|null
 */
function lastToastOf(Testable $component): ?array
{
    $expression = collect($component->effects['xjs'] ?? [])
        ->pluck('expression')
        ->last(fn (string $expression): bool => str_starts_with($expression, 'toast('));

    return $expression === null ? null : json_decode(substr($expression, 6, -1), true)['toast'];
}

/**
 * Un uuid fijo: `fakeKey(10)` siempre da el mismo valor. Sirve para un registro que la prueba no crea,
 * o, con departmentKey() y careerKey(), para comparar contra el que sí crea.
 */
function fakeKey(int $number): string
{
    return sprintf('00000000-0000-7000-8000-%012d', $number);
}

/**
 * El id de un departamento con el uuid de fakeKey($number), que se crea la primera vez: los alcances de
 * las pruebas solo necesitan que exista, y las pruebas comparan contra fakeKey($number).
 */
function departmentKey(int $number): string
{
    return fixedKeyOf(Department::class, $number);
}

/**
 * El id de una carrera con el uuid de fakeKey($number), que se crea la primera vez (ver departmentKey()).
 */
function careerKey(int $number): string
{
    return fixedKeyOf(Career::class, $number);
}

/**
 * Crea con su factory, si no existe, el registro del modelo con el uuid de fakeKey($number), y da ese uuid.
 *
 * @param  class-string<Model>  $model
 */
function fixedKeyOf(string $model, int $number): string
{
    $id = fakeKey($number);

    if (! $model::query()->withTrashed()->whereKey($id)->exists()) {
        $model::factory()->create(['id' => $id]);
    }

    return $id;
}

/**
 * Pone una materia en la retícula de un plan (Academic, tarea 1.7): para las pruebas de retícula, progreso
 * y elegibles.
 */
function placeInPlan(StudyPlan $plan, Subject $subject, int $semester = 1, ?Specialty $specialty = null): StudyPlanSubject
{
    return StudyPlanSubject::factory()->for($plan)->for($subject)->create(['semester' => $semester, 'specialty_id' => $specialty?->id]);
}

/**
 * Seria una materia con su requisito en un plan; las dos tienen que estar en su retícula (placeInPlan()).
 */
function seriate(StudyPlan $plan, Subject $subject, Subject $prerequisite, PrerequisiteKind $kind = PrerequisiteKind::Seriacion): SubjectPrerequisite
{
    return SubjectPrerequisite::factory()->create([
        'study_plan_id' => $plan->id,
        'subject_id' => $subject->id,
        'prerequisite_subject_id' => $prerequisite->id,
        'kind' => $kind,
    ]);
}

/**
 * El HTML de una respuesta o de un componente como documento, para afirmar dentro de una parte de la
 * página (la banda, la credencial, el menú) con selectores CSS.
 */
function domOf(TestResponse|Testable|string $html): HTMLDocument
{
    $html = match (true) {
        $html instanceof TestResponse => (string) $html->getContent(),
        $html instanceof Testable => $html->html(),
        default => $html,
    };

    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/**
 * El texto de un elemento con los espacios colapsados; cadena vacía si el elemento no existe.
 */
function textOf(?Element $element): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $element?->textContent ?? ''));
}

/**
 * La bitácora escrita después del arranque de la prueba (la bitácora es inmutable: no se vacía).
 *
 * @return Builder<Activity>
 */
function activitySince(): Builder
{
    return Activity::query()->where('id', '>', test()->activityBase);
}
