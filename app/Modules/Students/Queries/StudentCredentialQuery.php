<?php

namespace App\Modules\Students\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Queries\CredentialQuery;
use App\Modules\Students\Models\Student;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * La credencial del alumno (pestaña «Credencial» de «Mis datos», AC-15): nombre, número de control, carrera, campus, el
 * periodo activo y las iniciales que ocupan el lugar de la foto. Las iniciales y el periodo salen de CredentialQuery, la
 * credencial de la banda y del login, para que las tres digan lo mismo. Lleva también el código QR en SVG con el número de
 * control, y nada más (ADR-0031). Foto: Nexus no tiene fuente de la foto del alumno, así que `photo` es null y la vista
 * muestra las iniciales (AC-15).
 */
final class StudentCredentialQuery
{
    public function __construct(private readonly CredentialQuery $credentials) {}

    /**
     * @return array{name: string, control_number: string, career: string, campus: string, term: ?string, initials: string, photo: null, qr: string}
     */
    public function of(Actor $context, Student $student): array
    {
        $credential = $this->credentials->of($context);

        return [
            'name' => $student->name,
            'control_number' => $student->control_number,
            'career' => $student->career->name,
            'campus' => $student->campus->name,
            'term' => $credential['term'],
            'initials' => $this->credentials->initials($student->name),
            'photo' => null,
            'qr' => $this->qr($student->control_number),
        ];
    }

    /**
     * El SVG en línea de un código QR que codifica solo `$text`, sin la declaración XML para poder incrustarlo en la página.
     */
    public function qr(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(160, 0), new SvgImageBackEnd)))->writeString($text);

        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }
}
