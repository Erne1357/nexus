<?php

namespace App\Modules\Core\Credentials;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\StaffPhotos;
use App\Modules\Core\Support\StaffPhotoUrl;
use Closure;

/**
 * La miniatura de la foto de quien acaba de entrar (CO-05, tarea 1.36), como `data:` URL para la credencial de la
 * pantalla de acceso. Primero la del personal (StaffPhotos); si no hay, cada fuente que otro módulo registró con
 * extend() al arrancar (Students registra la del alumno: Core no lo conoce). Con extendUrl() las fuentes dan, además, la URL
 * de la foto propia para la banda (urlFor). Las fuentes se registran solo en boot() y
 * son closures sin estado, así que el registro es seguro bajo Octane.
 */
final class CredentialPhotos
{
    /** @var list<Closure(User): ?string> */
    private array $sources = [];

    /** @var list<Closure(User): ?string> */
    private array $urlSources = [];

    public function __construct(private readonly StaffPhotos $staff) {}

    /**
     * @param  Closure(User): ?string  $source  devuelve la miniatura como `data:` URL, o null si la persona no tiene foto en esa fuente
     */
    public function extend(Closure $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * @param  Closure(User): ?string  $source  devuelve la URL de la foto propia de la persona (para la banda), o null si no tiene
     */
    public function extendUrl(Closure $source): void
    {
        $this->urlSources[] = $source;
    }

    /**
     * La URL de la foto propia de quien tiene la sesión, para la banda: la miniatura firmada del personal o, si no hay,
     * la que dé una fuente registrada con extendUrl() (la del alumno). Null sin foto.
     */
    public function urlFor(User $user): ?string
    {
        $url = StaffPhotoUrl::for($user, mini: true);

        foreach ($this->urlSources as $source) {
            if ($url !== null) {
                break;
            }
            $url = $source($user);
        }

        return $url;
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
