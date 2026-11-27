<?php

namespace App\Modules\Core\Mail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Convierte el correo de Symfony que arma Laravel en el cuerpo de `POST /me/sendMail` de Microsoft Graph (tarea 1.29,
 * ADR-0032): asunto; el cuerpo en HTML si lo hay y, si no, el texto (Graph lleva uno solo); Para, CC, CCO y Responder
 * a; y cada adjunto como `#microsoft.graph.fileAttachment` en base64, el insertado con el `contentId` que cita el HTML
 * (`cid:`). El remitente no va: Graph manda siempre como la cuenta del token, y otro `from` le pediría «enviar como». El
 * correo queda en Enviados de esa cuenta. Graph acepta hasta 4 MB por petición; los correos de Nexus no llevan
 * adjuntos grandes.
 */
final class GraphMessage
{
    /**
     * @return array{message: array<string, mixed>, saveToSentItems: true}
     */
    public static function payload(Email $email): array
    {
        $html = self::text($email->getHtmlBody());

        $message = array_filter([
            'subject' => (string) $email->getSubject(),
            'body' => $html !== null
                ? ['contentType' => 'HTML', 'content' => $html]
                : ['contentType' => 'Text', 'content' => (string) self::text($email->getTextBody())],
            'toRecipients' => self::recipients($email->getTo()),
            'ccRecipients' => self::recipients($email->getCc()),
            'bccRecipients' => self::recipients($email->getBcc()),
            'replyTo' => self::recipients($email->getReplyTo()),
            'attachments' => array_map(self::attachment(...), $email->getAttachments()),
        ], fn (mixed $value): bool => $value !== []);

        return ['message' => $message, 'saveToSentItems' => true];
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{emailAddress: array{address: string, name?: string}}>
     */
    private static function recipients(array $addresses): array
    {
        return array_map(
            fn (Address $address): array => ['emailAddress' => array_filter(['address' => $address->getAddress(), 'name' => $address->getName()], fn (string $value): bool => $value !== '')],
            $addresses,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function attachment(DataPart $part): array
    {
        $inline = $part->getDisposition() === 'inline';

        return [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $part->getFilename() ?? 'adjunto',
            'contentType' => $part->getContentType(),
            'contentBytes' => base64_encode($part->getBody()),
            'isInline' => $inline,
            ...($inline ? ['contentId' => $part->hasContentId() ? $part->getContentId() : ($part->getFilename() ?? $part->getContentId())] : []),
        ];
    }

    /**
     * @param  resource|string|null  $body
     */
    private static function text(mixed $body): ?string
    {
        if (is_resource($body)) {
            if (stream_get_meta_data($body)['seekable']) {
                rewind($body);
            }

            return (string) stream_get_contents($body);
        }

        return $body;
    }
}
