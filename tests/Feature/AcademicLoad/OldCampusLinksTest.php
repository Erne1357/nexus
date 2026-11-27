<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
| Las ligas viejas de la consulta pública (`/horarios/{periodo}/{campus}/…`, de antes de que lo publicado fuera por
| modalidad, M1) se resuelven con lo publicado, sin el puente de campus a modalidad: un segmento que es la clave de una
| modalidad es esa modalidad; uno que es la clave de un campus lleva a la única modalidad con grupos publicados en ese
| campus en el periodo, con el campus como filtro (`?campus=`). Si ninguna o varias los tienen, 404. Nada se decide por
| la clave de la modalidad.
*/

/**
 * Publica una carga de la modalidad con un grupo en el campus.
 */
function publishOldLinkGroup(Term $term, Modality $modality, Campus $campus, string $department): void
{
    $load = AcademicLoad::factory()->for($term)->for($modality)
        ->for(Department::factory()->create(['code' => $department]))
        ->inStatus(LoadStatus::Published)->create();

    LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse('2026-12-12 10:30'))->withGroups([
        LoadSnapshotFactory::group([
            'code' => 'A',
            'subject' => ['id' => (string) Str::uuid7(), 'code' => "{$department}-0001", 'name' => 'Materia', 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => $load->department_id],
            'placements' => [LoadSnapshotFactory::placement(['career' => ['id' => (string) Str::uuid7(), 'code' => 'ISC', 'name' => 'Sistemas'], 'semester' => 1])],
            'campus' => ['id' => $campus->id, 'code' => $campus->code, 'name' => $campus->name],
        ]),
    ])->create();
}

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027', 'starts_on' => '2027-01-25']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    publishOldLinkGroup($this->term, modalityOf('PRES'), $this->c2, 'SC');
});

it('la clave de una modalidad en el camino es esa modalidad: no redirige', function () {
    $this->get('/horarios/20271/pres/carrera/ISC')->assertOk();
    $this->get('/horarios/20271/PRES/carrera/ISC')->assertOk();
});

it('la clave de un campus con una sola modalidad publicada ahí lleva a esa modalidad con el campus como filtro', function () {
    $this->get('/horarios/20271/C2/carrera/ISC?semestre=1')
        ->assertStatus(301)
        ->assertRedirect(route('published.semester', ['periodo' => '20271', 'modalidad' => 'pres', 'carrera' => 'ISC', 'campus' => 'C2', 'semestre' => '1']));
});

it('la modalidad sale de lo publicado, no de su clave: un campus con solo grupos de EAD lleva a EAD', function () {
    modalityOf('EAD')->update(['is_active' => true]);
    publishOldLinkGroup($this->term, modalityOf('EAD'), $this->c1, 'II');

    $this->get('/horarios/20271/C1/carrera/ISC')
        ->assertRedirect(route('published.semester', ['periodo' => '20271', 'modalidad' => 'ead', 'carrera' => 'ISC', 'campus' => 'C1']));
});

it('la clave de un campus sin grupos publicados en el periodo da 404', function () {
    $this->get('/horarios/20271/C1/carrera/ISC')->assertNotFound();
    $this->get('/horarios/20263/C2/carrera/ISC')->assertNotFound();
});

it('la clave de un campus con grupos publicados de dos modalidades da 404: la liga no dice cuál', function () {
    modalityOf('EAD')->update(['is_active' => true]);
    publishOldLinkGroup($this->term, modalityOf('EAD'), $this->c2, 'II');

    $this->get('/horarios/20271/C2/carrera/ISC')->assertNotFound();
});

it('la clave de un campus inactivo o de nada da 404', function () {
    $this->c2->update(['is_active' => false]);

    $this->get('/horarios/20271/C2/carrera/ISC')->assertNotFound();
    $this->get('/horarios/20271/ZZ/carrera/ISC')->assertNotFound();
});

it('una modalidad inactiva con grupos publicados en el campus no cuenta', function () {
    publishOldLinkGroup($this->term, modalityOf('ENL'), $this->c2, 'II');

    $this->get('/horarios/20271/C2/carrera/ISC')
        ->assertRedirect(route('published.semester', ['periodo' => '20271', 'modalidad' => 'pres', 'carrera' => 'ISC', 'campus' => 'C2']));
});
