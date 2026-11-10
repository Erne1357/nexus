<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Scheduling\Models\Group;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * HC-07 (reglas-de-negocio.md, "Cupos por carrera"): la suma de los cupos de un grupo más los lugares que apartan sus
 * propuestas vivas de grupo compartido no pasa su capacidad; una propuesta aparta sus lugares desde que se propone
 * (confirmado el 8 de noviembre de 2026). Los lugares de cada cupo van de 1 a 999, como los de un acuerdo.
 *
 * La base solo cuida los cupos (el trigger diferible de `group_quotas`); las propuestas viven en
 * `interdepartmental_agreements`, así que lo apartado (heldSeats) se revisa aquí, con el grupo bloqueado (FOR UPDATE)
 * por quien llama. Toda Action que propone, acepta, retira o reparte cupos toma ese candado, así que la suma no cambia
 * entre la lectura y el commit. Rechazar o cancelar solo libera lugares, así que no lo necesita.
 */
final class QuotaRules
{
    public const int MAX_SEATS = AgreementRules::MAX_SEATS;

    public function __construct(private readonly LoadEditability $editability) {}

    /**
     * Los cupos (`$total`) y lo que apartan las propuestas (`$held`) caben en la capacidad.
     *
     * @throws ValidationException
     */
    public function ensureWithinCapacity(int $total, int $capacity, int $held = 0): void
    {
        if ($total + $held > $capacity) {
            throw $this->overCapacity($total, $capacity, held: $held);
        }
    }

    /**
     * Los lugares que apartan las propuestas vivas (propuestas, todavía sin decidir) de grupo compartido del grupo. Las
     * aceptadas ya son cupos y las rechazadas o canceladas no apartan nada. Se lee con el grupo ya bloqueado.
     */
    public function heldSeats(Group $group): int
    {
        return (int) InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::SharedGroup->value)
            ->where('group_id', $group->getKey())
            ->where('status', AgreementStatus::Proposed->value)
            ->sum('max_seats');
    }

    /**
     * Al proponer: lo repartido y lo apartado por las demás propuestas (`$taken`) más los lugares de la nueva caben en
     * la capacidad.
     *
     * @throws ValidationException
     */
    public function ensureProposalFits(int $taken, int $seats, int $capacity): void
    {
        if ($taken + $seats > $capacity) {
            throw ValidationException::withMessages([
                'max_seats' => 'HC-07: el grupo ya tiene '.$taken.' de '.$capacity.' lugares repartidos o apartados; caben '.max(0, $capacity - $taken).'.',
            ]);
        }
    }

    /**
     * Los cupos se editan con la misma regla que las sesiones (LoadEditability): grupo sin cancelar y carga propuesta,
     * en asignación docente o en ajuste; sin carga tampoco (supuesto por confirmar).
     *
     * @throws ValidationException
     */
    public function ensureEditable(Group $group): void
    {
        $this->editability->ensure($group, 'cupos', 'quotas');
    }

    /**
     * Toma la carga del grupo (FOR SHARE) antes de bloquear el grupo: el orden de las transiciones de carga.
     */
    public function lockLoad(Group $group): void
    {
        $this->editability->lockLoad($group);
    }

    /**
     * Corre la escritura de cupos; un deadlock de la base sale como error de validación y no como un 500.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws ValidationException
     */
    public function guard(Closure $work): mixed
    {
        return $this->editability->guard($work, 'quotas');
    }

    /**
     * La carrera, para ganar un cupo nuevo, está activa, sin borrar y se ofrece activa en el campus del grupo.
     *
     * @throws ValidationException
     */
    public function ensureOffered(Career $career, Group $group): void
    {
        $offered = ! $career->trashed() && $career->is_active
            && CareerCampus::query()->where('career_id', $career->getKey())->where('campus_id', $group->campus_id)->where('is_active', true)->exists();

        if (! $offered) {
            throw ValidationException::withMessages(["quotas.{$career->getKey()}" => 'La carrera no está activa u ofrecida en el campus del grupo.']);
        }
    }

    /**
     * Al aceptar un acuerdo: lo repartido, lo que apartan las demás propuestas (`$held`) y los lugares del acuerdo caben
     * en la capacidad. Es una red de seguridad: como la propuesta ya apartaba sus lugares, siempre debería caber. Si no
     * cabe, el mensaje dice cuántos lugares hay que liberar y cómo: rechazar o retirar otras propuestas (o bajar cupos)
     * si las hay, o bajar cupos si no.
     *
     * @throws ValidationException
     */
    public function ensureAgreementFits(int $current, int $seats, int $capacity, int $held = 0): void
    {
        $taken = $current + $held;
        $missing = $taken + $seats - $capacity;

        if ($missing <= 0) {
            return;
        }

        $places = $missing === 1 ? '1 lugar' : "{$missing} lugares";

        throw ValidationException::withMessages(['max_seats' => $held > 0
            ? "HC-07: el grupo ya tiene {$taken} de {$capacity} lugares repartidos o apartados; caben ".max(0, $capacity - $taken).". Rechaza o retira {$places} de otras propuestas (o baja cupos) para poder aceptarlo."
            : "HC-07: el grupo ya tiene {$taken} de {$capacity} lugares repartidos; caben ".max(0, $capacity - $taken).". Baja {$places} de los cupos para poder aceptarlo.",
        ]);
    }

    /**
     * El error de HC-07 al repartir. Si hay lugares apartados por propuestas (`$held`), los dice y cuántos hay que quitar.
     */
    public function overCapacity(int $total, int $capacity, string $key = 'quotas', int $held = 0): ValidationException
    {
        if ($held === 0) {
            return ValidationException::withMessages([$key => "HC-07: los cupos suman {$total} y el grupo es de {$capacity} lugares."]);
        }

        $over = $total + $held - $capacity;

        return ValidationException::withMessages([
            $key => "HC-07: los cupos suman {$total} y las propuestas apartan {$held}; el grupo es de {$capacity} lugares. Quita {$over} ".($over === 1 ? 'lugar' : 'lugares').'.',
        ]);
    }

    /**
     * Si la excepción es el rechazo de HC-07 del trigger de la base (otra escritura cambió los cupos entre la
     * lectura y la escritura), la ValidationException con el mismo mensaje que la Action; si no, null.
     */
    public function fromDatabase(QueryException $e, string $key = 'quotas'): ?ValidationException
    {
        if (preg_match('/HC-07: los cupos del grupo suman (\d+) y su capacidad es (\d+)/', $e->getMessage(), $m) !== 1) {
            return null;
        }

        return $this->overCapacity((int) $m[1], (int) $m[2], $key);
    }

    /**
     * La violación del único de `group_quotas` (la carrera ya tiene cupo en el grupo): su ValidationException, o null.
     */
    public function fromUnique(QueryException $e): ?ValidationException
    {
        return str_contains($e->getMessage(), 'group_quotas_group_id_career_id_unique')
            ? ValidationException::withMessages(['career_id' => 'La carrera ya tiene cupo en este grupo.'])
            : null;
    }
}
