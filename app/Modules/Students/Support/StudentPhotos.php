<?php

namespace App\Modules\Students\Support;

use App\Modules\Core\Exceptions\InvalidPhoto;
use App\Modules\Core\Support\PhotoNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las fotos de los alumnos para la credencial (AC-20, tarea 1.27): el disco `student_photos` (fuera de la raíz del disco
 * `local` y de `public/`) guarda una carpeta por año de ingreso y un archivo por número de control,
 * `<AAAA>/<número de control>.<ext>` (p. ej. `2021/21111182.jpg`). El año sale de los dos primeros dígitos del número de
 * control (21 → 2021; 94 → 1994), después de las letras de un prefijo de posgrado («C», «M», «D»…). Se aceptan jpg, jpeg, png y webp
 * de hasta MAX_BYTES; una más pesada cuenta como sin foto (supuesto por confirmar). Las que ya existían se colocaron a mano y
 * se siguen leyendo igual. Desde la tarea 1.37 Nexus también las escribe (CO-06, `store`): normaliza a un JPEG de 600×800 y
 * la foto que reemplaza se mueve a `_anteriores/<AAAA>/<número de control>-<AAAAMMDDHHMMSS>.<ext>`; nunca se borra.
 */
final class StudentPhotos
{
    public const string ARCHIVE = '_anteriores';

    public function __construct(private readonly PhotoNormalizer $normalizer) {}

    /** @var list<string> */
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** El tope de una foto: la página pública la incrusta en cada verificación. */
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string, string> */
    private const array MIME = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /**
     * La carpeta del año de un número de control (`2021`), o null si no empieza con dos dígitos tras su prefijo. Los dos
     * dígitos son del siglo XXI hasta el año siguiente al actual; los mayores, del XX (`94110667` → `1994`).
     */
    public static function yearFolder(string $controlNumber): ?string
    {
        if (preg_match('/^[A-Za-z]*(\d{2})\d/', trim($controlNumber), $match) !== 1) {
            return null;
        }

        return ((int) $match[1] <= (int) now()->format('y') + 1 ? '20' : '19').$match[1];
    }

    /**
     * La ruta relativa de la foto que existe para el número de control y no pasa del tope, o null si no hay.
     */
    public function locate(string $controlNumber): ?string
    {
        $folder = self::yearFolder($controlNumber);
        $name = trim($controlNumber);

        if ($folder === null || preg_match('/^[A-Za-z0-9]+$/', $name) !== 1) {
            return null;
        }

        foreach (self::EXTENSIONS as $extension) {
            foreach (array_unique([$name, mb_strtoupper($name), mb_strtolower($name)]) as $candidate) {
                $path = "{$folder}/{$candidate}.{$extension}";

                if ($this->disk()->exists($path)) {
                    $size = $this->disk()->size($path);

                    return $size > 0 && $size <= self::MAX_BYTES ? $path : null;
                }
            }
        }

        return null;
    }

    /**
     * Normaliza `$sourcePath`, mueve la foto que hubiera de ese número de control (de cualquier extensión) a
     * `_anteriores/` y escribe `<AAAA>/<número de control>.jpg`; devuelve esa ruta.
     *
     * @throws InvalidPhoto si no es una imagen jpg, png o webp legible, o el número de control no sirve de nombre de archivo
     */
    public function store(string $controlNumber, string $sourcePath): string
    {
        $folder = self::yearFolder($controlNumber);
        $name = trim($controlNumber);

        if ($folder === null || preg_match('/^[A-Za-z0-9]+$/', $name) !== 1) {
            throw new InvalidPhoto('El número de control no trae el año de ingreso.');
        }

        $photos = $this->normalizer->normalize($sourcePath);

        $stamp = now();
        foreach ($this->existing($folder, $name) as $path) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            while ($this->disk()->exists($archived = self::ARCHIVE."/{$folder}/{$name}-{$stamp->format('YmdHis')}.{$extension}")) {
                $stamp = $stamp->addSecond();
            }
            $this->disk()->move($path, $archived);
        }

        $this->disk()->put($path = "{$folder}/{$name}.jpg", $photos['normal']);

        return $path;
    }

    /**
     * Los números de control (en minúsculas) que tienen un archivo de foto, para filtrar «Con foto» y «Sin foto». Mira las
     * carpetas de año, no `_anteriores`.
     *
     * @return list<string>
     */
    public function controlNumbersWithPhoto(): array
    {
        $numbers = [];

        foreach ($this->disk()->directories() as $folder) {
            if (preg_match('/^\d{4}$/', $folder) !== 1) {
                continue;
            }

            foreach ($this->disk()->files($folder) as $file) {
                if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    $numbers[mb_strtolower(pathinfo($file, PATHINFO_FILENAME))] = true;
                }
            }
        }

        return array_keys($numbers);
    }

    /**
     * Cuándo se guardó la foto vigente del número de control, o null si no hay.
     */
    public function modifiedAt(string $controlNumber): ?CarbonImmutable
    {
        $path = $this->locate($controlNumber);

        return $path === null ? null : CarbonImmutable::createFromTimestamp($this->disk()->lastModified($path));
    }

    /**
     * Una marca que cambia cuando cambia la foto (para la URL de la foto propia), o null si no hay foto.
     */
    public function version(string $controlNumber): ?string
    {
        $path = $this->locate($controlNumber);

        return $path === null ? null : substr(hash('sha256', $path.'|'.$this->disk()->lastModified($path).'|'.$this->disk()->size($path)), 0, 16);
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

        return ['bytes' => $bytes, 'mime' => self::mimeOf($path)];
    }

    /**
     * La foto como respuesta en flujo (sin cargarla entera en memoria), con las cabeceras dadas, o null si no hay.
     *
     * @param  array<string, string>  $headers
     */
    public function stream(string $controlNumber, array $headers): ?StreamedResponse
    {
        $path = $this->locate($controlNumber);

        return $path === null ? null : $this->disk()->response($path, null, ['Content-Type' => self::mimeOf($path), ...$headers]);
    }

    /**
     * Los archivos de foto de ese número de control en la carpeta del año, de cualquier extensión y mayúsculas.
     *
     * @return list<string>
     */
    private function existing(string $folder, string $name): array
    {
        $found = [];

        foreach (self::EXTENSIONS as $extension) {
            foreach (array_unique([$name, mb_strtoupper($name), mb_strtolower($name)]) as $candidate) {
                if ($this->disk()->exists($path = "{$folder}/{$candidate}.{$extension}")) {
                    $found[] = $path;
                }
            }
        }

        return $found;
    }

    private static function mimeOf(string $path): string
    {
        return self::MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))];
    }

    private function disk(): Filesystem
    {
        return Storage::disk('student_photos');
    }
}
