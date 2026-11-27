<?php

namespace App\Modules\Students\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Enums\RepositionReason;
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
 *
 * CO-12: la reposición exige el motivo y las dos respuestas del SIISAE (autodeclaradas). Con los datos incorrectos no se
 * puede pedir; si no ve su foto, la solicitud lleva también la foto, salvo que la foto no esté disponible (plazo o ya
 * hay una abierta): entonces la reposición sigue (supuesto por confirmar). La foto sola no pregunta nada del SIISAE.
 */
final class RequestCredential
{
    public const string DATA_INCORRECT_MESSAGE = 'Todavía no puedes pedir la reposición. Valida tus datos en el SIISAE. Si no te aparece la opción «Validar datos», espera a que se publique la convocatoria para validar datos en el SIISAE.';

    /**
     * @throws ValidationException
     */
    public function handle(Student $student, bool $reposition, bool $photo, User $by, ?string $reason = null, ?bool $siisaePhotoVisible = null, ?bool $siisaeDataCorrect = null): CredentialRequest
    {
        if (! $reposition && ! $photo) {
            throw ValidationException::withMessages(['request' => 'Elige qué quieres pedir.']);
        }

        $repositionReason = null;

        if ($reposition) {
            $repositionReason = RepositionReason::tryFrom((string) $reason);
            $errors = array_filter([
                'reason' => $repositionReason === null ? 'Elige el motivo de la reposición.' : null,
                'photo_visible' => $siisaePhotoVisible === null ? 'Contesta si en el SIISAE puedes ver tu fotografía.' : null,
                'data_correct' => $siisaeDataCorrect === null ? 'Contesta si en el SIISAE tus datos son correctos.' : null,
            ]);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if ($siisaeDataCorrect === false) {
                throw ValidationException::withMessages(['data_correct' => self::DATA_INCORRECT_MESSAGE]);
            }
        } else {
            $siisaePhotoVisible = $siisaeDataCorrect = null;
        }

        try {
            return DB::transaction(function () use ($student, $reposition, $photo, $by, $repositionReason, $siisaePhotoVisible, $siisaeDataCorrect): CredentialRequest {
                $locked = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

                if (! StudentCredentialQuery::isCurrent($locked->status)) {
                    throw ValidationException::withMessages(['request' => 'Solo quien tiene credencial vigente puede pedirla.']);
                }

                $availability = CredentialRequestRules::availability($locked, CarbonImmutable::now());

                // CO-12: sin foto en el SIISAE la solicitud la lleva; si la foto no está disponible, no se agrega y la reposición sigue.
                if ($reposition && $siisaePhotoVisible === false && $availability['photo'] === null) {
                    $photo = true;
                }

                foreach (['reposition' => $reposition, 'photo' => $photo] as $type => $wanted) {
                    if ($wanted && $availability[$type] !== null) {
                        throw ValidationException::withMessages([$type => $availability[$type]]);
                    }
                }

                $request = CredentialRequest::query()->create([
                    'student_id' => $locked->getKey(),
                    'wants_reposition' => $reposition,
                    'wants_photo' => $photo,
                    'reposition_reason' => $repositionReason,
                    'siisae_photo_visible' => $siisaePhotoVisible,
                    'siisae_data_correct' => $siisaeDataCorrect,
                    'status' => CredentialRequestStatus::Solicitada,
                    'requested_at' => now(),
                ]);

                activity()->performedOn($request)->causedBy($by)->event('credential_requested')
                    ->withProperties(['student_id' => $locked->getKey(), 'wants_reposition' => $reposition, 'wants_photo' => $photo, 'reposition_reason' => $repositionReason?->value, 'siisae_photo_visible' => $siisaePhotoVisible, 'siisae_data_correct' => $siisaeDataCorrect])
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
