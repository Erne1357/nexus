<?php

namespace App\Modules\Scheduling\Validation;

use App\Modules\Scheduling\Enums\ConflictSeverity;

/**
 * Lo que una regla encontró: su id (`HC-xx`, `SC-xx`), su gravedad, el mensaje en español para el usuario, de qué se
 * trata (`group`, `teacher` o `room` con su id), el grupo afectado (un choque entre dos grupos da una violación por
 * cada uno, para que cada departamento y carrera lo cuente) y el detalle (`related`) que la pantalla puede mostrar
 * sin recalcular. Es la forma de una fila de `schedule_conflicts`.
 */
final readonly class Violation
{
    /**
     * @param  'group'|'teacher'|'room'  $subjectType
     * @param  array<string, mixed>  $related
     */
    public function __construct(
        public string $rule,
        public ConflictSeverity $severity,
        public string $message,
        public string $subjectType,
        public string $subjectId,
        public ?string $groupId = null,
        public array $related = [],
    ) {}

    /**
     * Choque duro.
     *
     * @param  'group'|'teacher'|'room'  $subjectType
     * @param  array<string, mixed>  $related
     */
    public static function hard(string $rule, string $message, string $subjectType, string $subjectId, ?string $groupId = null, array $related = []): self
    {
        return new self($rule, ConflictSeverity::Hard, $message, $subjectType, $subjectId, $groupId, $related);
    }

    /**
     * Aviso (regla blanda, o dura que solo se reporta mientras se acomoda).
     *
     * @param  'group'|'teacher'|'room'  $subjectType
     * @param  array<string, mixed>  $related
     */
    public static function soft(string $rule, string $message, string $subjectType, string $subjectId, ?string $groupId = null, array $related = []): self
    {
        return new self($rule, ConflictSeverity::Soft, $message, $subjectType, $subjectId, $groupId, $related);
    }

    /**
     * Llave para no repetir la misma violación: regla, sujeto, grupo, mensaje y las sesiones que la causan (dos sesiones
     * contiguas del mismo grupo que chocan con la misma sesión de otro dan el mismo mensaje, pero son dos choques).
     */
    public function key(): string
    {
        return implode('|', [
            $this->rule, $this->subjectType, $this->subjectId, $this->groupId ?? '', $this->message,
            (string) ($this->related['session_id'] ?? ''), (string) ($this->related['other_session_id'] ?? ''),
        ]);
    }
}
