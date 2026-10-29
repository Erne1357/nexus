<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El código de un periodo es el del SII: año y número, sin guión (`20271` Ene-Jun, `20272` verano, `20273`
 * Ago-Dic), el mismo que trae el kardex (confirmado el 29 de octubre de 2026). La migración que creó `terms`
 * todavía muestra el formato anterior (`2027-1`) en su comentario; no se edita porque ya está en `develop`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table terms add constraint terms_code_check check (code ~ '^[0-9]{4}[123]$')");
    }

    public function down(): void
    {
        DB::statement('alter table terms drop constraint terms_code_check');
    }
};
