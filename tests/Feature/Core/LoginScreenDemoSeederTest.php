<?php

use App\Modules\Core\Database\Seeders\LoginScreenDemoSeeder;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

/*
| LoginScreenDemoSeeder: avisos y atajos de ejemplo del login, solo con la foto del edificio del ITCJ.
*/

it('siembra los avisos y atajos de ejemplo sin duplicarlos y copia la foto del edificio', function () {
    Storage::fake('public');
    $seeder = (new DatabaseSeeder)->setContainer(app());

    $seeder->call(LoginScreenDemoSeeder::class);
    $seeder->call(LoginScreenDemoSeeder::class);

    expect(LoginNotice::query()->count())->toBe(2)
        ->and(LoginNotice::query()->whereNotNull('image_path')->pluck('image_path')->all())->toBe([LoginScreenDemoSeeder::PHOTO])
        ->and(LoginShortcut::query()->count())->toBe(2);
    Storage::disk('public')->assertExists(LoginScreenDemoSeeder::PHOTO);
});

it('no vuelve a crear un aviso de ejemplo que se borró', function () {
    Storage::fake('public');
    $seeder = (new DatabaseSeeder)->setContainer(app());
    $seeder->call(LoginScreenDemoSeeder::class);
    LoginNotice::query()->where('title', 'Vacaciones de invierno')->first()->delete();

    $seeder->call(LoginScreenDemoSeeder::class);

    expect(LoginNotice::withTrashed()->where('title', 'Vacaciones de invierno')->count())->toBe(1);
});
