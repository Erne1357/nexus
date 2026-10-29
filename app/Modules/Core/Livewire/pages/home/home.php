<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página de inicio: saludo y un bloque por cada rol que el usuario ejerce (ScopedContext::roles()),
 * con su nombre (Role::label()) y lo que ese rol hace en Nexus según el glosario. El admin, que
 * tiene todos los roles, ve solo el suyo.
 */
new #[Title('Inicio')] class extends Component
{
    /**
     * @return array{name: string, blocks: list<array{title: string, description: string}>}
     */
    public function with(ScopedContext $context): array
    {
        return [
            'name' => $context->user()?->name ?? '',
            'blocks' => array_map(fn (Role $role): array => [
                'title' => $role->label(),
                'description' => $this->description($role),
            ], $context->roles()),
        ];
    }

    private function description(Role $role): string
    {
        return match ($role) {
            Role::Admin => 'Tienes acceso a todo Nexus, en todos los campus. Tus acciones quedan en la bitácora como las de cualquier usuario.',
            Role::Subdireccion => 'Si el campus lo tiene configurado, das el visto bueno final antes de publicar la carga académica.',
            Role::JefeDepartamento => 'Validas los catálogos de tu departamento, asignas docentes y salones a los grupos de sus materias y cierras su carga.',
            Role::JefeProyectoDocente => 'Junto con el jefe de departamento, asignas docentes a los grupos según la prioridad de base.',
            Role::Coordinador => 'Analizas el avance de los alumnos de tu carrera, propones qué materias y cuántos grupos abrir y armas la propuesta de horarios por semestre.',
            Role::Docente => 'Consultas tu propia carga: los grupos que impartes y sus horarios.',
        };
    }
};
