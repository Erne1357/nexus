<?php

namespace App\Modules\CatalogValidation\Rules;

use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Lo que comparten FlagRecord, CorrectRecord y las marcas de revisado (reglas-de-negocio.md, "Validación de
 * catálogos"): el registro es de la revisión, y la revisión no está validada (CV-S2, supuesto por confirmar; para cambiar algo de una
 * validada, primero se reabre), y para validar, que no quede ningún registro sin revisar (CV7, confirmado el 12 de
 * noviembre de 2026). Las Actions las llaman con la revisión ya bloqueada.
 */
final class CatalogReviewRules
{
    public function __construct(private readonly CatalogContentsQuery $contents) {}

    /**
     * @throws ValidationException
     */
    public function ensureOpen(CatalogReview $review): void
    {
        if ($review->status === ReviewStatus::Validated) {
            throw ValidationException::withMessages(['status' => 'La revisión ya está validada: reábrela para corregir u observar.']);
        }
    }

    /**
     * @throws ValidationException
     */
    public function ensureContains(CatalogReview $review, Model $record): void
    {
        if (! $this->contents->contains($review, $record)) {
            throw ValidationException::withMessages(['record' => 'El registro no es de esta revisión.']);
        }
    }

    /**
     * Todos los ids son de registros de la revisión; si uno no, ninguno cuenta.
     *
     * @param  list<string>  $ids  sin repetidos
     *
     * @throws ValidationException
     */
    public function ensureContainsAll(CatalogReview $review, array $ids): void
    {
        if (! $this->contents->containsAll($review, $ids)) {
            throw ValidationException::withMessages(['record' => 'El registro no es de esta revisión.']);
        }
    }

    /**
     * Ningún registro de la revisión queda sin marcar como revisado (CV7); una revisión sin registros cumple.
     *
     * @throws ValidationException
     */
    public function ensureAllReviewed(CatalogReview $review): void
    {
        if (($reason = $this->unreviewedReason($review)) !== null) {
            throw ValidationException::withMessages(['status' => $reason]);
        }
    }

    /**
     * Por qué todavía no se valida por CV7 («Faltan 3 registros por revisar.»), o null si ya están todos revisados.
     * La pantalla lo usa para deshabilitar Validar con su motivo.
     */
    public function unreviewedReason(CatalogReview $review): ?string
    {
        return self::unreviewedMessage($this->contents->unreviewedCount($review));
    }

    /**
     * El motivo de CV7 para un número de registros sin revisar, o null con cero.
     */
    public static function unreviewedMessage(int $unreviewed): ?string
    {
        return match (true) {
            $unreviewed <= 0 => null,
            $unreviewed === 1 => 'Falta 1 registro por revisar.',
            default => "Faltan {$unreviewed} registros por revisar.",
        };
    }
}
