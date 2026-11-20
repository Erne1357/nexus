<?php

namespace App\Modules\Students\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Queries\CredentialQuery;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Support\CredentialToken;
use App\Modules\Students\Support\StudentPhotos;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

/**
 * La credencial del alumno (pestaña «Credencial» de «Mis datos», AC-15 y AC-20; tarea 1.27): el frente de la plantilla
 * institucional con su foto y tres líneas («Nombre / número de control», «Carrera / Estudiante o Egresado» e «Instituto
 * Tecnológico de Ciudad Juárez») y el reverso con el QR que cambia cada 30 s (`qrFor`). La foto sale del disco
 * `student_photos` (StudentPhotos); sin foto, la vista pone las iniciales.
 */
final class StudentCredentialQuery
{
    public const string INSTITUTION = 'Instituto Tecnológico de Ciudad Juárez';

    public function __construct(
        private readonly CredentialQuery $credentials,
        private readonly StudentPhotos $photos,
        private readonly CredentialToken $tokens,
    ) {}

    /**
     * @return array{id: string, name: string, control_number: string, career: string, campus: string, term: ?string, initials: string, status: ?string, current: bool, has_photo: bool}
     */
    public function of(Actor $context, Student $student): array
    {
        return [
            'id' => (string) $student->getKey(),
            'name' => $student->name,
            'control_number' => $student->control_number,
            'career' => self::shortCareer((string) $student->career?->name),
            'campus' => (string) $student->campus?->name,
            'term' => $this->credentials->of($context)['term'],
            'initials' => $this->credentials->initials($student->name),
            'status' => self::statusLabel($student->status),
            'current' => self::isCurrent($student->status),
            'has_photo' => $this->photos->locate($student->control_number) !== null,
        ];
    }

    /**
     * El QR del reverso para el instante `$now`: la URL pública de verificación con el token de esa ventana (AC-20), el
     * SVG en línea y los segundos que le quedan antes de cambiar.
     *
     * @return array{url: string, svg: string, window: int, seconds_left: int}
     */
    public function qrFor(Student $student, int $now): array
    {
        $url = route('credential.verify', ['token' => $this->tokens->issue((string) $student->getKey(), $now)]);

        return ['url' => $url, 'svg' => $this->qr($url), 'window' => CredentialToken::window($now), 'seconds_left' => CredentialToken::secondsLeft($now)];
    }

    /**
     * El SVG en línea de un código QR que codifica solo `$text`, sin la declaración XML para poder incrustarlo en la página.
     */
    public function qr(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd)))->writeString($text);

        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }

    /**
     * La carrera como la escribe la plantilla: «Ingeniería en Sistemas Computacionales» → «Ing. Sistemas Computacionales»,
     * «Licenciatura en Administración» → «Lic. Administración». Las demás no cambian.
     */
    public static function shortCareer(string $name): string
    {
        return (string) Str::of($name)->replaceMatches('/^Ingeniería(\s+en)?\s+/u', 'Ing. ')->replaceMatches('/^Licenciatura(\s+en)?\s+/u', 'Lic. ');
    }

    /**
     * «Estudiante» al alumno activo y «Egresado» al egresado (confirmado el 20 de noviembre de 2026); los demás no tienen
     * credencial vigente.
     */
    public static function statusLabel(?StudentStatus $status): ?string
    {
        return match ($status) {
            StudentStatus::Activo => 'Estudiante',
            StudentStatus::Egresado => 'Egresado',
            default => null,
        };
    }

    public static function isCurrent(?StudentStatus $status): bool
    {
        return self::statusLabel($status) !== null;
    }
}
