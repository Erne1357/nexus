<?php

namespace App\Modules\Communication\Queries;

use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de «Fotos del personal» (CO-03): el personal con su foto, buscado por nombre o usuario y filtrado a «Sin
 * foto» o «Con foto», y los lotes en ZIP del personal. Del personal es quien tiene la cuenta activa y no es solo
 * alumno (UnitStaffQuery::staffAccounts()).
 */
final class StaffPhotosQuery
{
    public const int PER_PAGE = 25;

    public function __construct(private readonly UnitStaffQuery $staff) {}

    /**
     * Una página del personal por nombre. `$photo`: 'sin' (sin foto), 'con' (con foto) o cualquier otro valor (todos).
     * Cada persona trae `units`, los nombres de las unidades de sus nombramientos vigentes.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function page(string $search, string $photo): LengthAwarePaginator
    {
        $page = $this->staff->staffAccounts()
            ->tap(fn ($query) => TextSearch::apply($query, ['name', 'username'], $search))
            ->when($photo === 'sin', fn ($query) => $query->whereNull('photo_path'))
            ->when($photo === 'con', fn ($query) => $query->whereNotNull('photo_path'))
            ->with(['roleAssignments' => fn ($assignments) => $assignments->current()->whereNotNull('org_unit_id')->with('orgUnit:id,name')])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        $page->through(function (User $user): User {
            $user->setAttribute('units', $user->roleAssignments->map(fn ($assignment): ?string => $assignment->orgUnit?->name)->filter()->unique()->values()->all());

            return $user;
        });

        return $page;
    }

    /**
     * La persona del personal con ese id; un alumno o un id que no es uuid no existe.
     *
     * @throws ModelNotFoundException<User>
     */
    public function findOrFail(string $id): User
    {
        return KeyLookup::findOrFail($this->staff->staffAccounts(), $id);
    }

    /**
     * Los últimos lotes del personal, el más nuevo primero.
     *
     * @return Collection<int, PhotoBatch>
     */
    public function recentBatches(int $limit = 5): Collection
    {
        return PhotoBatch::query()->where('kind', PhotoBatchKind::Personal)->latest()->latest('id')->limit($limit)->get();
    }

    /**
     * Un lote del personal; con el id de otro tipo, no existe.
     *
     * @throws ModelNotFoundException<PhotoBatch>
     */
    public function batch(string $id): PhotoBatch
    {
        return KeyLookup::findOrFail(PhotoBatch::query()->where('kind', PhotoBatchKind::Personal)->with('uploader:id,name'), $id);
    }
}
