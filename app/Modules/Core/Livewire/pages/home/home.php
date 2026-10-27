<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página de inicio: saludo y un bloque por cada rol que el usuario ejerce (ScopedContext::has()),
 * con lo que ese rol hace en Nexus según el glosario. El admin, que tiene todos los roles, ve solo
 * el suyo.
 */
new #[Title('Inicio')] class extends Component
{
    /**
     * @return array{name: string, blocks: list<array{title: string, description: string}>}
     */
    public function with(ScopedContext $context): array
    {
        $roles = $context->isAdmin()
            ? [Role::Admin]
            : array_values(array_filter(Role::cases(), fn (Role $role): bool => $context->has($role)));

        return [
            'name' => $context->user()?->name ?? '',
            'blocks' => array_map($this->block(...), $roles),
        ];
    }

    /**
     * @return array{title: string, description: string}
     */
    private function block(Role $role): array
    {
        return match ($role) {
            Role::Admin => [
                'title' => 'Administración',
                'description' => 'Tienes acceso a todo Nexus, en todos los campus. Tus acciones quedan en la bitácora como las de cualquier usuario.',
            ],
            Role::Subdireccion => [
                'title' => 'Subdirección Académica',
                'description' => 'Si el campus lo tiene configurado, das el visto bueno final antes de publicar la carga académica.',
            ],
            Role::JefeDepartamento => [
                'title' => 'Jefe de departamento académico',
                'description' => 'Validas los catálogos de tu departamento, asignas docentes y salones a los grupos de sus materias y cierras su carga.',
            ],
            Role::JefeProyectoDocente => [
                'title' => 'Jefe de proyecto docente',
                'description' => 'Junto con el jefe de departamento, asignas docentes a los grupos según la prioridad de base.',
            ],
            Role::Coordinador => [
                'title' => 'Coordinador de carrera',
                'description' => 'Analizas el avance de los alumnos de tu carrera, propones qué materias y cuántos grupos abrir y armas la propuesta de horarios por semestre.',
            ],
            Role::Docente => [
                'title' => 'Docente',
                'description' => 'Consultas tu propia carga: los grupos que impartes y sus horarios.',
            ],
        };
    }
};
