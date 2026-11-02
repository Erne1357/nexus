<?php

namespace App\Modules\Core\Notifications;

/**
 * Lo que la campana muestra de una notificación: título, una línea de detalle, el icono (heroicon de
 * MaryUI) con su tono (`info`, `warning`, `success` o `neutral`) y a dónde lleva, como nombre de ruta y parámetros para que el destino pueda llegar después que el
 * aviso (si la ruta no existe, la campana lleva al inicio). `termId` y `campusId` son el periodo y el campus
 * del aviso: al abrirlo, la credencial cambia a ellos si el usuario trabaja ahí.
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
    ) {}

    /**
     * @return array{title: string, body: string, icon: string, tone: string, route: ?string, parameters: array<string, string>, term_id: ?string, campus_id: ?string}
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
        );
    }
}
