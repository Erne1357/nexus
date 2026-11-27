<?php

use App\Modules\Faculty\Actions\UpdatePosition;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;

it('PL-01: UpdatePosition con solo el tipo nuevo deja la categoría vacía y sin solo las horas la conserva', function () {
    $plaza = TeacherPosition::factory()->for(Teacher::factory()->create())->tiempoCompleto()->create(['category' => 'E3817']);

    app(UpdatePosition::class)->handle($plaza, ['base_hours' => 40]);
    expect($plaza->fresh()->category)->toBe('E3817');

    app(UpdatePosition::class)->handle($plaza, ['kind' => 'medio_tiempo', 'base_hours' => 20]);
    expect($plaza->fresh())->kind->value->toBe('medio_tiempo')->category->toBeNull();
});
