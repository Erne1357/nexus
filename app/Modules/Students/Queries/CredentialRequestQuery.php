<?php

namespace App\Modules\Students\Queries;

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Las solicitudes de credencial para las pantallas (CO-07, CO-10): la bandeja de Comunicación por estado y con búsqueda,
 * el conteo de cada pestaña y el historial de un alumno.
 */
final class CredentialRequestQuery
{
    /**
     * La bandeja: las más viejas primero, con el alumno; `$search` busca por número de control o nombre.
     *
     * @return LengthAwarePaginator<int, CredentialRequest>
     */
    public function tray(?CredentialRequestStatus $status, string $search, int $perPage = 20): LengthAwarePaginator
    {
        $search = trim($search);

        return CredentialRequest::query()
            ->with('student.career')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->whereHas('student', fn ($students) => $students
                ->where('control_number', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('requested_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Una solicitud con su alumno y carrera, por llave: un id que no es uuid es un 404, no un error de la base (ADR-0014).
     */
    public function findOrFail(string $id): CredentialRequest
    {
        return KeyLookup::findOrFail(CredentialRequest::query()->with('student.career'), $id);
    }

    /**
     * Cuántas solicitudes hay en cada estado (`solicitada` => 3), con 0 en los que no tienen.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = CredentialRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(CredentialRequestStatus::cases())
            ->mapWithKeys(fn (CredentialRequestStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    /**
     * Todas las solicitudes del alumno, la más reciente primero.
     *
     * @return Collection<int, CredentialRequest>
     */
    public function history(Student $student): Collection
    {
        return CredentialRequest::query()
            ->where('student_id', $student->getKey())
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();
    }
}
