{{-- Enlaces de paginación con Bootstrap y en español. Laravel entrega $paginator y $elements. --}}
@if ($paginator->hasPages())
    <nav aria-label="Paginación">
        <ul class="pagination mb-0">
            <li @class(['page-item', 'disabled' => $paginator->onFirstPage()])>
                <a class="page-link" href="{{ $paginator->previousPageUrl() ?? '#' }}" @if ($paginator->onFirstPage()) aria-disabled="true" tabindex="-1" @endif>Anterior</a>
            </li>

            @foreach ($elements as $elemento)
                {{-- Laravel entrega "..." como texto cuando hay muchas páginas. --}}
                @if (is_string($elemento))
                    <li class="page-item disabled"><span class="page-link">{{ $elemento }}</span></li>
                @endif

                @if (is_array($elemento))
                    @foreach ($elemento as $pagina => $url)
                        <li @class(['page-item', 'active' => $pagina === $paginator->currentPage()])>
                            <a class="page-link" href="{{ $url }}" @if ($pagina === $paginator->currentPage()) aria-current="page" @endif>{{ $pagina }}</a>
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li @class(['page-item', 'disabled' => ! $paginator->hasMorePages()])>
                <a class="page-link" href="{{ $paginator->nextPageUrl() ?? '#' }}" @unless ($paginator->hasMorePages()) aria-disabled="true" tabindex="-1" @endunless>Siguiente</a>
            </li>
        </ul>
    </nav>
@endif
