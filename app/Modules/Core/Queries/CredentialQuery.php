<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;

/**
 * La credencial de quien trabaja: su nombre, su usuario, las iniciales del avatar, el puesto activo con el nombre que
 * le da su unidad (RoleTitleQuery, AZ-02) y el periodo de consulta (y, para quien trabaja la carga, el de carga, TP-04), con el resumen «Puesto · Periodo». La leen la banda
 * (core::components.band) y la respuesta del login en JSON (LoginResponse), que llena la credencial de la pantalla de
 * acceso antes de llevarla a la banda: así las dos dicen lo mismo.
 */
final class CredentialQuery
{
    public function __construct(private readonly RoleTitleQuery $titles) {}

    /**
     * @return array{name: string, username: string, initials: string, role: ?string, term: ?string, loadTerm: ?string, summary: string}
     */
    public function of(Actor $context): array
    {
        $user = $context->user();
        $role = $context->activeRole();
        $roleLabel = $role === null ? null : $this->titles->of($context, $role)['title'];
        $term = $context->viewTerm()?->name;

        return [
            'name' => $user->name ?? '',
            'username' => $user->username ?? '',
            'initials' => $this->initials($user->name ?? ''),
            'role' => $roleLabel,
            'term' => $term,
            'loadTerm' => $context->worksLoad() ? $context->loadTerm()?->name : null,
            'summary' => implode(' · ', array_filter([$roleLabel, $term])),
        ];
    }

    /**
     * Las iniciales del avatar: las de las dos primeras palabras con mayúscula del nombre («María de
     * la Luz» da ML) o, si no hay, las de las dos primeras palabras.
     */
    public function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $capitalized = array_values(array_filter($words, fn (string $word): bool => preg_match('/^\p{Lu}/u', $word) === 1));

        return mb_strtoupper(implode('', array_map(
            fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($capitalized === [] ? $words : $capitalized, 0, 2),
        )));
    }
}
