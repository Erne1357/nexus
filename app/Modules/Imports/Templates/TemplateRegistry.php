<?php

namespace App\Modules\Imports\Templates;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Plantillas de importación por clave. Se arma una vez con la lista de ImportsServiceProvider y
 * revisa ahí lo que haría fallar un lote más tarde: una clase que no es plantilla, dos plantillas con
 * la misma clave o una clave natural vacía o fuera de los campos. Solo guarda clases, nunca
 * instancias: cada get() pide al contenedor una plantilla nueva, así que puede vivir como singleton
 * con Octane.
 */
final class TemplateRegistry
{
    /**
     * @var array<string, class-string<ImportTemplate>>
     */
    private readonly array $templates;

    /**
     * @param  list<class-string<ImportTemplate>>  $templates
     *
     * @throws InvalidArgumentException
     */
    public function __construct(private readonly Container $container, array $templates)
    {
        $byKey = [];

        foreach ($templates as $class) {
            if (! is_subclass_of($class, ImportTemplate::class)) {
                throw new InvalidArgumentException("{$class} no extiende ImportTemplate.");
            }

            $template = $container->make($class);
            $key = $template->key();

            if (isset($byKey[$key])) {
                throw new InvalidArgumentException("Dos plantillas usan la clave '{$key}': {$byKey[$key]} y {$class}.");
            }

            $naturalKey = $template->naturalKey();

            if ($naturalKey === [] || array_diff($naturalKey, array_keys($template->fields())) !== []) {
                throw new InvalidArgumentException("La clave natural de {$class} debe ser uno o más de sus campos.");
            }

            $byKey[$key] = $class;
        }

        $this->templates = $byKey;
    }

    public function has(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    /**
     * @throws InvalidArgumentException si la clave no está registrada
     */
    public function get(string $key): ImportTemplate
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("No hay plantilla de importación '{$key}'.");
        }

        return $this->container->make($this->templates[$key]);
    }

    /**
     * Una instancia de cada plantilla, por clave y en el orden en que se registraron.
     *
     * @return array<string, ImportTemplate>
     */
    public function all(): array
    {
        return array_map(fn (string $class): ImportTemplate => $this->container->make($class), $this->templates);
    }
}
