{{--
    «Mis datos» del alumno (AC-15): sus datos escolares como los ficha del alumno (nombre, número de control, estatus y
    la línea de carrera, plan, campus y semestre) y, debajo, su acceso: usuario, correo institucional, de dónde sale su
    contraseña y el enlace para cambiarla.
--}}
<div class="space-y-5">
    <x-header title="Mis datos" subtitle="Tus datos escolares, como los trae el SII." separator />

    @if ($student === null)
        <x-alert
            title="Todavía no encontramos tus datos escolares."
            description="Si eres alumno, acude a Servicios Escolares."
            icon="o-information-circle"
            class="alert-info"
            data-testid="my-record-empty"
        />
    @else
        <section class="rounded-box border border-base-300 bg-base-100 p-5" data-testid="my-record">
            <h2 class="flex flex-wrap items-center gap-3 text-xl font-bold leading-tight md:text-2xl">
                {{ $student->name }}
                <span class="font-mono text-lg font-semibold text-neutral tabular-nums">{{ $student->control_number }}</span>
                @include('students::status-badge', ['status' => $student->status])
            </h2>
            <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                <span>Carrera <span class="font-medium text-base-content"><span class="font-mono">{{ $student->career->code }}</span> · {{ $student->career->name }}</span></span>
                <span>Plan <span class="font-mono font-medium text-base-content">{{ $student->studyPlan->code }}</span></span>
                <span>Campus <span class="font-medium text-base-content">{{ $student->campus->name }}</span></span>
                <span>Semestre <span class="font-mono font-medium text-base-content">{{ $student->current_semester ?? '—' }}</span></span>
            </p>
        </section>
    @endif

    <section class="rounded-box border border-base-300 bg-base-100 p-5" data-testid="my-access">
        <h2 class="text-lg font-bold leading-tight">Tu acceso</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-[auto_1fr]">
            <dt class="text-neutral">Usuario</dt>
            <dd class="font-mono font-medium">{{ $username }}</dd>
            <dt class="text-neutral">Correo institucional</dt>
            <dd class="font-medium break-all">{{ $email ?? 'Sin correo institucional' }}</dd>
        </dl>
        <p class="mt-3">
            @if ($usesSiiNip)
                Entras con tu NIP del SII. Puedes cambiarlo por una contraseña propia cuando quieras; no es obligatorio.
            @else
                Ya no entras con tu NIP del SII: entras con tu contraseña de Nexus.
            @endif
        </p>
        <x-button label="Cambiar contraseña" icon="o-key" link="{{ route('user-password.edit') }}" class="btn-outline mt-4" data-testid="change-password" />
    </section>
</div>
