<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una versión publicada de una carga: la foto que leen la consulta publicada y los exportes. Inmutable (HC-18). La
 * forma del `payload` la fija `Rules\SnapshotPayload` y la documenta docs/dominio/modelo-de-datos.md («Contenido del
 * snapshot»).
 */
#[Fillable(['academic_load_id', 'version', 'payload', 'created_by'])]
#[UseFactory(LoadSnapshotFactory::class)]
class LoadSnapshot extends Model
{
    /** @use HasFactory<LoadSnapshotFactory> */
    use HasFactory, HasUuids;

    public const ?string UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicLoad, $this>
     */
    public function academicLoad(): BelongsTo
    {
        return $this->belongsTo(AcademicLoad::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Solo la última versión de cada carga: la que no tiene otra más nueva.
     *
     * @param  Builder<LoadSnapshot>  $query
     */
    #[Scope]
    protected function latestVersion(Builder $query): void
    {
        $query->whereNotExists(fn ($newer) => $newer->from('load_snapshots as newer')
            ->whereColumn('newer.academic_load_id', 'load_snapshots.academic_load_id')
            ->whereColumn('newer.version', '>', 'load_snapshots.version'));
    }
}
