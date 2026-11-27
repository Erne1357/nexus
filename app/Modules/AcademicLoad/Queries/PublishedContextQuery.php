<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resuelve el periodo, la modalidad y el campus del filtro de la consulta pública por sus códigos (`20271`, `pres`,
 * `C2`), que es lo que lleva la URL: `/horarios/{periodo}/{modalidad}?campus=` (M1: lo publicado es por modalidad y el
 * campus es un filtro). La clave de la modalidad va en minúsculas en la URL y se acepta en cualquiera. Sin estado
 * propio, para Octane.
 */
final class PublishedContextQuery
{
    public function __construct(private readonly PublishedScheduleQuery $published) {}

    /**
     * El contexto de los códigos de la URL; aborta con 404 si el periodo, la modalidad o el campus del filtro no existen
     * o no están activos. Sin campus (null o ''), todos.
     */
    public function resolve(string $termCode, string $modalityCode, ?string $campusCode = null): PublishedContext
    {
        $campusCode = $campusCode === '' ? null : $campusCode;
        abort_unless(self::isCode($termCode) && self::isCode($modalityCode) && ($campusCode === null || self::isCode($campusCode)), 404);

        $term = Term::query()->where('code', $termCode)->first();
        $modality = self::modality($modalityCode);

        abort_if($term === null || $modality === null, 404);

        return $this->context($term, $modality, $campusCode);
    }

    /**
     * El contexto que abre `/horarios`: con periodo y modalidad, ese (404 si no existe); sin ellos, la primera modalidad
     * del primer periodo con algo publicado, o null si no hay nada publicado todavía. Con solo el periodo, la primera
     * modalidad que publicó en él; con solo la modalidad, su periodo más reciente con algo publicado. El campus siempre
     * es solo un filtro.
     */
    public function initial(?string $termCode, ?string $modalityCode, ?string $campusCode = null): ?PublishedContext
    {
        $termCode = $termCode === '' ? null : $termCode;
        $modalityCode = $modalityCode === '' ? null : $modalityCode;
        $campusCode = $campusCode === '' ? null : $campusCode;
        abort_if(($termCode !== null && ! self::isCode($termCode)) || ($modalityCode !== null && ! self::isCode($modalityCode)) || ($campusCode !== null && ! self::isCode($campusCode)), 404);

        if ($termCode !== null && $modalityCode !== null) {
            return $this->resolve($termCode, $modalityCode, $campusCode);
        }

        $contexts = $this->published->contexts();
        $matches = fn (Modality $item): bool => self::modalityKey($item) === mb_strtolower((string) $modalityCode);

        if ($termCode === null && $modalityCode !== null) {
            $context = array_find($contexts, fn (array $entry): bool => array_any($entry['modalities'], $matches));
            $modality = $context === null ? null : array_find($context['modalities'], $matches);
        } else {
            $context = $termCode === null ? ($contexts[0] ?? null) : array_find($contexts, fn (array $entry): bool => $entry['term']->code === $termCode);

            if ($context === null) {
                abort_if($termCode !== null, 404);

                return null;
            }

            $modality = $modalityCode === null ? ($context['modalities'][0] ?? null) : array_find($context['modalities'], $matches);
        }

        abort_if($modality === null, 404);

        return $this->context($context['term'], $modality, $campusCode);
    }

    /**
     * La clave de la modalidad como va en la URL de la consulta: en minúsculas (`pres`).
     */
    public static function modalityKey(Modality $modality): string
    {
        return mb_strtolower($modality->code);
    }

    /**
     * La modalidad activa con esa clave, en cualquier capitalización, o null.
     */
    public static function modality(string $code): ?Modality
    {
        return self::isCode($code) ? Modality::query()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->where('is_active', true)->first() : null;
    }

    /**
     * Si un texto puede ser la clave de un periodo, una modalidad o un campus (`20271`, `pres`, `C2`): letras, dígitos,
     * guion y guion bajo, hasta 16. Lo demás es 404 antes de llegar a la base (un `%00` daría error de PostgreSQL).
     */
    public static function isCode(?string $value): bool
    {
        return $value !== null && preg_match('/^[A-Za-z0-9_-]{1,16}$/', $value) === 1;
    }

    /**
     * @throws HttpException 404 si el campus del filtro no existe o no está activo
     */
    private function context(Term $term, Modality $modality, ?string $campusCode): PublishedContext
    {
        $campus = $campusCode === null ? null : Campus::query()->where('code', $campusCode)->where('is_active', true)->first();
        abort_if($campusCode !== null && $campus === null, 404);

        return new PublishedContext($term, $modality, $campus, $this->published->schedule($term, $modality)->onlyCampus($campus?->code));
    }
}
