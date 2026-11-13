<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contraer la carga por modalidad (ADR-0025): el campus queda solo como el lugar, con código, nombre y estado (MS3,
 * supuesto por confirmar). Cada lector ya usa el catálogo `modalities` (MS1), así que se quitan `campuses.modality`,
 * `campuses.settings` (el perfil de flujo, que es de la modalidad) y el puente `campuses.modality_id`.
 *
 * Las filas de campus que eran modalidades (su `modality` no es presencial: EAD, ENL y TNMV, M2) se desactivan y se
 * borran lógicamente. Antes revisa que nada las referencie (referenceCounts()): la columna que apunta a `campuses.id` de
 * cada llave foránea a `campuses` (en una compuesta, solo esa; solo cuentan las llaves cuya columna destino es
 * `campuses.id`: una que apuntara a otra columna, como `campuses.code`, no se revisaría) y cada columna `campus_id`
 * (comparada como texto, sea del tipo que sea), de cualquier tabla y esquema (se leen del catálogo de PostgreSQL al
 * migrar, así que una tabla nueva también cuenta), los parámetros de `settings` con alcance de campus y los avisos
 * guardados con su campus. Si algo las referencia, se detiene con un RuntimeException que lista cada referencia con su
 * número y no escribe nada. La bitácora no cuenta: es historia y la fila borrada lógicamente sigue ahí (MS33, supuesto
 * por confirmar). Tampoco cuentan los ids de campus guardados dentro de un jsonb (las fotos v1, los alumnos posibles por
 * campus de una propuesta): las filas se borran lógicamente, no se quitan, así que esos ids siguen resolviendo.
 *
 * Antes, 2026_11_12_211101 (Scheduling) pasó los avisos guardados de la propuesta y de los acuerdos que llevaban solo
 * el campus a llevar su modalidad por el puente (MS14), que esta migración quita.
 *
 * Todo up() va en una transacción.
 *
 * down() devuelve las columnas (`modality` con `presencial` por omisión, como nace un campus) y el puente: un campus con
 * la clave de una modalidad recupera esa modalidad, su texto en `modality` y la fila sin borrar (desactivada); los demás
 * son presenciales. `settings` toma el perfil de su modalidad, no el que tenía el campus. Quita el `deleted_at` de toda
 * fila no presencial, también de una que ya estaba borrada antes de up(): la reversa es para las pruebas y para regresar
 * una base recién migrada, no para recuperar la historia de esas filas.
 */
return new class extends Migration
{
    /** El valor de `campuses.modality` de un campus que es un lugar. */
    private const string PLACE_MODALITY = 'presencial';

    /**
     * `modalities.code` → `campuses.modality` (la equivalencia de 2026_11_12_210001, congelada aquí para down()).
     *
     * @var array<string, string>
     */
    private const array CAMPUS_MODALITIES = [
        'PRES' => 'presencial',
        'EAD' => 'mixta',
        'ENL' => 'en_linea',
        'TNMV' => 'virtual_tecnm',
    ];

    public function up(): void
    {
        $retired = DB::table('campuses')->where('modality', '<>', self::PLACE_MODALITY)->orderBy('code')->pluck('code', 'id')->all();
        $references = array_filter($this->referenceCounts(array_keys($retired)));

        if ($references !== []) {
            throw new RuntimeException(sprintf(
                'Las filas de campus que eran modalidades (%s) no se pueden borrar porque algo las referencia: %s. Quita o mueve esas referencias antes de migrar.',
                implode(', ', $retired),
                implode(', ', array_map(fn (string $source, int $count): string => "{$source}: {$count}", array_keys($references), $references)),
            ));
        }

        DB::table('campuses')->whereIn('id', array_keys($retired))->update([
            'is_active' => false,
            'deleted_at' => DB::raw('coalesce(deleted_at, now())'),
            'updated_at' => now(),
        ]);

        Schema::table('campuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('modality_id');
            $table->dropColumn(['modality', 'settings']);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('campuses', 'modality_id')) {
            return;
        }

        Schema::table('campuses', function (Blueprint $table) {
            $table->string('modality')->default(self::PLACE_MODALITY);
            $table->jsonb('settings')->default('{}');
            $table->foreignUuid('modality_id')->nullable()->constrained('modalities');
        });

        DB::statement('update campuses set modality_id = modalities.id from modalities where modalities.code = campuses.code');
        DB::table('campuses')->whereNull('modality_id')->update(['modality_id' => DB::table('modalities')->where('code', 'PRES')->value('id')]);

        foreach (self::CAMPUS_MODALITIES as $code => $text) {
            DB::table('campuses')->whereIn('modality_id', DB::table('modalities')->where('code', $code)->select('id'))->update(['modality' => $text]);
        }

        DB::statement('update campuses set settings = modalities.settings from modalities where modalities.id = campuses.modality_id');
        DB::table('campuses')->where('modality', '<>', self::PLACE_MODALITY)->update(['deleted_at' => null]);
    }

    /**
     * Cuántas filas de cada fuente referencian esos campus, con todas las fuentes (también las que dan 0): cada columna
     * de una llave foránea a `campuses` y cada columna `campus_id` («tabla.columna», con el esquema si no es `public`),
     * los parámetros de `settings` de campus y los avisos guardados con su campus.
     *
     * @param  list<string>  $campusIds
     * @return array<string, int>
     */
    public function referenceCounts(array $campusIds): array
    {
        $ids = '{'.implode(',', $campusIds).'}';
        $counts = [];

        foreach ($this->referencingColumns() as $column) {
            $source = ($column->schema === 'public' ? '' : "{$column->schema}.")."{$column->table}.{$column->column}";
            $counts[$source] = (int) DB::selectOne(
                sprintf('select count(*) as total from %s.%s where %s::text = any (?::text[])', $this->quote($column->schema), $this->quote($column->table), $this->quote($column->column)),
                [$ids],
            )->total;
        }

        if (Schema::hasTable('settings')) {
            $counts['settings.scope_id (campus)'] = (int) DB::selectOne("select count(*) as total from settings where scope = 'campus' and scope_id = any (?::uuid[])", [$ids])->total;
        }

        if (Schema::hasTable('notifications')) {
            $counts['notifications.data.campus_id'] = (int) DB::selectOne("select count(*) as total from notifications where data::jsonb ->> 'campus_id' = any (?::text[])", [$ids])->total;
        }

        return $counts;
    }

    /**
     * La columna que apunta a `campuses.id` de cada llave foránea a `campuses` (en una compuesta, la que se empareja con
     * `id` en `confkey`) y las columnas `campus_id` de las tablas (no de las vistas), de cualquier esquema, sin
     * `campuses`.
     *
     * @return list<object{schema: string, table: string, column: string}>
     */
    private function referencingColumns(): array
    {
        return DB::select(<<<'SQL'
            select n.nspname as schema, c.relname as table, a.attname as column
            from pg_constraint k
            join pg_class c on c.oid = k.conrelid
            join pg_namespace n on n.oid = c.relnamespace
            join lateral unnest(k.conkey, k.confkey) as key_column(attnum, target_attnum) on true
            join pg_attribute a on a.attrelid = k.conrelid and a.attnum = key_column.attnum
            join pg_attribute target on target.attrelid = k.confrelid and target.attnum = key_column.target_attnum
            where k.contype = 'f' and k.confrelid = 'campuses'::regclass and target.attname = 'id'
            union
            select columns.table_schema, columns.table_name, columns.column_name
            from information_schema.columns
            join information_schema.tables on tables.table_schema = columns.table_schema and tables.table_name = columns.table_name
            where columns.column_name = 'campus_id' and columns.table_name <> 'campuses' and tables.table_type = 'BASE TABLE'
                and columns.table_schema not in ('pg_catalog', 'information_schema')
            order by 1, 2, 3
            SQL);
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
