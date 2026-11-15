<?php

use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\MailKinds;
use Livewire\Livewire;

/*
| CF-S8: «Correos y colas» muestra el tipo de cada correo en español, no el nombre de la clase que lo mandó. Toda
| notificación de los módulos que sale por correo (con `toMail()`) tiene su nombre; un tipo desconocido sale tal cual.
*/

it('CF-S8: toda notificación que sale por correo tiene su nombre en español', function () {
    $root = base_path('app/Modules');
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getPathname(), '.php') && str_contains(file_get_contents($file->getPathname()), 'function toMail(')) {
            $classes[] = $file->getBasename('.php');
        }
    }

    expect($classes)->toContain('AccessInvitation', 'PasswordResetLink')
        ->and(array_values(array_filter($classes, fn (string $class): bool => ! MailKinds::knows($class))))->toBe([]);
});

it('CF-S8: la tabla y el filtro de tipo nombran el correo en español', function () {
    MailLog::factory()->create(['kind' => 'AccessInvitation', 'subject' => 'Tu acceso a Nexus']);
    MailLog::factory()->create(['kind' => 'OtroCorreo', 'subject' => 'Algo más']);

    Livewire::actingAs(User::factory()->admin()->create())->test('core::pages.mail-and-queues')
        ->assertViewHas('kinds', [['id' => 'AccessInvitation', 'name' => 'Invitación de acceso'], ['id' => 'OtroCorreo', 'name' => 'OtroCorreo']])
        ->assertSeeInOrder(['Tu acceso a Nexus', 'Invitación de acceso'])
        ->assertDontSee('>AccessInvitation<', false);
});
