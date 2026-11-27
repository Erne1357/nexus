<?php

namespace App\Modules\AcademicLoad\Rules;

use Illuminate\Validation\ValidationException;

/**
 * Lo que el coordinador captura en una fila de la propuesta de carrera (reglas-de-negocio.md, "Propuesta de
 * carrera", supuesto por confirmar): alumnos esperados de 0 a 1000, grupos de 0 a 30 y notas de hasta 500
 * caracteres. La página puede usar los topes para su propia validación de forma.
 */
final class ProposalItemRules
{
    public const int MAX_EXPECTED_STUDENTS = 1000;

    public const int MAX_REQUESTED_GROUPS = 30;

    public const int MAX_NOTES_LENGTH = 500;

    /**
     * Los valores listos para guardar, con las notas recortadas (vacías, nulas), o una ValidationException con
     * el error de cada campo fuera de rango.
     *
     * @return array{expected_students: int, requested_groups: int, notes: ?string}
     *
     * @throws ValidationException
     */
    public function normalize(int $expectedStudents, int $requestedGroups, ?string $notes): array
    {
        $notes = $notes === null || trim($notes) === '' ? null : trim($notes);
        $errors = [];

        if ($expectedStudents < 0 || $expectedStudents > self::MAX_EXPECTED_STUDENTS) {
            $errors['expected_students'] = 'Los alumnos esperados van de 0 a '.self::MAX_EXPECTED_STUDENTS.'.';
        }

        if ($requestedGroups < 0 || $requestedGroups > self::MAX_REQUESTED_GROUPS) {
            $errors['requested_groups'] = 'Los grupos solicitados van de 0 a '.self::MAX_REQUESTED_GROUPS.'.';
        }

        if ($notes !== null && mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
            $errors['notes'] = 'Las notas llevan hasta '.self::MAX_NOTES_LENGTH.' caracteres.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['expected_students' => $expectedStudents, 'requested_groups' => $requestedGroups, 'notes' => $notes];
    }
}
