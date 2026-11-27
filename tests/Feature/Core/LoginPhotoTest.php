<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Actions\SetStaffPhoto;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SetStudentPhoto;
use App\Modules\Students\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| La foto en la credencial del login (CO-05, tarea 1.36): la respuesta JSON trae `credential.photo` (miniatura como data:
| URL) solo tras la contraseña correcta; nunca antes ni para un usuario que se teclea. Imágenes ficticias.
*/

beforeEach(function () {
    Storage::fake('staff_photos');
    Storage::fake('student_photos');
});

it('CO-05: la respuesta JSON del login trae credential.photo como data URL tras contraseña correcta', function () {
    $person = User::factory()->create(['username' => 'maria01']);
    app(SetStaffPhoto::class)->handle($person, UploadedFile::fake()->image('f.jpg', 700, 900), communicationHead());

    $photo = $this->postJson('/login', ['username' => 'maria01', 'password' => 'password'])
        ->assertOk()
        ->json('credential.photo');

    expect($photo)->toStartWith('data:image/jpeg;base64,');
    [$width, $height] = getimagesizefromstring(base64_decode(substr($photo, strlen('data:image/jpeg;base64,'))));
    expect([$width, $height])->toBe([150, 200]);
});

it('CO-05: sin foto, credential.photo es null', function () {
    User::factory()->create(['username' => 'sinfoto']);

    $this->postJson('/login', ['username' => 'sinfoto', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('credential.photo', null);
});

it('CO-05: una contraseña incorrecta no devuelve foto ni datos', function () {
    $person = User::factory()->create(['username' => 'maria02', 'name' => 'Maria Ficticia']);
    app(SetStaffPhoto::class)->handle($person, UploadedFile::fake()->image('f.jpg', 700, 900), communicationHead());

    $response = $this->postJson('/login', ['username' => 'maria02', 'password' => 'mal'])->assertUnprocessable();

    expect($response->getContent())->not->toContain('data:image')->not->toContain('Maria Ficticia')->not->toContain('photo');
});

it('CO-05: el alumno con foto la trae en credential.photo', function () {
    $campus = Campus::factory()->create(['code' => 'C1']);
    $career = Career::factory()->for(Department::factory())->create();
    $plan = StudyPlan::factory()->for($career)->create();
    $user = User::factory()->create(['username' => '21111182']);
    $student = Student::factory()->create(['control_number' => '21111182', 'user_id' => $user->id, 'career_id' => $career->id, 'study_plan_id' => $plan->id, 'campus_id' => $campus->id]);
    app(SetStudentPhoto::class)->handle($student, UploadedFile::fake()->image('f.jpg', 700, 900), communicationStaff());

    $this->postJson('/login', ['username' => '21111182', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('credential.photo', fn (?string $photo): bool => is_string($photo) && str_starts_with($photo, 'data:image/jpeg;base64,'));
});
