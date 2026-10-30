<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Database\Factories\TeacherAvailabilityFactory;
use App\Modules\Faculty\Enums\AvailabilityKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bloque de un docente en un periodo: no disponible o preferido. Por defecto todo bloque del campus está disponible; aquí solo van las excepciones y preferencias. No se traslapa con otro del mismo docente, periodo y día.
 */
#[Fillable(['teacher_id', 'term_id', 'day', 'starts_at', 'ends_at', 'kind'])]
#[UseFactory(TeacherAvailabilityFactory::class)]
class TeacherAvailability extends Model
{
    /** @use HasFactory<TeacherAvailabilityFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'teacher_availability';

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'kind' => AvailabilityKind::class,
        ];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * Los bloques de un periodo.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forTerm(Builder $query, Term $term): void
    {
        $query->where('term_id', $term->id);
    }
}
