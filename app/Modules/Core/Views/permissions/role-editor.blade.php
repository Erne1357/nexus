{{--
    «Editar rol» y «Nuevo rol» (core::pages.permissions; ADR-0023), dentro del x-drawer: los avisos de un rol de fábrica
    (CF-02: «Sigue al código»; si ya no lo sigue, «Restablecer»; si el código agregó permisos, cuántos con «Aplicar»), el
    nombre y la descripción (CF-S2), los puestos que lo tienen y la lista de permisos por grupo
    (core::permissions.picker), con los candados (CF-03) y lo que algún puesto que lo tiene no acota (CF-04)
    deshabilitados con su motivo. Al pie, si guardar lo deja de seguir al código, lo dice. Guardar llama a `saveRole`; los
    errores de la Action salen junto a su campo. `close()` es del x-drawer. Espera `$editor`.
--}}
<div class="flex h-dvh flex-col" data-testid="role-editor-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="role-editor-title" class="text-xl font-bold leading-tight">{{ $editor['title'] }}</h2>
            <p class="mt-0.5 text-sm text-neutral">{{ $editor['subtitle'] }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:size-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
        @error('permission_set')
            <x-alert icon="o-x-circle" class="alert-error alert-soft" data-testid="role-editor-error">{{ $message }}</x-alert>
        @enderror

        @if ($editor['newFactory'] !== [])
            @php($newCount = count($editor['newFactory']))
            <div class="flex flex-wrap items-start gap-3 rounded-box border border-warning/40 bg-warning/10 px-4 py-3" role="status" data-testid="editor-new-factory">
                <x-icon name="o-sparkles" class="mt-0.5 h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ $newCount }} {{ $newCount === 1 ? 'permiso nuevo de fábrica' : 'permisos nuevos de fábrica' }}</p>
                    <p class="text-sm">{{ implode(' · ', $editor['newFactory']) }}</p>
                </div>
                <x-button label="Aplicar" class="btn-sm btn-outline bg-base-100 max-md:h-11" wire:click="applyFactoryUpdates('{{ $editor['id'] }}')" spinner="applyFactoryUpdates('{{ $editor['id'] }}')" />
            </div>
        @endif

        @if ($editor['follows'])
            <div class="flex items-start gap-3 rounded-box border border-base-300 bg-secondary/40 px-4 py-3" data-testid="editor-follows">
                <x-icon name="o-code-bracket" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
                <div>
                    <p class="font-semibold">Sigue al código</p>
                    <p class="text-sm text-neutral">Tiene lo que el código le concede a {{ $editor['factoryLabel'] }}. Si guardas un cambio en sus permisos, deja de seguirlo y guarda su propia lista.</p>
                </div>
            </div>
        @elseif ($editor['edited'])
            <div class="flex flex-wrap items-start gap-3 rounded-box border border-base-300 bg-secondary/40 px-4 py-3" data-testid="editor-edited">
                <x-icon name="o-pencil-square" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">Ya no sigue al código</p>
                    <p class="text-sm text-neutral">Guarda su propia lista. «Restablecer» lo regresa a lo que el código le concede a {{ $editor['factoryLabel'] }}.</p>
                </div>
                <x-button
                    label="Restablecer"
                    class="btn-sm btn-outline bg-base-100 max-md:h-11"
                    wire:click="restoreRole('{{ $editor['id'] }}')"
                    x-confirm="Vuelve a seguir al código y se pierde su lista propia."
                    data-confirm-title="¿Restablecer «{{ $editor['subtitle'] }}»?"
                    data-confirm-button="Restablecer"
                    data-confirm-tone="warning"
                    spinner="restoreRole('{{ $editor['id'] }}')"
                />
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            <x-input label="Nombre" name="name" wire:model="name" maxlength="80" placeholder="Por ejemplo, Consulta de catálogos" />
            <x-input label="Descripción (opcional)" name="description" wire:model="description" maxlength="240" />
        </div>

        <section class="space-y-2" aria-labelledby="editor-holders-title">
            <h3 id="editor-holders-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Lo tienen</h3>
            @if ($editor['holders'] === [])
                <p class="text-sm text-neutral">Ningún puesto todavía.</p>
            @else
                <ul class="flex flex-wrap gap-1.5">
                    @foreach ($editor['holders'] as $holder)
                        <li class="inline-flex items-center gap-1.5 rounded-selector border border-base-300 bg-secondary/40 px-2 py-0.5 text-sm">
                            {{ $holder['label'] }}
                            <span class="font-mono text-[12.5px] text-neutral">{{ number_format($holder['people']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="space-y-2" aria-labelledby="editor-permissions-title">
            <div class="flex items-baseline justify-between gap-3">
                <h3 id="editor-permissions-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Permisos</h3>
                <span class="text-sm text-neutral" data-testid="editor-marked">{{ $editor['marked'] }} {{ $editor['marked'] === 1 ? 'marcado' : 'marcados' }}</span>
            </div>

            @error('permissions')
                <x-alert icon="o-x-circle" class="alert-error alert-soft" data-testid="permissions-error">
                    @foreach ($errors->get('permissions') as $line)
                        <span class="block">{{ $line }}</span>
                    @endforeach
                </x-alert>
            @enderror

            @include('core::permissions.picker', ['groups' => $editor['groups'], 'target' => 'editor', 'model' => 'permissions'])
        </section>
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 px-5 py-3">
        <p class="text-sm text-neutral">
            @if ($editor['leavesFactory'])
                Al guardar deja de seguir al código.
            @endif
        </p>
        <div class="flex gap-2">
            <x-button label="Cancelar" class="btn-ghost max-md:h-11" @click="close()" />
            <x-button label="Guardar" icon="o-check" class="btn-primary max-md:h-11" wire:click="saveRole" spinner="saveRole" data-testid="role-editor-save" />
        </div>
    </footer>
</div>
