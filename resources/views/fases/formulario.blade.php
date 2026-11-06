@extends('layouts.app')

@section('titulo', 'Editar fase')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Editar fase</h1>
            <p class="pagina-subtitulo">El nuevo nombre se verá en todas las empresas.</p>
        </div>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ route('fases.actualizar', $fase) }}">
            @csrf
            @method('PUT')

            <x-campo name="nombre" label="Nombre" :value="old('nombre', $fase->nombre)" maxlength="100" required autofocus />

            {{-- Estos datos solo se muestran: la app depende de ellos y no se pueden cambiar. --}}
            <dl class="datos-fijos">
                <div>
                    <dt>Orden</dt>
                    <dd>{{ $fase->orden }}</dd>
                </div>
                <div>
                    <dt>Registra sesiones</dt>
                    <dd>{{ $fase->tiene_sesiones ? 'Sí' : 'No' }}</dd>
                </div>
            </dl>

            <div class="formulario-acciones">
                <a href="{{ route('fases.index') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Guardar</x-boton>
            </div>
        </form>
    </div>
@endsection
