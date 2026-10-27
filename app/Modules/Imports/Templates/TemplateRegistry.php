<?php

namespace App\Modules\Imports\Templates;

use Illuminate\Container\Container;
use InvalidArgumentException;

/**
 * Plantillas de importación por clave. Se arma una vez con la lista de ImportsServiceProvider y
 * revisa ahí lo que haría fallar un lote más tarde: una clase que no es plantilla, dos plantillas con
 * la misma clave, una clave natural vacía o fuera de los campos, o un campo que solo maneja el
 * sistema. Guarda solo clases: cada get() pide la plantilla al contenedor de la petición en curso
 * (Container::getInstance(), que Octane cambia en cada petición), así que puede vivir como singleton.
 */
final class TemplateRegistry
{
    /**
     * Columnas que pone el sistema: una importación no las escribe (`is_active` sí se importa en un
     * alta; en un cambio, apply() lo ignora).
     */
    private const array RESERVED_FIELDS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * @var array<string, class-string<ImportTemplate>>
     */
    private readonly array $templates;

    /**
     * @param  list<class-string<ImportTemplate>>  $templates
     *
     * @throws InvalidArgumentException
     */
    public function __construct(array $templates)
    {
        $byKey = [];

        foreach ($templates as $class) {
            if (! is_subclass_of($class, ImportTemplate::class)) {
                throw new InvalidArgumentException("{$class} no extiende ImportTemplate.");
            }

            $template = $this->make($class);
            $key = $template->key();

            if (isset($byKey[$key])) {
                throw new InvalidArgumentException("Dos plantillas usan la clave '{$key}': {$byKey[$key]} y {$class}.");
            }

            $fields = array_keys($template->fields());
            $naturalKey = $template->naturalKey();

            if ($naturalKey === [] || array_diff($naturalKey, $fields) !== []) {
                throw new InvalidArgumentException("La clave natural de {$class} debe ser uno o más de sus campos.");
            }

            if ($reserved = array_intersect($fields, self::RESERVED_FIELDS)) {
                throw new InvalidArgumentException("{$class} no puede importar ".implode(', ', $reserved).': las pone el sistema.');
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

        return $this->make($this->templates[$key]);
    }

    /**
     * Una instancia de cada plantilla, por clave y en el orden en que se registraron.
     *
     * @return array<string, ImportTemplate>
     */
    public function all(): array
    {
        return array_map($this->make(...), $this->templates);
    }

    /**
     * @param  class-string<ImportTemplate>  $class
     */
    private function make(string $class): ImportTemplate
    {
        return Container::getInstance()->make($class);
    }
}
