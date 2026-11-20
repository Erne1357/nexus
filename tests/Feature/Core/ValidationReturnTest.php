<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\ValidationReturn;
use App\Modules\Faculty\Models\Teacher;
use Livewire\Livewire;

/*
| «Regresar» a la validación de catálogos (tarea 1.24.3): las páginas a las que se entra desde ella (ficha del docente,
| disponibilidad, plan, carreras, materias, salones y docentes) vuelven al departamento y catálogo de donde se salió. La
| marca es `?desde=validacion` con `depto` (uuid) y `cat` (lista fija); el destino se arma con route(), nunca con una URL
| de la query (open redirect). Sin la marca, o con una desconocida, cada página conserva su regreso de siempre.
*/

beforeEach(function () {
    Term::factory()->current()->create();
    $this->admin = User::factory()->admin()->create();
    $this->dept = Department::factory()->create();
    $this->teacher = Teacher::factory()->adscribedTo($this->dept)->create(['name' => 'Ana Regreso']);
    $this->back = ValidationReturn::params($this->dept->id, 'teachers');
    $this->expected = route('catalog-validation.review', ['department' => $this->dept->id, 'catalogo' => 'teachers']);
});

function backPages(): array
{
    return [
        'ficha del docente' => fn () => route('faculty.teachers.show', test()->teacher->id),
        'disponibilidad' => fn () => route('faculty.teachers.availability', test()->teacher->id),
        'plan' => fn () => route('academic.plans.show', StudyPlan::factory()->create()->id),
        'carreras' => fn () => route('academic.careers'),
        'materias' => fn () => route('academic.subjects'),
        'salones' => fn () => route('facilities.rooms'),
        'docentes' => fn () => route('faculty.teachers'),
    ];
}

describe('la lista de catálogos', function () {
    it('coincide con los valores del enum Catalog de la validación', function () {
        expect(ValidationReturn::CATALOGS)->toEqualCanonicalizing(array_map(fn (Catalog $catalog): string => $catalog->value, Catalog::cases()));
    });
});

describe('la ficha conserva el regreso hacia la disponibilidad', function () {
    it('el enlace «Editar disponibilidad» de la ficha lleva desde, depto y cat', function () {
        $expected = route('faculty.teachers.availability', ['teacher' => $this->teacher->id, ...$this->back]);

        $html = Livewire::withQueryParams($this->back)->actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
            ->set('tab', 'disponibilidad')->html();

        expect(str_contains($html, 'href="'.e($expected).'"') || str_contains($html, 'href="'.$expected.'"'))->toBeTrue();
    });

    it('sin la marca el enlace queda como siempre', function () {
        Livewire::actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
            ->set('tab', 'disponibilidad')
            ->assertSeeHtml('href="'.e(route('faculty.teachers.availability', $this->teacher->id)).'"');
    });
});

describe('con la marca de la validación', function () {
    it('muestra «Regresar» a la validación, al mismo departamento y catálogo', function (string $page) {
        $url = backPages()[$page]().'?'.http_build_query($this->back);

        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertSeeHtml('data-testid="back-to-validation"')
            ->assertSeeHtml('href="'.e($this->expected).'"')
            ->assertSee('Regresar');
    })->with(array_keys(backPages()));

    it('CV15: desde una revisión de Servicios Escolares, «Regresar» vuelve a la de Servicios Escolares', function () {
        $url = route('academic.subjects').'?'.http_build_query(ValidationReturn::params(ValidationReturn::SCHOOL_SERVICES, 'subjects'));

        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertSeeHtml('href="'.e(route('catalog-validation.review', ['department' => 'servicios-escolares', 'catalogo' => 'subjects'])).'"');
        expect(ValidationReturn::url('validacion', 'servicios-escolares', 'careers'))->toBe(route('catalog-validation.review', ['department' => 'servicios-escolares', 'catalogo' => 'careers']))
            ->and(ValidationReturn::url('validacion', 'servicios-escolares-x', 'careers'))->toBeNull()
            ->and(ValidationReturn::url('validacion', 'SERVICIOS-ESCOLARES', 'careers'))->toBeNull();
    });

    it('la ruta de regreso es la de la validación y no se arma con texto de la query', function () {
        expect(ValidationReturn::url('validacion', $this->dept->id, 'rooms'))->toBe(route('catalog-validation.review', ['department' => $this->dept->id, 'catalogo' => 'rooms']));
    });
});

describe('sin la marca o con una desconocida', function () {
    it('conserva el regreso de siempre', function (string $page) {
        $this->actingAs($this->admin)->get(backPages()[$page]())->assertOk()->assertDontSeeHtml('data-testid="back-to-validation"');
    })->with(array_keys(backPages()));

    it('un `desde` desconocido se ignora', function (string $page) {
        $url = backPages()[$page]().'?'.http_build_query([...$this->back, 'desde' => 'otro']);

        $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSeeHtml('data-testid="back-to-validation"');
    })->with(array_keys(backPages()));

    it('una URL cruda en la query nunca llega al enlace (sin open redirect)', function () {
        $url = route('faculty.teachers.show', $this->teacher->id).'?'.http_build_query(['desde' => 'validacion', 'depto' => 'https://malo.example/robo', 'cat' => 'https://malo.example/robo']);

        $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSeeHtml('href="https://malo.example')->assertDontSeeHtml('data-testid="back-to-validation"');
        expect(ValidationReturn::url('validacion', 'https://malo.example/robo', 'teachers'))->toBeNull()
            ->and(ValidationReturn::url('https://malo.example', $this->dept->id, 'teachers'))->toBeNull();
    });

    it('un catálogo fuera de la lista se ignora y queda el departamento', function () {
        expect(ValidationReturn::url('validacion', $this->dept->id, '//malo.example'))->toBe(route('catalog-validation.review', ['department' => $this->dept->id]));
    });

    it('la ficha vuelve a Docentes y la disponibilidad, a la ficha', function () {
        $this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->teacher->id))->assertSee('Docentes');
        $this->actingAs($this->admin)->get(route('faculty.teachers.availability', $this->teacher->id))->assertSee('Ana Regreso');
    });
});

describe('después de una acción de Livewire', function () {
    it('la ficha sigue ofreciendo «Regresar» a la validación', function () {
        Livewire::withQueryParams($this->back)->actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
            ->assertSeeHtml('data-testid="back-to-validation"')
            ->set('tab', 'plazas')
            ->assertSeeHtml('href="'.e($this->expected).'"');
    });
});

describe('alcance (los permisos de cada página no cambian)', function () {
    it('quien no ve la ficha sigue sin verla aunque venga de la validación', function () {
        $otro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

        $this->actingAs($otro)->get(route('faculty.teachers.show', $this->teacher->id).'?'.http_build_query($this->back))->assertForbidden();
    });
});
