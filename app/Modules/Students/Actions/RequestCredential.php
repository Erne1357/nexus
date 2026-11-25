<?php

namespace App\Modules\Students\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentCredentialQuery;
use App\Modules\Students\Rules\CredentialRequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CO-07: el alumno pide la reposición de su credencial física, la foto o las dos. Exige credencial vigente (CO-S4),
 * ninguna abierta de los tipos pedidos y el plazo de cada tipo cumplido. Bloquea la fila del alumno mientras revisa; si
 * dos peticiones pasan a la vez, el índice parcial detiene a la segunda y se traduce al mismo mensaje.
 */
final class RequestCredential
{
    /**
     * @throws ValidationException
     */
    public function handle(Student $student, bool $reposition, bool $photo, User $by): CredentialRequest
    {
        if (! $reposition && ! $photo) {
            throw ValidationException::withMessages(['request' => 'Elige qué quieres pedir.']);
        }

        try {
            return DB::transaction(function () use ($student, $reposition, $photo, $by): CredentialRequest {
                $locked = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

                if (! StudentCredentialQuery::isCurrent($locked->status)) {
                    throw ValidationException::withMessages(['request' => 'Solo quien tiene credencial vigente puede pedirla.']);
                }

                $availability = CredentialRequestRules::availability($locked, CarbonImmutable::now());

                foreach (['reposition' => $reposition, 'photo' => $photo] as $type => $wanted) {
                    if ($wanted && $availability[$type] !== null) {
                        throw ValidationException::withMessages([$type => $availability[$type]]);
                    }
                }

                $request = CredentialRequest::query()->create([
                    'student_id' => $locked->getKey(),
                    'wants_reposition' => $reposition,
                    'wants_photo' => $photo,
                    'status' => CredentialRequestStatus::Solicitada,
                    'requested_at' => now(),
                ]);

                activity()->performedOn($request)->causedBy($by)->event('credential_requested')
                    ->withProperties(['student_id' => $locked->getKey(), 'wants_reposition' => $reposition, 'wants_photo' => $photo])
                    ->log('Pidió '.($reposition && $photo ? 'la reposición de la credencial y la foto' : ($reposition ? 'la reposición de la credencial' : 'la foto')));

                return $request;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['request' => CredentialRequestRules::OPEN_MESSAGE]);
            }

            throw $exception;
        }
    }
}
