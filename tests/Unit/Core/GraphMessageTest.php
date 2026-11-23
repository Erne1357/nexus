<?php

use App\Modules\Core\Mail\GraphMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/*
| Tarea 1.29 (ADR-0032): el correo de Symfony que arma Laravel se convierte en el JSON de `POST /me/sendMail` de
| Microsoft Graph: asunto, cuerpo (HTML si lo hay), Para, CC, CCO, Responder a y los adjuntos en base64 (los insertados,
| con su contentId). El remitente no va: Graph manda siempre como la cuenta conectada.
*/

it('arma asunto, cuerpo HTML, destinatarios y Responder a, y guarda el correo en Enviados', function () {
    $email = (new Email)
        ->from(new Address('otro@cdjuarez.tecnm.mx', 'Nexus'))
        ->to(new Address('ana@cdjuarez.tecnm.mx', 'Ana Ruiz'), 'beto@cdjuarez.tecnm.mx')
        ->cc('cora@cdjuarez.tecnm.mx')
        ->bcc('dani@cdjuarez.tecnm.mx')
        ->replyTo(new Address('soporte@cdjuarez.tecnm.mx', 'Soporte'))
        ->subject('Tu acceso a Nexus')
        ->text('Texto plano')
        ->html('<p>Hola</p>');

    expect(GraphMessage::payload($email))->toBe([
        'message' => [
            'subject' => 'Tu acceso a Nexus',
            'body' => ['contentType' => 'HTML', 'content' => '<p>Hola</p>'],
            'toRecipients' => [
                ['emailAddress' => ['address' => 'ana@cdjuarez.tecnm.mx', 'name' => 'Ana Ruiz']],
                ['emailAddress' => ['address' => 'beto@cdjuarez.tecnm.mx']],
            ],
            'ccRecipients' => [['emailAddress' => ['address' => 'cora@cdjuarez.tecnm.mx']]],
            'bccRecipients' => [['emailAddress' => ['address' => 'dani@cdjuarez.tecnm.mx']]],
            'replyTo' => [['emailAddress' => ['address' => 'soporte@cdjuarez.tecnm.mx', 'name' => 'Soporte']]],
        ],
        'saveToSentItems' => true,
    ]);
});

it('sin HTML manda el texto plano y deja fuera las listas vacías', function () {
    $email = (new Email)->from('nexus@cdjuarez.tecnm.mx')->to('ana@cdjuarez.tecnm.mx')->subject('Aviso')->text('Solo texto');

    expect(GraphMessage::payload($email)['message'])->toBe([
        'subject' => 'Aviso',
        'body' => ['contentType' => 'Text', 'content' => 'Solo texto'],
        'toRecipients' => [['emailAddress' => ['address' => 'ana@cdjuarez.tecnm.mx']]],
    ]);
});

it('manda cada adjunto como fileAttachment en base64, y el insertado con el contentId que cita el HTML', function () {
    $logo = (new DataPart('png-bytes', 'logo.png', 'image/png'))->asInline();
    $cid = $logo->getContentId();

    $email = (new Email)
        ->from('nexus@cdjuarez.tecnm.mx')
        ->to('ana@cdjuarez.tecnm.mx')
        ->subject('Con adjuntos')
        ->html('<img src="cid:'.$cid.'">')
        ->addPart($logo)
        ->attach('a,b', 'datos.csv', 'text/csv');

    expect(GraphMessage::payload($email)['message']['attachments'])->toBe([
        [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => 'logo.png',
            'contentType' => 'image/png',
            'contentBytes' => base64_encode('png-bytes'),
            'isInline' => true,
            'contentId' => $cid,
        ],
        [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => 'datos.csv',
            'contentType' => 'text/csv',
            'contentBytes' => base64_encode('a,b'),
            'isInline' => false,
        ],
    ]);
});

it('un insertado sin contentId propio se cita por su nombre, como lo deja Symfony en el HTML', function () {
    $email = (new Email)
        ->from('nexus@cdjuarez.tecnm.mx')
        ->to('ana@cdjuarez.tecnm.mx')
        ->subject('Logo')
        ->html('<img src="cid:logo.png">')
        ->addPart((new DataPart('png-bytes', 'logo.png', 'image/png'))->asInline());

    expect(GraphMessage::payload($email)['message']['attachments'][0]['contentId'])->toBe('logo.png');
});

it('lee un cuerpo que llega como flujo', function () {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, '<p>Del flujo</p>');
    rewind($stream);

    $email = (new Email)->from('nexus@cdjuarez.tecnm.mx')->to('ana@cdjuarez.tecnm.mx')->subject('Flujo')->html($stream);

    expect(GraphMessage::payload($email)['message']['body'])->toBe(['contentType' => 'HTML', 'content' => '<p>Del flujo</p>']);
});
