<?php

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| core:provision-staff (CO-11, C8, C9): CSV usuario,nombre,foto con datos ficticios en un directorio temporal.
*/

beforeEach(function () {
    Storage::fake('staff_photos');
    config(['auth.default_password' => 'Clave-Ficticia-1']);
    $this->dir = sys_get_temp_dir().'/provision-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->unit = communicationUnit();
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

function provisionCsv(string $dir, array $rows): string
{
    $path = $dir.'/cuentas.csv';
    file_put_contents($path, "\xEF\xBB\xBFusuario,nombre,foto\n".implode("\n", $rows)."\n");

    return $path;
}

function provisionPhoto(string $dir, string $name, int $w = 600, int $h = 800, int $shade = 120): void
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, $shade, 80, 200));
    imagejpeg($image, $dir.'/'.$name);
}

function pendingStaff(array $attributes = []): User
{
    return User::factory()->withRole(Role::Personal, ['org_unit_id' => communicationUnit()->id])
        ->create(array_merge(['password' => null, 'password_origin' => null, 'must_change_password' => false], $attributes));
}

it('CO-11: activa con la contraseña por defecto y cambio obligatorio a quien no tiene contraseña', function () {
    $user = pendingStaff(['username' => 'ABCD010101XX1']);
    $csv = provisionCsv($this->dir, ['abcd010101xx1,,']);

    $this->artisan('core:provision-staff', ['file' => $csv])->assertSuccessful()->doesntExpectOutputToContain('Clave-Ficticia-1');

    $user->refresh();
    expect(Hash::check('Clave-Ficticia-1', $user->password))->toBeTrue()
        ->and($user->password_origin)->toBe(PasswordOrigin::Default)
        ->and($user->must_change_password)->toBeTrue();
    $log = Activity::query()->where('description', 'Activó la cuenta con la contraseña por defecto (comando)')->get();
    expect($log)->toHaveCount(1)
        ->and(json_encode($log->first()->properties))->not->toContain('Clave-Ficticia-1');
});

it('CO-11: una segunda corrida no vuelve a activar ni a registrar', function () {
    pendingStaff(['username' => 'U1']);
    $csv = provisionCsv($this->dir, ['U1,,']);

    $this->artisan('core:provision-staff', ['file' => $csv])->assertSuccessful();
    $this->artisan('core:provision-staff', ['file' => $csv])->assertSuccessful();

    expect(Activity::query()->where('description', 'like', 'Activó la cuenta%')->count())->toBe(1);
});

it('CO-11: no toca a quien ya eligió la suya (password_origin user)', function () {
    $user = pendingStaff(['username' => 'U2', 'password' => Hash::make('Propia-Ficticia-9'), 'password_origin' => PasswordOrigin::User]);
    $before = $user->fresh()->password;

    $this->artisan('core:provision-staff', ['file' => provisionCsv($this->dir, ['U2,,'])])->assertSuccessful();

    $user->refresh();
    expect($user->password)->toBe($before)
        ->and($user->password_origin)->toBe(PasswordOrigin::User)
        ->and($user->must_change_password)->toBeFalse();
});

it('CO-11: corrige el nombre', function () {
    $user = pendingStaff(['username' => 'U3', 'name' => 'Nombre Viejo']);

    $this->artisan('core:provision-staff', ['file' => provisionCsv($this->dir, ['U3,Nombre Nuevo Ficticio,'])])->assertSuccessful();

    expect($user->fresh()->name)->toBe('Nombre Nuevo Ficticio');
});

it('CO-11: pone la foto y una segunda corrida con la misma foto no la vuelve a subir', function () {
    $user = pendingStaff(['username' => 'U4']);
    provisionPhoto($this->dir, 'u4.jpg');
    $csv = provisionCsv($this->dir, ['U4,,u4.jpg']);

    $this->artisan('core:provision-staff', ['file' => $csv])->assertSuccessful();
    $first = $user->fresh()->photo_path;
    expect($first)->not->toBeNull();

    $this->travel(5)->seconds();
    $this->artisan('core:provision-staff', ['file' => $csv])->assertSuccessful();
    expect($user->fresh()->photo_path)->toBe($first)
        ->and(Activity::query()->where('description', 'Cambió la foto')->count())->toBe(1);

    provisionPhoto($this->dir, 'u4.jpg', 600, 800, 30);
    $this->artisan('core:provision-staff', ['file' => $csv])->assertSuccessful();
    expect($user->fresh()->photo_path)->not->toBe($first);
});

it('CO-11: --dry-run no escribe nada', function () {
    $user = pendingStaff(['username' => 'U5', 'name' => 'Nombre Viejo']);
    provisionPhoto($this->dir, 'u5.jpg');

    $this->artisan('core:provision-staff', ['file' => provisionCsv($this->dir, ['U5,Nombre Nuevo,u5.jpg']), '--dry-run' => true])
        ->assertSuccessful()->expectsOutputToContain('1 nombres corregidos, 1 fotos, 1 cuentas');

    $user->refresh();
    expect($user->name)->toBe('Nombre Viejo')
        ->and($user->password)->toBeNull()
        ->and($user->photo_path)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

it('CO-11: un usuario que no existe o es alumno es error de su fila y las demás se aplican', function () {
    $good = pendingStaff(['username' => 'U6']);
    $student = User::factory()->withRole(Role::Alumno)->create(['username' => '2027000001', 'password' => null, 'password_origin' => null]);
    $csv = provisionCsv($this->dir, ['NOEXISTE,,', '2027000001,,', 'U6,,']);

    $this->artisan('core:provision-staff', ['file' => $csv])->assertSuccessful()->expectsOutputToContain('3 filas, 2 con error');

    expect($good->fresh()->password)->not->toBeNull()
        ->and($student->fresh()->password)->toBeNull();
});

it('CO-11: avisa si la persona no tiene puesto vigente', function () {
    User::factory()->create(['username' => 'U7', 'password' => null, 'password_origin' => null]);

    $this->artisan('core:provision-staff', ['file' => provisionCsv($this->dir, ['U7,,'])])
        ->assertSuccessful()->expectsOutputToContain('U7: no tiene un puesto vigente.');
});
