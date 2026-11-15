{{--
    Formulario de un aviso o un atajo, dentro del x-drawer de core::pages.login-content. Los campos se llaman como las claves
    de las Actions (`title`, `link_url`, `starts_at`, `image`, `url`, `file`), así sus errores caen en su campo. Nada se
    guarda hasta «Guardar». La foto del aviso es jpg, png o webp de hasta 5 MB; el archivo del atajo, pdf de hasta 10 MB.
--}}
@php($isNotices = $tab === 'notices')
<form wire:submit="save" class="flex h-dvh flex-col" data-testid="login-content-form">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="login-content-drawer-title" class="text-xl font-bold leading-tight">
                {{ $editingId === null ? ($isNotices ? 'Nuevo aviso' : 'Nuevo atajo') : ($isNotices ? 'Editar aviso' : 'Editar atajo') }}
            </h2>
            <p class="mt-0.5 text-sm text-neutral">
                {{ $isNotices ? 'Sale en el carrusel de la pantalla de acceso, con su foto y su enlace si los tiene.' : 'Sale en el botón de atajos de la pantalla de acceso, sin iniciar sesión.' }}
            </p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:size-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
        @unless ($isNotices)
            <x-select label="Tipo" wire:model.live="kind" :options="$kinds" />
        @endunless

        <x-input label="Título" name="title" wire:model="title" maxlength="{{ $isNotices ? 120 : 80 }}" />

        @if ($isNotices)
            <x-input label="Enlace (opcional)" name="link_url" wire:model="link_url" placeholder="https://" hint="Solo enlaces que empiezan con http:// o https://." />

            <div>
                <x-file label="Foto (opcional)" name="image" wire:model="image" accept="image/jpeg,image/png,image/webp" hint="jpg, png o webp de hasta 5 MB." />
                @if ($editingId !== null && $this->currentUrl() !== null && $image === null)
                    <div class="mt-2 flex items-center gap-3">
                        <img src="{{ $this->currentUrl() }}" alt="Foto actual del aviso" class="h-16 rounded-field border border-base-300" />
                        <x-checkbox label="Quitar la foto" wire:model="remove_image" />
                    </div>
                @endif
            </div>
        @else
            <x-input label="Descripción (opcional)" name="description" wire:model="description" maxlength="160" />

            @if ($kind === 'descarga')
                <div>
                    <x-file label="Archivo" name="file" wire:model="file" accept="application/pdf" hint="pdf de hasta 10 MB." />
                    @if ($editingId !== null && $currentFile !== null && $file === null)
                        <p class="mt-2 text-sm text-neutral">Archivo actual: <a href="{{ $this->currentUrl() }}" target="_blank" rel="noopener" class="text-primary underline">{{ basename($currentFile) }}</a>. Sube otro para reemplazarlo.</p>
                    @endif
                </div>
            @else
                <x-input label="Enlace" name="url" wire:model="url" placeholder="https://" hint="Solo enlaces que empiezan con http:// o https://." />
            @endif
        @endif

        <div class="grid gap-3 sm:grid-cols-2">
            <x-input label="Inicio (opcional)" name="starts_at" type="datetime-local" wire:model="starts_at" />
            <x-input label="Fin (opcional)" name="ends_at" type="datetime-local" wire:model="ends_at" />
        </div>
        <p class="text-sm text-neutral">Sin fechas, se ve siempre que esté activo.</p>
    </div>

    <footer class="flex justify-end gap-2 border-t border-base-300 px-5 py-3">
        <x-button label="Cancelar" @click="close()" />
        <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
    </footer>
</form>
