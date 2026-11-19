<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| El drawer de sugerencias de «Re-análisis de la carga» (tarea 8.3a): el jefe del departamento ve, edita, aplica y
| descarta las sugerencias de una materia en un campus (RA-07). Subdirección las ve sin aplicarlas; el coordinador y el
| jefe de otro departamento no las abren ni las aplican. El mundo: Cálculo Integral en C2 con ISIC faltante (70
| esperados; el grupo A de 40 lugares en un salón de 50) y en C1 con ISIC sobrante (30 contra el grupo D de 45).
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->jefe = $this->world->head();
    $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 70]);
    $this->a = $this->world->groups['A'];
    $this->expandKey = "expand_quota:{$this->a->id}:{$this->world->isc->id}";
    $this->openKey = "open_group:{$this->world->isc->id}";
});

function suggestionsDrawer(User $user, ReanalysisWorld $world, ?Campus $campus = null): Testable
{
    return Livewire::actingAs($user)
        ->test('scheduling::components.reanalysis-suggestions', ['loadId' => $world->load->id])
        ->dispatch('open-reanalysis-suggestions', subjectId: $world->integral->id, campusId: ($campus ?? $world->c2)->id);
}

/**
 * Las llaves de las sugerencias a la vista, en orden.
 *
 * @return list<string>
 */
function suggestionKeysShown(Testable $drawer): array
{
    return array_map(fn ($item): string => $item->getAttribute('data-key'), iterator_to_array(domOf($drawer)->querySelectorAll('[data-testid="suggestion"]')));
}

describe('jefe del departamento', function () {
    it('ve cada sugerencia con su tipo, su evidencia y su efecto, los lugares de ahora y los grupos', function () {
        $drawer = suggestionsDrawer($this->jefe, $this->world)
            ->assertSee('Ampliar el cupo de ISIC en el grupo A de 40 a 50')
            ->assertSee('ISIC: 70 esperados · 40 lugares · faltan 30')
            ->assertSee('La capacidad del grupo A sube de 40 a 50, dentro de su salón (HC-05, HC-07).')
            ->assertSee('Abrir un grupo de ACF-0902 para ISIC')
            ->assertSee('Sin docente: asígnalo en Asignación docente.')
            ->assertSee('Grupos de ahora')
            ->assertDontSeeHtml('data-testid="suggestions-readonly"');

        $open = domOf($drawer)->querySelector("[data-key=\"{$this->openKey}\"]");

        expect(suggestionKeysShown($drawer))->toBe([$this->expandKey, $this->openKey])
            ->and($open->querySelector('[data-testid="suggestion-apply"]'))->toBeNull()
            ->and($open->querySelector('[data-testid="suggestion-find"]'))->not->toBeNull()
            ->and(textOf(domOf($drawer)->querySelector('[data-testid="suggestions-now"][data-career="ISIC"]')))->toBe('40');
    });

    it('aplica «Ampliar cupo»: la sugerencia se va, los lugares de ahora suben y avisa a la página', function () {
        $drawer = suggestionsDrawer($this->jefe, $this->world)
            ->call('apply', $this->expandKey)
            ->assertDispatched('reanalysis-adjusted');

        expect(lastToastOf($drawer)['title'])->toBe('Sugerencia aplicada')
            ->and($this->a->fresh()->capacity)->toBe(50)
            ->and(suggestionKeysShown($drawer))->toBe([$this->openKey])
            ->and(textOf(domOf($drawer)->querySelector('[data-testid="suggestions-now"][data-career="ISIC"]')))->toBe('50, ya ajustado');
    });

    it('edita cuántos lugares suma antes de aplicar, de 1 al máximo', function () {
        $drawer = suggestionsDrawer($this->jefe, $this->world)
            ->call('edit', $this->expandKey)
            ->assertSet('editSeats', 10)
            ->assertSeeHtml('data-testid="suggestion-edit"')
            ->set('editSeats', 11)
            ->call('apply', $this->expandKey)
            ->assertHasErrors(['editSeats' => 'max']);

        expect($this->a->fresh()->capacity)->toBe(40);

        $drawer->set('editSeats', 4)->call('apply', $this->expandKey)->assertHasNoErrors()->assertSet('editing', null);

        expect($this->a->fresh()->capacity)->toBe(44)
            ->and(GroupQuota::query()->where('group_id', $this->a->id)->value('max_seats'))->toBe(44);
    });

    it('aplica «Cambiar salón» y «Cerrar grupo» (este con la confirmación de Nexus)', function () {
        $room = Room::factory()->capacity(70)->create(['building_id' => Building::factory()->create(['campus_id' => $this->world->c2->id])->id, 'code' => 'L-70']);

        suggestionsDrawer($this->jefe, $this->world)->call('apply', "change_room:{$this->a->id}:{$this->world->isc->id}");

        expect(GroupSession::query()->where('group_id', $this->a->id)->value('room_id'))->toBe($room->id);

        $e = $this->world->group($this->world->integral, $this->world->isc, 20, $this->world->c1, 2, day: 2);
        $teacher = $e->teacher->name;
        $drawer = suggestionsDrawer($this->jefe, $this->world, $this->world->c1)
            ->assertSee("Primero quita a {$teacher} del grupo {$e->code} en Asignación docente; luego lo puedes cerrar.");

        expect(domOf($drawer)->querySelector("[data-key=\"close_group:{$e->id}\"] [data-testid=\"suggestion-apply\"]")->hasAttribute('disabled'))->toBeTrue();

        $e->update(['teacher_id' => null]);
        $drawer = suggestionsDrawer($this->jefe, $this->world, $this->world->c1);
        $apply = domOf($drawer)->querySelector("[data-key=\"close_group:{$e->id}\"] [data-testid=\"suggestion-apply\"]");

        expect($apply->hasAttribute('disabled'))->toBeFalse()
            ->and($apply->getAttribute('data-confirm-title'))->toBe("¿Cerrar el grupo {$e->code}?")
            ->and($apply->getAttribute('data-confirm-tone'))->toBe('error')
            ->and($apply->getAttribute('x-confirm'))->toContain("El grupo {$e->code} se cancela");

        $drawer->call('apply', "close_group:{$e->id}");

        expect($e->fresh()->status)->toBe(GroupStatus::Cancelled);
    });

    it('descartar solo esconde la sugerencia en la pantalla, sin guardar nada', function () {
        $drawer = suggestionsDrawer($this->jefe, $this->world)->call('discard', $this->expandKey);

        expect(suggestionKeysShown($drawer))->toBe([$this->openKey])
            ->and($this->a->fresh()->capacity)->toBe(40);

        $drawer->assertSee('Volver a mostrar la descartada')->call('restoreDiscarded');

        expect(suggestionKeysShown($drawer))->toBe([$this->expandKey, $this->openKey])
            ->and(suggestionKeysShown(suggestionsDrawer($this->jefe, $this->world)))->toBe([$this->expandKey, $this->openKey]);
    });

    it('una sugerencia que ya no aplica o una carga fuera de re-análisis no cambian nada y lo dicen', function () {
        $drawer = suggestionsDrawer($this->jefe, $this->world)->call('apply', 'expand_quota:otro:grupo');

        expect(lastToastOf($drawer)['description'])->toContain('Esa sugerencia ya no aplica');

        $this->world->load->update(['status' => LoadStatus::Closed]);
        $drawer = suggestionsDrawer($this->jefe, $this->world)
            ->assertSee('La carga ya no está en re-análisis: las sugerencias ya no se aplican.')
            ->call('apply', $this->expandKey);

        expect(lastToastOf($drawer)['title'])->toBe('No se aplicó la sugerencia')
            ->and($this->a->fresh()->capacity)->toBe(40);
    });

    it('RA-09: el bloque de primer semestre no da sugerencias y lo dice', function () {
        Livewire::actingAs($this->jefe)
            ->test('scheduling::components.reanalysis-suggestions', ['loadId' => $this->world->load->id])
            ->dispatch('open-reanalysis-suggestions', subjectId: $this->world->differential->id, campusId: $this->world->c2->id)
            ->assertSee('Primer semestre: no se clasifica')
            ->assertDontSeeHtml('data-testid="suggestion"');
    });

    it('sin sugerencias, dice que la oferta de ahora está dentro del umbral', function () {
        DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 42]);

        suggestionsDrawer($this->jefe, $this->world)->assertSee('Sin sugerencias')->assertSee('dentro del 20 %');
    });
});

describe('quien no ajusta (RA-07)', function () {
    it('Subdirección ve las sugerencias de solo consulta y no las aplica, edita ni descarta', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        $drawer = suggestionsDrawer($subdireccion, $this->world)
            ->assertSee('Solo consulta: los ajustes los hace la jefatura de Ciencias Básicas.');
        $apply = domOf($drawer)->querySelector("[data-key=\"{$this->expandKey}\"] [data-testid=\"suggestion-apply\"]");

        expect($apply->hasAttribute('disabled'))->toBeTrue();

        suggestionsDrawer($subdireccion, $this->world)->call('apply', $this->expandKey)->assertForbidden();
        suggestionsDrawer($subdireccion, $this->world)->call('edit', $this->expandKey)->assertForbidden();
        suggestionsDrawer($subdireccion, $this->world)->call('discard', $this->expandKey)->assertForbidden();

        expect($this->a->fresh()->capacity)->toBe(40);
    });

    it('el coordinador no abre el drawer ni aplica', function () {
        $coordinator = $this->world->coordinator($this->world->isc);

        suggestionsDrawer($coordinator, $this->world)->assertForbidden();

        $drawer = suggestionsDrawer($this->jefe, $this->world);
        $this->actingAs($coordinator);
        $drawer->call('apply', $this->expandKey)->assertForbidden();

        expect($this->a->fresh()->capacity)->toBe(40);
    });

    it('el jefe de otro departamento no aplica sobre un drawer que abrió el jefe', function () {
        $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();
        $drawer = suggestionsDrawer($this->jefe, $this->world);
        $this->actingAs($other);

        $drawer->call('apply', $this->expandKey)->assertForbidden();

        expect($this->a->fresh()->capacity)->toBe(40);
    });
});
