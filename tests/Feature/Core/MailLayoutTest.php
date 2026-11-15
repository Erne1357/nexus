<?php

use App\Modules\Core\Notifications\AccessInvitation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;

/*
| La forma de todo correo de Nexus (tarea 1.21): las plantillas de correo de Laravel publicadas en
| resources/views/vendor, con la banda de Nexus y el nombre del ITCJ, los colores de DESIGN.md y el texto de tú, también
| en el pie con el enlace por si el botón no funciona.
*/

it('todo correo lleva la banda de Nexus con el nombre del ITCJ y la franja roja, con los colores de DESIGN.md', function () {
    $html = (string) (new MailMessage)->line('Texto del aviso.')->render();

    expect($html)
        ->toContain('Instituto Tecnológico de Ciudad Juárez')
        ->toContain('background-color: #092d48')
        ->toContain('background-color: #c2201e')
        ->toContain('el sistema académico del Instituto Tecnológico de Ciudad Juárez')
        ->not->toContain('All rights reserved')
        ->not->toContain('Todos los derechos reservados');
});

it('habla de tú: saluda, firma y, al pie, dice qué hacer si el botón no funciona', function () {
    $html = html_entity_decode((string) (new MailMessage)->line('Texto del aviso.')->action('Ir a validar', 'https://nexus.test/validar')->render());

    expect($html)
        ->toContain('Hola')
        ->toContain('Saludos,')
        ->toContain('Si el botón «Ir a validar» no funciona, copia este enlace en tu navegador:')
        ->toContain('https://nexus.test/validar')
        ->not->toContain('copie')
        ->not->toContain('Regards')
        ->not->toContain('Hello');
});

it('AC-07: la invitación sale con esa forma: el usuario, el botón «Activar mi acceso» y el enlace al pie', function () {
    $invitation = new AccessInvitation('ABCD800101XX1', 'Ana Pérez', 'https://nexus.test/access/x', now()->addDays(7)->toImmutable());
    $html = html_entity_decode((string) $invitation->toMail(new AnonymousNotifiable)->render());

    expect($html)
        ->toContain('Hola, Ana Pérez')
        ->toContain('ABCD800101XX1')
        ->toContain('Activar mi acceso')
        ->toContain('Si el botón «Activar mi acceso» no funciona, copia este enlace en tu navegador:')
        ->toContain('Instituto Tecnológico de Ciudad Juárez');
});
