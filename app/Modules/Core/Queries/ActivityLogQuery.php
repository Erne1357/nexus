<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\ActivityEvents;
use App\Modules\Core\Support\ActivityRecordTypes;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Lecturas de «Bitácora» (CF-S5, HC-18): la bitácora de la más nueva a la más vieja, filtrada por quién hizo el cambio
 * (por nombre o usuario), tipo de registro, evento, texto de la descripción y fechas, y el detalle de una entrada. La
 * tabla tiene decenas de miles de filas, así que la lista pagina sin contar el total (`simplePaginate`) y se ordena por
 * `id`, que sigue el orden de las fechas. Solo lee: la tabla es inmutable.
 */
final class ActivityLogQuery
{
    /**
     * Cada entrada lleva `acting_as_label`, el nombre del puesto con que actuó la persona, o null.
     *
     * @return Paginator<int, Activity>
     */
    public function forTable(string $who = '', string $subjectType = '', string $event = '', string $search = '', ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, int $perPage = 25): Paginator
    {
        $page = Activity::query()
            ->with('causer')
            ->when(trim($who) !== '', fn (Builder $query) => $query
                ->where('causer_type', (new User)->getMorphClass())
                ->whereIn('causer_id', User::query()->select('id')->where(fn (Builder $users) => TextSearch::apply($users, ['username', 'name'], $who))))
            ->when($subjectType !== '', fn (Builder $query) => $query->where('subject_type', $subjectType))
            ->when($event !== '', fn (Builder $query) => $query->where('event', $event))
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['description'], $search))
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->orderByDesc('id')
            ->simplePaginate($perPage);

        $page->getCollection()->each(fn (Activity $activity) => $activity->setAttribute('acting_as_label', $this->actingAsLabel($activity->properties?->get('acting_as'))));

        return $page;
    }

    /**
     * Los tipos de registro que hay en la bitácora, con su nombre en español, para el filtro.
     *
     * @return list<array{id: string, name: string}>
     */
    public function subjectTypes(): array
    {
        $types = Activity::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')
            ->map(fn (string $type): array => ['id' => $type, 'name' => ActivityRecordTypes::label($type)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return $types->all();
    }

    /**
     * Los eventos que hay en la bitácora, para el filtro, con su nombre en español (ActivityEvents) y en su orden.
     *
     * @return list<array{id: string, name: string}>
     */
    public function events(): array
    {
        return Activity::query()->whereNotNull('event')->distinct()->pluck('event')
            ->map(fn (string $event): array => ['id' => $event, 'name' => ActivityEvents::label($event)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @throws ModelNotFoundException<Activity>
     */
    public function detail(int|string $id): ActivityDetail
    {
        $activity = Activity::query()->with('causer')->whereKey($id)->firstOrFail();

        $attributes = (array) ($activity->attribute_changes['attributes'] ?? []);
        $old = (array) ($activity->attribute_changes['old'] ?? []);
        $changes = [];

        foreach ([...array_keys($attributes), ...array_diff(array_keys($old), array_keys($attributes))] as $field) {
            $changes[] = ['field' => (string) $field, 'old' => $this->show($old[$field] ?? null, array_key_exists($field, $old)), 'new' => $this->show($attributes[$field] ?? null, array_key_exists($field, $attributes))];
        }

        $properties = $activity->properties?->all() ?? [];
        $actingAs = $properties['acting_as'] ?? null;
        unset($properties['acting_as']);

        return new ActivityDetail(
            $activity,
            ActivityRecordTypes::label($activity->subject_type),
            $this->subjectName($activity),
            $this->actingAsLabel($actingAs),
            $changes,
            $properties,
        );
    }

    /**
     * El nombre del puesto con que actuó la persona (`properties.acting_as`); su clave si ya no existe.
     */
    private function actingAsLabel(mixed $actingAs): ?string
    {
        return is_string($actingAs) ? (Role::tryFrom($actingAs)?->listLabel() ?? $actingAs) : null;
    }

    /**
     * Un valor para mostrarlo: texto, «—» si no existía, `sí` o `no` y el JSON de lo compuesto.
     */
    private function show(mixed $value, bool $present): string
    {
        return match (true) {
            ! $present, $value === null => '—',
            is_bool($value) => $value ? 'sí' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * El nombre del registro afectado, si todavía existe y tiene uno a la vista.
     */
    private function subjectName(Activity $activity): ?string
    {
        if ($activity->subject_type === null || ! is_subclass_of($activity->subject_type, Model::class)) {
            return null;
        }

        try {
            $subject = $activity->subject;
        } catch (Throwable) {
            return null;
        }

        foreach (['name', 'code', 'username', 'title'] as $attribute) {
            $value = $subject?->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
