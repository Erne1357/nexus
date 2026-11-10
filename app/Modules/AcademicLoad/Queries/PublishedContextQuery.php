<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * Resuelve el periodo y el campus de la consulta pública por su código (`20271`, `C2`), que es lo que lleva la URL.
 * Sin estado propio, para Octane.
 */
final class PublishedContextQuery
{
    public function __construct(private readonly PublishedScheduleQuery $published) {}

    /**
     * El contexto de los códigos de la URL; aborta con 404 si el periodo o el campus no existen o no están activos.
     */
    public function resolve(string $termCode, string $campusCode): PublishedContext
    {
        abort_unless(self::isCode($termCode) && self::isCode($campusCode), 404);

        $term = Term::query()->where('code', $termCode)->first();
        $campus = Campus::query()->where('code', $campusCode)->where('is_active', true)->first();

        abort_if($term === null || $campus === null, 404);

        return new PublishedContext($term, $campus, $this->published->schedule($term, $campus));
    }

    /**
     * El contexto que abre `/horarios`: con códigos, ese (404 si no existe); sin ellos, el primer campus del primer
     * periodo con algo publicado, o null si no hay nada publicado todavía. Con solo el periodo, el primer campus que
     * publicó en él; con solo el campus, su periodo más reciente con algo publicado.
     */
    public function initial(?string $termCode, ?string $campusCode): ?PublishedContext
    {
        $termCode = $termCode === '' ? null : $termCode;
        $campusCode = $campusCode === '' ? null : $campusCode;
        abort_if(($termCode !== null && ! self::isCode($termCode)) || ($campusCode !== null && ! self::isCode($campusCode)), 404);

        if ($termCode !== null && $campusCode !== null) {
            return $this->resolve($termCode, $campusCode);
        }

        $contexts = $this->published->contexts();
        $campus = null;

        if ($termCode === null && $campusCode !== null) {
            $context = array_find($contexts, fn (array $entry): bool => array_any($entry['campuses'], fn (Campus $item): bool => $item->code === $campusCode));
            $campus = $context === null ? null : array_find($context['campuses'], fn (Campus $item): bool => $item->code === $campusCode);
        } else {
            $context = $termCode === null ? ($contexts[0] ?? null) : array_find($contexts, fn (array $entry): bool => $entry['term']->code === $termCode);

            if ($context === null) {
                abort_if($termCode !== null, 404);

                return null;
            }

            $campus = $campusCode === null ? ($context['campuses'][0] ?? null) : array_find($context['campuses'], fn (Campus $item): bool => $item->code === $campusCode);
        }

        abort_if($campus === null, 404);

        return new PublishedContext($context['term'], $campus, $this->published->schedule($context['term'], $campus));
    }

    /**
     * Si un texto puede ser la clave de un periodo o de un campus (`20271`, `C2`): letras, dígitos, guion y guion bajo,
     * hasta 16. Lo demás es 404 antes de llegar a la base (un `%00` daría error de PostgreSQL).
     */
    public static function isCode(?string $value): bool
    {
        return $value !== null && preg_match('/^[A-Za-z0-9_-]{1,16}$/', $value) === 1;
    }
}
