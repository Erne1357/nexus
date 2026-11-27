@php
$summary ??= true;

if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        {{-- Con `summary` en false (data de links()) solo van los controles: el catálogo pinta su propio conteo en su pie. Colores del tema daisyUI. --}}
        @php
            $pageName = $paginator->getPageName();
            $suffix = $pageName == 'page' ? '' : '.'.$pageName;
        @endphp
        <div @class(['sm:flex sm:items-center sm:justify-between sm:gap-4', 'max-sm:w-full'])>
        @if ($summary)
            <p class="mb-2 text-sm text-neutral max-sm:hidden sm:mb-0">
                {!! __('Showing') !!}
                <span class="font-medium">{{ $paginator->firstItem() }}</span>
                {!! __('to') !!}
                <span class="font-medium">{{ $paginator->lastItem() }}</span>
                {!! __('of') !!}
                <span class="font-medium">{{ $paginator->total() }}</span>
                {!! __('results') !!}
            </p>
        @endif
        <nav role="navigation" aria-label="Pagination Navigation" class="max-sm:w-full">
            <div class="flex items-center justify-between gap-2 sm:hidden">
                @if ($paginator->onFirstPage())
                    <span class="btn btn-sm min-h-11 btn-disabled" aria-disabled="true">{!! __('pagination.previous') !!}</span>
                @else
                    <button type="button" wire:click="previousPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="previousPage{{ $suffix }}.before" class="btn btn-sm min-h-11">{!! __('pagination.previous') !!}</button>
                @endif

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="nextPage{{ $suffix }}.before" class="btn btn-sm min-h-11">{!! __('pagination.next') !!}</button>
                @else
                    <span class="btn btn-sm min-h-11 btn-disabled" aria-disabled="true">{!! __('pagination.next') !!}</span>
                @endif
            </div>

            <div class="join hidden sm:inline-flex">
                @if ($paginator->onFirstPage())
                    <span class="join-item btn btn-sm btn-disabled" aria-disabled="true" aria-label="{{ __('pagination.previous') }}"><svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></span>
                @else
                    <button type="button" wire:click="previousPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" dusk="previousPage{{ $suffix }}.after" class="join-item btn btn-sm" aria-label="{{ __('pagination.previous') }}"><svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></button>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="join-item btn btn-sm btn-disabled" aria-disabled="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span wire:key="paginator-{{ $pageName }}-page{{ $page }}" class="join-item btn btn-sm btn-primary tabular-nums" aria-current="page">{{ $page }}</span>
                            @else
                                <button type="button" wire:key="paginator-{{ $pageName }}-page{{ $page }}" wire:click="gotoPage({{ $page }}, '{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="join-item btn btn-sm tabular-nums" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</button>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" dusk="nextPage{{ $suffix }}.after" class="join-item btn btn-sm" aria-label="{{ __('pagination.next') }}"><svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg></button>
                @else
                    <span class="join-item btn btn-sm btn-disabled" aria-disabled="true" aria-label="{{ __('pagination.next') }}"><svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg></span>
                @endif
            </div>
        </nav>
        </div>
    @endif
</div>
