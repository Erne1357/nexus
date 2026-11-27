<?php

use App\Modules\Academic\Models\Subject;
use Spatie\Activitylog\Models\Activity;

/*
| PL-02 (tarea 1.73): el comando de una sola vez `academic:exclude-teacher-hours --nombre=…` marca que no cuentan en la
| carga del docente las materias cuyo nombre contiene el texto, sin importar acentos ni mayúsculas. El texto llega por el
| argumento; con `--dry-run` solo cuenta; es idempotente.
*/

beforeEach(function () {
    $this->tutorias = Subject::factory()->create(['code' => 'TUT-2024', 'name' => 'Tutorías']);
    $this->tutoria = Subject::factory()->create(['code' => 'TCT', 'name' => 'TUTORIA']);
    $this->other = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación']);
});

it('PL-02: con --dry-run dice qué materias marcaría y no escribe', function () {
    $this->artisan('academic:exclude-teacher-hours', ['--nombre' => 'tutori', '--dry-run' => true])
        ->expectsOutputToContain('Marcaría 2 materias; 0 ya no contaban en la carga del docente.')
        ->expectsOutputToContain('TCT · TUTORIA')
        ->expectsOutputToContain('TUT-2024 · Tutorías')
        ->assertSuccessful();

    expect(Subject::query()->where('counts_teacher_hours', false)->count())->toBe(0);
});

it('PL-02: marca las que coinciden sin importar acentos ni mayúsculas, con bitácora, y al repetir no cambia nada', function () {
    $this->artisan('academic:exclude-teacher-hours', ['--nombre' => 'TUTORÍ'])
        ->expectsOutputToContain('Marcó 2 materias; 0 ya no contaban en la carga del docente.')
        ->assertSuccessful();

    expect($this->tutorias->fresh()->counts_teacher_hours)->toBeFalse()
        ->and($this->tutoria->fresh()->counts_teacher_hours)->toBeFalse()
        ->and($this->other->fresh()->counts_teacher_hours)->toBeTrue()
        ->and(Activity::query()->whereIn('subject_id', [$this->tutorias->id, $this->tutoria->id])->count())->toBe(2);

    $this->artisan('academic:exclude-teacher-hours', ['--nombre' => 'tutori'])
        ->expectsOutputToContain('Marcó 0 materias; 2 ya no contaban en la carga del docente.')
        ->assertSuccessful();

    expect(Activity::query()->whereIn('subject_id', [$this->tutorias->id, $this->tutoria->id])->count())->toBe(2);
});

it('PL-02: sin al menos 4 letras en --nombre no marca nada', function () {
    $this->artisan('academic:exclude-teacher-hours', ['--nombre' => 'tu'])->assertFailed();
    $this->artisan('academic:exclude-teacher-hours')->assertFailed();

    expect(Subject::query()->where('counts_teacher_hours', false)->count())->toBe(0);
});
