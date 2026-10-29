<?php

namespace App\Modules\Students\Enums;

/**
 * Color de una cadena de seriación en la retícula del alumno (tarea 1.6; DESIGN.md, "Retícula del alumno"): la
 * franja a la izquierda de cada materia de la cadena y la muestra de la leyenda. Usa los tokens `chain-1` a
 * `chain-12` del tema, medidos contra los rellenos de la retícula en claro y en oscuro. Con más de doce cadenas
 * los colores se repiten; la leyenda y el nombre de cada cadena las distinguen, porque el color nunca va solo.
 */
enum ChainColor: string
{
    case Chain1 = 'chain_1';
    case Chain2 = 'chain_2';
    case Chain3 = 'chain_3';
    case Chain4 = 'chain_4';
    case Chain5 = 'chain_5';
    case Chain6 = 'chain_6';
    case Chain7 = 'chain_7';
    case Chain8 = 'chain_8';
    case Chain9 = 'chain_9';
    case Chain10 = 'chain_10';
    case Chain11 = 'chain_11';
    case Chain12 = 'chain_12';

    /**
     * El color de la cadena `$chain` de StudentRecord::$chains, en orden.
     */
    public static function forChain(int $chain): self
    {
        return self::cases()[$chain % count(self::cases())];
    }

    /**
     * La franja de la materia: una sombra interior a la izquierda, para no tocar el borde de su estado. Van
     * completas, sin armar, para que Tailwind las encuentre.
     */
    public function stripeClasses(): string
    {
        return match ($this) {
            self::Chain1 => 'shadow-[inset_5px_0_0_var(--color-chain-1)]',
            self::Chain2 => 'shadow-[inset_5px_0_0_var(--color-chain-2)]',
            self::Chain3 => 'shadow-[inset_5px_0_0_var(--color-chain-3)]',
            self::Chain4 => 'shadow-[inset_5px_0_0_var(--color-chain-4)]',
            self::Chain5 => 'shadow-[inset_5px_0_0_var(--color-chain-5)]',
            self::Chain6 => 'shadow-[inset_5px_0_0_var(--color-chain-6)]',
            self::Chain7 => 'shadow-[inset_5px_0_0_var(--color-chain-7)]',
            self::Chain8 => 'shadow-[inset_5px_0_0_var(--color-chain-8)]',
            self::Chain9 => 'shadow-[inset_5px_0_0_var(--color-chain-9)]',
            self::Chain10 => 'shadow-[inset_5px_0_0_var(--color-chain-10)]',
            self::Chain11 => 'shadow-[inset_5px_0_0_var(--color-chain-11)]',
            self::Chain12 => 'shadow-[inset_5px_0_0_var(--color-chain-12)]',
        };
    }

    /**
     * El borde de la muestra de la cadena en la leyenda.
     */
    public function swatchClasses(): string
    {
        return match ($this) {
            self::Chain1 => 'border-chain-1',
            self::Chain2 => 'border-chain-2',
            self::Chain3 => 'border-chain-3',
            self::Chain4 => 'border-chain-4',
            self::Chain5 => 'border-chain-5',
            self::Chain6 => 'border-chain-6',
            self::Chain7 => 'border-chain-7',
            self::Chain8 => 'border-chain-8',
            self::Chain9 => 'border-chain-9',
            self::Chain10 => 'border-chain-10',
            self::Chain11 => 'border-chain-11',
            self::Chain12 => 'border-chain-12',
        };
    }
}
