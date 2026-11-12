<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Enums\Role;

/**
 * Lo que la campana muestra de una notificación: título, una línea de detalle, el icono (heroicon de
 * MaryUI) con su tono (`info`, `warning`, `success` o `neutral`) y a dónde lleva, como nombre de ruta y parámetros para que el destino pueda llegar después que el
 * aviso (si la ruta no existe, la campana avisa que no está disponible y no lleva). `termId` y `campusId` son el periodo y el campus
 * del aviso: al abrirlo, si el usuario trabaja en ese campus, la credencial cambia al periodo y la pantalla se abre en
 * el campus (`?campus=<código>`; OpenNotification). `role` es el puesto al que va el aviso
 * (`data.role`), o null si no va a uno: al abrirlo, la sesión actúa con ese puesto (OpenNotification).
 */
final readonly class NotificationMessage
{
    /**
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $icon,
        public ?string $route = null,
        public array $parameters = [],
        public ?string $termId = null,
        public ?string $campusId = null,
        public string $tone = 'info',
        public ?Role $role = null,
    ) {}

    public function withRole(?Role $role): self
    {
        return new self($this->title, $this->body, $this->icon, $this->route, $this->parameters, $this->termId, $this->campusId, $this->tone, $role);
    }

    /**
     * @return array{title: string, body: string, icon: string, tone: string, route: ?string, parameters: array<string, string>, term_id: ?string, campus_id: ?string, role?: string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'tone' => $this->tone,
            'route' => $this->route,
            'parameters' => $this->parameters,
            'term_id' => $this->termId,
            'campus_id' => $this->campusId,
            ...($this->role === null ? [] : ['role' => $this->role->value]),
        ];
    }

    /**
     * El mensaje guardado en `notifications.data`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) ($data['title'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            icon: (string) ($data['icon'] ?? 'o-bell'),
            route: $data['route'] ?? null,
            parameters: (array) ($data['parameters'] ?? []),
            termId: $data['term_id'] ?? null,
            campusId: $data['campus_id'] ?? null,
            tone: (string) ($data['tone'] ?? 'info'),
            role: is_string($data['role'] ?? null) ? Role::tryFrom($data['role']) : null,
        );
    }
}
