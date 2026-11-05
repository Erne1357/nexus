<?php

namespace App\Modules\Imports\Sii;

use Normalizer;

/**
 * El nombre de una materia del SII como lo guarda Nexus (R12, supuesto por confirmar). El SII lo da en mayúsculas y
 * casi siempre sin acentos: title() lo pasa a tipo título con las palabras cortas en minúsculas (salvo la primera),
 * los números romanos en mayúsculas y los acentos de un diccionario pequeño de palabras que no son ambiguas sin
 * ellos; los acentos que ya trae se respetan. key() da la llave con que se comparan dos nombres: mayúsculas, sin
 * acentos y sin espacios repetidos.
 */
final class SubjectName
{
    /** @var list<string> */
    private const array SMALL_WORDS = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'en', 'a', 'el', 'para', 'por', 'con', 'al'];

    /**
     * Palabras con su acento, por su forma sin acentos en mayúsculas.
     *
     * @var array<string, string>
     */
    private const array ACCENTED = [
        'ADMINISTRACION' => 'Administración',
        'ALGEBRA' => 'Álgebra',
        'ANALISIS' => 'Análisis',
        'AUDITORIA' => 'Auditoría',
        'AUTOMATIZACION' => 'Automatización',
        'BASICA' => 'Básica',
        'BASICAS' => 'Básicas',
        'CALCULO' => 'Cálculo',
        'COMPUTACION' => 'Computación',
        'COMUNICACION' => 'Comunicación',
        'DINAMICA' => 'Dinámica',
        'ECONOMIA' => 'Economía',
        'ELECTRICA' => 'Eléctrica',
        'ELECTRONICA' => 'Electrónica',
        'ESTADISTICA' => 'Estadística',
        'ETICA' => 'Ética',
        'FILOSOFIA' => 'Filosofía',
        'FISICA' => 'Física',
        'GESTION' => 'Gestión',
        'GRAFICA' => 'Gráfica',
        'INFORMACION' => 'Información',
        'INGENIERIA' => 'Ingeniería',
        'INTEGRACION' => 'Integración',
        'INTRODUCCION' => 'Introducción',
        'INVESTIGACION' => 'Investigación',
        'LOGICA' => 'Lógica',
        'LOGISTICA' => 'Logística',
        'MATEMATICA' => 'Matemática',
        'MATEMATICAS' => 'Matemáticas',
        'MECANICA' => 'Mecánica',
        'METODO' => 'Método',
        'METODOS' => 'Métodos',
        'NUMERICO' => 'Numérico',
        'NUMERICOS' => 'Numéricos',
        'ORGANIZACION' => 'Organización',
        'PLANEACION' => 'Planeación',
        'PRACTICA' => 'Práctica',
        'PRACTICAS' => 'Prácticas',
        'PRODUCCION' => 'Producción',
        'PROGRAMACION' => 'Programación',
        'PSICOLOGIA' => 'Psicología',
        'QUIMICA' => 'Química',
        'SIMULACION' => 'Simulación',
        'SOCIOLOGIA' => 'Sociología',
        'TECNICA' => 'Técnica',
        'TECNICAS' => 'Técnicas',
        'TECNOLOGIA' => 'Tecnología',
        'TECNOLOGIAS' => 'Tecnologías',
        'TEORIA' => 'Teoría',
        'TERMODINAMICA' => 'Termodinámica',
    ];

    /**
     * El nombre en tipo título. Las palabras son las secuencias de letras: la puntuación (paréntesis, guiones)
     * se queda donde está.
     */
    public static function title(string $name): string
    {
        $first = true;

        return (string) preg_replace_callback('/\p{L}+/u', function (array $match) use (&$first): string {
            $word = self::word($match[0], $first);
            $first = false;

            return $word;
        }, self::collapse($name));
    }

    /**
     * La llave para comparar nombres: mayúsculas, sin acentos y con los espacios juntos.
     */
    public static function key(string $name): string
    {
        return mb_strtoupper(self::withoutAccents(self::collapse($name)));
    }

    private static function word(string $word, bool $first): string
    {
        $upper = mb_strtoupper($word);

        if (preg_match('/^(X{0,3})(IX|IV|V?I{0,3})$/', $upper) === 1) {
            return $upper;
        }

        $lower = mb_strtolower($word);

        if (! $first && in_array($lower, self::SMALL_WORDS, true)) {
            return $lower;
        }

        return self::ACCENTED[self::withoutAccents($upper)] ?? mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
    }

    private static function collapse(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private static function withoutAccents(string $text): string
    {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

        return (string) preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $text : $decomposed);
    }
}
