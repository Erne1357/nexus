<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Notifications\PositionRef;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Los buzones de puesto (CE-02) y sus correos en espera: a qué buzón va el correo de un puesto, la tabla de la pantalla
 * «Buzones de puesto» y lo que espera agrupado por puesto, también lo que no tiene buzón, para que el admin sepa qué
 * capturar.
 *
 * El buzón de un puesto es el activo y sin borrar del mismo rol cuyo alcance coincide: cada columna del buzón es nula
 * (comodín) o igual a la del puesto, y una columna nula del puesto solo la cubre un buzón con esa columna nula. Si
 * coinciden varios, gana el más específico (más columnas llenas) y, a la par, el más viejo.
 */
final class PositionMailboxQuery
{
    public function __construct(private readonly AssignmentLabels $labels, private readonly PeopleQuery $people) {}

    public function addressFor(PositionRef $position): ?string
    {
        return $this->mailboxFor($position)?->email;
    }

    public function mailboxFor(PositionRef $position): ?PositionMailbox
    {
        $query = PositionMailbox::query()->active()->where('role', $position->role);

        foreach ($position->columns() as $column => $value) {
            $value === null
                ? $query->whereNull($column)
                : $query->where(fn (Builder $match) => $match->whereNull($column)->orWhere($column, $value));
        }

        return $query
            ->orderByRaw('((org_unit_id is not null)::int + (career_id is not null)::int + (campus_id is not null)::int + (modality_id is not null)::int) desc')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * El buzón que se edita, por el id que llega del cliente.
     */
    public function findOrFail(string $id): PositionMailbox
    {
        return KeyLookup::findOrFail(PositionMailbox::query(), $id);
    }

    /**
     * Todos los buzones sin borrar, activos primero y por puesto, con su nombre (`label`), su alcance en etiquetas
     * (`chips`) y cuántos correos esperan que hoy resolverían a él (`waiting`). Es un catálogo corto (un buzón por
     * jefatura o coordinación), así que no se pagina. `$search` busca en el correo y en el nombre del puesto. `$waiting` es
     * lo de waiting(), si quien llama ya lo tiene.
     *
     * @param  list<array{position: PositionRef, label: string, chips: list<array{label: string, value: string}>, count: int, since: CarbonImmutable, mailbox: ?PositionMailbox}>|null  $waiting
     * @return list<array{id: string, mailbox: PositionMailbox, label: string, chips: list<array{label: string, value: string}>, waiting: int}>
     */
    public function forTable(string $search = '', ?array $waiting = null): array
    {
        $mailboxes = PositionMailbox::query()->orderByDesc('is_active')->orderBy('role')->orderBy('email')->get();
        $labels = $this->describe($mailboxes->all());
        $waiting = $this->waitingByMailbox($waiting ?? $this->waiting());
        $search = mb_strtolower(trim($search));
        $rows = [];

        foreach ($mailboxes as $mailbox) {
            $label = $labels[$mailbox->getKey()];

            if ($search !== '' && ! str_contains(mb_strtolower($mailbox->email.' '.$label->summary), $search)) {
                continue;
            }

            $rows[] = ['id' => $mailbox->getKey(), 'mailbox' => $mailbox, 'label' => $label->title, 'chips' => $label->chips, 'waiting' => $waiting[$mailbox->getKey()] ?? 0];
        }

        return $rows;
    }

    /**
     * Los correos en espera agrupados por puesto, el más viejo primero: el puesto (con su nombre y su alcance), cuántos
     * esperan, desde cuándo y el buzón que hoy los recibiría (null: no tiene; hay que capturarlo). Los fallidos no
     * cuentan.
     *
     * @return list<array{position: PositionRef, label: string, chips: list<array{label: string, value: string}>, count: int, since: CarbonImmutable, mailbox: ?PositionMailbox}>
     */
    public function waiting(): array
    {
        $groups = HeldPositionMail::query()
            ->waiting()
            ->selectRaw('role, org_unit_id, career_id, campus_id, modality_id, count(*) as total, min(held_at) as since')
            ->groupBy('role', 'org_unit_id', 'career_id', 'campus_id', 'modality_id')
            ->orderBy('since')
            ->get();

        $positions = $groups->map(fn (HeldPositionMail $group): PositionRef => $group->position())->all();
        $labels = $this->describePositions($positions);
        $rows = [];

        foreach ($groups as $index => $group) {
            $rows[] = [
                'position' => $positions[$index],
                'label' => $labels[$index]->title,
                'chips' => $labels[$index]->chips,
                'count' => (int) $group->getAttribute('total'),
                'since' => CarbonImmutable::parse($group->getAttribute('since')),
                'mailbox' => $this->mailboxFor($positions[$index]),
            ];
        }

        return $rows;
    }

    /**
     * Los correos de puesto que esperan o que fallaron, uno por renglón, para «Reintentar» y «Descartar»: primero los
     * fallidos (el más reciente arriba) y luego los que esperan (el más viejo arriba), cada uno con el nombre de su puesto
     * (`label`), su alcance (`chips`) y el buzón que hoy lo recibiría (`mailbox`). Los que ya se tomaron para mandarse y
     * los descartados no salen.
     *
     * @return LengthAwarePaginator<int, array{id: string, held: HeldPositionMail, label: string, chips: list<array{label: string, value: string}>, mailbox: ?PositionMailbox}>
     */
    public function heldForTable(int $perPage = 10): LengthAwarePaginator
    {
        $page = HeldPositionMail::query()
            ->whereNull('released_at')
            ->whereNull('discarded_at')
            ->orderByRaw('failed_at is null')
            ->orderByDesc('failed_at')
            ->orderBy('held_at')
            ->orderBy('id')
            ->paginate($perPage, pageName: 'correos');

        $items = $page->getCollection()->values();
        $labels = $this->describePositions($items->map(fn (HeldPositionMail $held): PositionRef => $held->position())->all());

        return $page->setCollection($items->map(fn (HeldPositionMail $held, int $index): array => [
            'id' => $held->getKey(),
            'held' => $held,
            'label' => $labels[$index]->title,
            'chips' => $labels[$index]->chips,
            'mailbox' => $this->mailboxFor($held->position()),
        ]));
    }

    /**
     * El correo en espera que se reintenta o descarta, por el id que llega del cliente.
     */
    public function findHeldOrFail(string $id): HeldPositionMail
    {
        return KeyLookup::findOrFail(HeldPositionMail::query(), $id);
    }

    /**
     * Los puestos que pueden tener buzón: los que llevan alcance (no el admin, el docente ni el alumno, cuyos avisos son
     * de persona), con su nombre.
     *
     * @return list<array{id: string, name: string}>
     */
    public function roleOptions(): array
    {
        $roles = array_filter(Role::cases(), fn (Role $role): bool => PositionRef::columnsOf($role) !== []);

        return array_values(array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->label()], $roles));
    }

    /**
     * Las opciones del alcance del formulario para `$role`, las mismas de «Nombrar» (PeopleQuery::scopeOptions()).
     *
     * @return array{modalities: list<array{id: string, name: string}>, campuses: list<array{id: string, name: string}>, units: list<array{id: string, name: string}>, careers: list<array{id: string, name: string}>}
     */
    public function scopeOptions(Role $role, ?string $campusId, ?string $modalityId): array
    {
        return $this->people->scopeOptions($role, $campusId, $modalityId);
    }

    /**
     * El nombre del puesto de un buzón o de un grupo en espera, con las etiquetas del Organigrama y de los nombramientos
     * (AssignmentLabels): se arma un nombramiento sin guardar con el mismo rol y alcance.
     *
     * @param  list<PositionMailbox>  $mailboxes
     * @return array<string, AssignmentLabel> por id del buzón
     */
    private function describe(array $mailboxes): array
    {
        $assignments = array_map(fn (PositionMailbox $mailbox): RoleAssignment => (new RoleAssignment)->forceFill([
            'id' => $mailbox->getKey(),
            'role' => $mailbox->role,
            ...$mailbox->position()->columns(),
        ]), $mailboxes);

        return $this->labels->describe($assignments);
    }

    /**
     * @param  list<PositionRef>  $positions
     * @return array<int, AssignmentLabel> en el orden recibido
     */
    private function describePositions(array $positions): array
    {
        $assignments = [];

        foreach ($positions as $index => $position) {
            $assignments[] = (new RoleAssignment)->forceFill(['id' => (string) $index, 'role' => $position->role, ...$position->columns()]);
        }

        $described = $this->labels->describe($assignments);

        return array_map(fn (int $index): AssignmentLabel => $described[(string) $index], array_keys($positions));
    }

    /**
     * Cuántos correos en espera resolvería hoy cada buzón, por id del buzón.
     *
     * @param  list<array{count: int, mailbox: ?PositionMailbox}>  $waiting
     * @return array<string, int>
     */
    private function waitingByMailbox(array $waiting): array
    {
        $counts = [];

        foreach ($waiting as $group) {
            if ($group['mailbox'] !== null) {
                $counts[$group['mailbox']->getKey()] = ($counts[$group['mailbox']->getKey()] ?? 0) + $group['count'];
            }
        }

        return $counts;
    }
}
