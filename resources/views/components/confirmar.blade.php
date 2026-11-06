{{--
    Ventana de confirmación (modal de Bootstrap) antes de una acción importante.
    Se abre desde un botón con data-bs-toggle="modal" y data-bs-target="#el-id".
    Uso: <x-confirmar id="baja-1" titulo="Dar de baja" :accion="$url" boton="Dar de baja">Texto</x-confirmar>
--}}
@props(['id', 'titulo', 'accion', 'boton', 'metodo' => 'PATCH'])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-titulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="{{ $id }}-titulo">{{ $titulo }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">{{ $slot }}</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST" action="{{ $accion }}">
                    @csrf
                    @method($metodo)
                    <x-boton>{{ $boton }}</x-boton>
                </form>
            </div>
        </div>
    </div>
</div>
