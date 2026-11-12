<?php

use App\Modules\Core\Actions\RemoveTermStage;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
| RemoveTermStage borra físicamente la ventana: term_stages no es catálogo.
*/

it('borra físicamente la ventana, deja en la bitácora la quitada y avisa', function () {
    Event::fake([TermCalendarChanged::class]);
    $term = Term::factory()->create(['code' => '20271']);
    $modality = Modality::factory()->create(['code' => 'M002', 'name' => 'Modalidad dos']);
    $window = TermStage::factory()->for($term)->create(['modality_id' => $modality->id]);

    app(RemoveTermStage::class)->handle($window);

    $entry = Activity::sole();

    expect(TermStage::count())->toBe(0)
        ->and($entry->event)->toBe('deleted')
        ->and($entry->description)->toBe('Quitó la etapa Propuesta por carrera (de Modalidad dos) del periodo 20271')
        ->and($entry->attribute_changes['old'])->toBe([
            'stage' => 'proposal', 'modality_id' => $modality->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-13',
        ]);
    Event::assertDispatched(TermCalendarChanged::class, fn ($event) => $event->term->is($term) && $event->modality?->is($modality));
});

it('quita la general sin tocar la de una modalidad', function () {
    $term = Term::factory()->create();
    $modality = Modality::factory()->create();
    $general = TermStage::factory()->for($term)->create();
    TermStage::factory()->for($term)->create(['modality_id' => $modality->id]);

    app(RemoveTermStage::class)->handle($general);

    expect(TermStage::sole()->modality_id)->toBe($modality->id);
});
