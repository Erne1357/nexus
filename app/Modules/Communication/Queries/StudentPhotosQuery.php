<?php

namespace App\Modules\Communication\Queries;

use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentCredentialQuery;
use App\Modules\Students\Support\StudentPhotos;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de «Fotos de alumnos» (CO-06): los alumnos de todos los campus (Comunicación trabaja en todo el Tec) por número
 * de control, buscados por número o nombre, con los filtros «Sin foto / Con foto» y «Vigentes» (los que tienen credencial
 * vigente: activos y egresados), y los lotes en ZIP de alumnos. Las fotos viven en un disco, no en la base: el filtro de
 * foto cruza el listado del disco con los números de control en una sola consulta.
 */
final class StudentPhotosQuery
{
    public const int PER_PAGE = 25;

    public function __construct(private readonly StudentPhotos $photos) {}

    /**
     * Una página de alumnos por número de control. `$photo`: 'sin', 'con' o cualquier otro valor (todos).
     *
     * @return LengthAwarePaginator<int, Student>
     */
    public function page(string $search, string $photo, bool $onlyCurrent): LengthAwarePaginator
    {
        $query = Student::query()
            ->with(['career:id,code,name', 'campus:id,code,name'])
            ->tap(fn ($query) => TextSearch::apply($query, ['control_number', 'name'], $search))
            ->when($onlyCurrent, fn ($query) => $query->whereIn('status', array_map(fn (StudentStatus $status): string => $status->value, array_filter(StudentStatus::cases(), fn (StudentStatus $status): bool => StudentCredentialQuery::isCurrent($status)))));

        if ($photo === 'sin' || $photo === 'con') {
            $array = '{'.implode(',', array_map(fn (string $number): string => '"'.addcslashes($number, '"\\').'"', $this->photos->controlNumbersWithPhoto())).'}';
            $query->whereRaw(($photo === 'sin' ? 'not ' : '').'(lower(control_number) = any(?::text[]))', [$array]);
        }

        return $query->orderBy('control_number')->orderBy('id')->paginate(self::PER_PAGE);
    }

    /**
     * El alumno con ese id; un id que no es uuid no existe.
     *
     * @throws ModelNotFoundException<Student>
     */
    public function findOrFail(string $id): Student
    {
        return KeyLookup::findOrFail(Student::query()->with(['career:id,code,name', 'campus:id,code,name']), $id);
    }

    /**
     * Los últimos lotes de alumnos, el más nuevo primero.
     *
     * @return Collection<int, PhotoBatch>
     */
    public function recentBatches(int $limit = 5): Collection
    {
        return PhotoBatch::query()->where('kind', PhotoBatchKind::Alumnos)->latest()->latest('id')->limit($limit)->get();
    }

    /**
     * Un lote de alumnos; con el id de otro tipo, no existe.
     *
     * @throws ModelNotFoundException<PhotoBatch>
     */
    public function batch(string $id): PhotoBatch
    {
        return KeyLookup::findOrFail(PhotoBatch::query()->where('kind', PhotoBatchKind::Alumnos)->with('uploader:id,name'), $id);
    }
}
