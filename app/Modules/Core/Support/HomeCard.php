<?php

namespace App\Modules\Core\Support;

/**
 * Una tarjeta de Inicio que pone un módulo (HomeCardsRequested): título, una línea de lo que toca hacer y un enlace a
 * la pantalla donde se hace, con su icono (heroicon de MaryUI). `key` la distingue en la página (`data-testid`). Ya
 * viene redactada: Inicio la pinta tal cual, sin consultar nada.
 */
final readonly class HomeCard
{
    public function __construct(
        public string $key,
        public string $title,
        public string $body,
        public string $actionLabel,
        public string $url,
        public string $icon = 'o-bell',
    ) {}
}
