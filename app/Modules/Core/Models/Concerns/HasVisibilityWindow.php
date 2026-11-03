<?php

namespace App\Modules\Core\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vigencia de lo que muestra la pantalla de acceso (LG-01 y LG-02): activo, sin inicio o ya empezado, y sin fin
 * o todavía no terminado; en su orden y luego por antigüedad. Lo usan LoginNotice y LoginShortcut.
 */
trait HasVisibilityWindow
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeVisibleAt(Builder $query, CarbonInterface $moment): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $moment))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $moment))
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }
}
