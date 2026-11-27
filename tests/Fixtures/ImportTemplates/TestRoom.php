<?php

namespace Tests\Fixtures\ImportTemplates;

use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un catálogo de un campus para las pruebas de "desactivar faltantes" (tarea 1.3), como `rooms`: Core
 * no tiene ninguno. Su tabla la crea cada prueba con createTable(), dentro de su transacción.
 */
class TestRoom extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'test_rooms';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @param  string  $codeType  el tipo de la columna `code`: otro que texto sirve para ver qué pasa cuando
     *                            la base guarda la clave distinto de como vino
     */
    public static function createTable(string $codeType = 'string'): void
    {
        Schema::create('test_rooms', function (Blueprint $table) use ($codeType) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('campus_id')->constrained('campuses');
            $table->{$codeType}('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public static function in(Campus $campus, string $code): self
    {
        $room = (new self)->forceFill(['campus_id' => $campus->id, 'code' => $code, 'name' => "Salón {$code}"]);
        $room->save();

        return $room;
    }
}
