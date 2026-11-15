<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién pidió el enlace para restablecer la contraseña (CF-08, confirmado el 14 de noviembre de 2026): `issued_by` es el
 * admin que lo mandó desde el panel (SendUserPasswordReset) y nulo si lo pidió el usuario con «¿Olvidaste tu
 * contraseña?» (AC-16). El del admin sirve aunque el correo del personal no esté confirmado, y al usarlo queda
 * confirmado (AC-18); el del autoservicio sigue pidiéndolo confirmado (PasswordResetRules::allows()). Un enlace nuevo
 * reemplaza la fila, así que solo cuenta quién pidió el último.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->foreignUuid('issued_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_by');
        });
    }
};
