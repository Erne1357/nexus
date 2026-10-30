<?php

namespace App\Modules\Faculty\Rules;

use App\Modules\Faculty\Models\TeacherExternalLoad;
use Illuminate\Validation\ValidationException;

/**
 * Forma de las horas fuera del ITCJ (S16, supuesto por confirmar): origen obligatorio de hasta 255 caracteres y
 * de 1 a 40 horas. La unicidad por docente, periodo y origen la revisan las Actions, sin distinguir mayúsculas.
 */
final class ExternalLoadRules
{
    /** @var list<string> */
    public const array FIELDS = ['source', 'hours', 'notes'];

    /**
     * @param  array<string, mixed>  $data
     * @return array{source: string, hours: int, notes: ?string}
     *
     * @throws ValidationException
     */
    public function normalize(array $data): array
    {
        $source = trim((string) ($data['source'] ?? ''));
        $hours = $data['hours'] ?? null;
        $errors = [];

        if ($source === '') {
            $errors['source'] = 'El origen es obligatorio.';
        } elseif (mb_strlen($source) > 255) {
            $errors['source'] = 'El origen no puede pasar de 255 caracteres.';
        }

        if (filter_var($hours, FILTER_VALIDATE_INT) === false || (int) $hours < 1 || (int) $hours > 40) {
            $errors['hours'] = 'Las horas van de 1 a 40.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $notes = isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null;

        return ['source' => $source, 'hours' => (int) $hours, 'notes' => $notes];
    }

    /**
     * @return array{source: string, hours: int, notes: ?string}
     */
    public function logged(TeacherExternalLoad $load): array
    {
        return ['source' => $load->source, 'hours' => $load->hours, 'notes' => $load->notes];
    }
}
