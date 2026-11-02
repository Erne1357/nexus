<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\AcademicLoad\Database\Factories\LoadTransitionFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una transición de una carga: de qué estado a cuál, quién y con qué comentario. Solo se inserta (HC-18):
 * la base rechaza editarla o borrarla. Sin `updated_at`.
 */
#[Fillable(['academic_load_id', 'from_status', 'to_status', 'user_id', 'comment'])]
#[UseFactory(LoadTransitionFactory::class)]
class LoadTransition extends Model
{
    /** @use HasFactory<LoadTransitionFactory> */
    use HasFactory, HasUuids;

    public const ?string UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => LoadStatus::class,
            'to_status' => LoadStatus::class,
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
