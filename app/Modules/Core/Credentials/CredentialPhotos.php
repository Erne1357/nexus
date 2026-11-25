<?php

namespace App\Modules\Core\Credentials;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\StaffPhotos;
use Closure;

/**
 * La miniatura de la foto de quien acaba de entrar (CO-05, tarea 1.36), como `data:` URL para la credencial de la
 * pantalla de acceso. Primero la del personal (StaffPhotos); si no hay, cada fuente que otro módulo registró con
 * extend() al arrancar (Students registra la del alumno: Core no lo conoce). Las fuentes se registran solo en boot() y
 * son closures sin estado, así que el registro es seguro bajo Octane.
 */
final class CredentialPhotos
{
    /** @var list<Closure(User): ?string> */
    private array $sources = [];

    public function __construct(private readonly StaffPhotos $staff) {}

    /**
     * @param  Closure(User): ?string  $source  devuelve la miniatura como `data:` URL, o null si la persona no tiene foto en esa fuente
     */
    public function extend(Closure $source): void
    {
        $this->sources[] = $source;
    }

    public function thumbFor(User $user): ?string
    {
        $photo = $this->staff->thumbDataUrl($user);

        foreach ($this->sources as $source) {
            if ($photo !== null) {
                break;
            }
            $photo = $source($user);
        }

        return $photo;
    }
}
