<?php

namespace App\Modules\Students\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Las fotos de los alumnos para la credencial (AC-20, tarea 1.27): el disco `student_photos` guarda una carpeta por año
 * de ingreso y un archivo por número de control, `<AAAA>/<número de control>.<ext>` (p. ej. `2021/21111182.jpg`). El año
 * sale de los dos primeros dígitos del número de control (21 → 2021), después de las letras de un prefijo de posgrado
 * («C», «M», «D»…). Se aceptan jpg, jpeg, png y webp. Nexus solo las lee: se colocan a mano en el directorio.
 */
final class StudentPhotos
{
    /** @var list<string> */
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var array<string, string> */
    private const array MIME = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /**
     * La carpeta del año de un número de control (`2021`), o null si no empieza con dos dígitos tras su prefijo.
     */
    public static function yearFolder(string $controlNumber): ?string
    {
        return preg_match('/^[A-Za-z]*(\d{2})\d/', trim($controlNumber), $match) === 1 ? '20'.$match[1] : null;
    }

    /**
     * La ruta relativa de la foto que existe para el número de control, o null si no hay.
     */
    public function locate(string $controlNumber): ?string
    {
        $folder = self::yearFolder($controlNumber);
        $name = trim($controlNumber);

        if ($folder === null || preg_match('/^[A-Za-z0-9]+$/', $name) !== 1) {
            return null;
        }

        foreach (self::EXTENSIONS as $extension) {
            foreach ([$name, mb_strtoupper($name), mb_strtolower($name)] as $candidate) {
                $path = "{$folder}/{$candidate}.{$extension}";

                if ($this->disk()->exists($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * El contenido y el tipo de la foto del número de control, o null si no hay.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function read(string $controlNumber): ?array
    {
        $path = $this->locate($controlNumber);
        $bytes = $path === null ? null : $this->disk()->get($path);

        if ($path === null || $bytes === null || $bytes === '') {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => self::MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))]];
    }

    private function disk(): Filesystem
    {
        return Storage::disk('student_photos');
    }
}
