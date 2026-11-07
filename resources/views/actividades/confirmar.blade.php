{{-- Segundo paso al crear una actividad: avisa cuántos seguimientos se generarán. --}}
@extends('layouts.app')

@section('titulo', 'Confirmar actividad')

@section('contenido')
    <div class="pagina-encabezado">
        <h1 class="pagina-titulo">Confirmar actividad</h1>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <x-alerta tipo="advertencia">
            {{ \App\Models\Seguimiento::seGeneraran($porGenerar) }} en los grupos activos a los que aplica esta actividad.
        </x-alerta>

        <dl class="datos-fijos mb-3">
            <div>
                <dt>Empresa</dt>
                <dd>{{ $empresa->nombre }}</dd>
            </div>
            <div>
                <dt>Fase</dt>
                <dd>{{ $fase->nombre }}</dd>
            </div>
            <div>
                <dt>Bloque</dt>
                <dd>{{ $bloque?->nombre ?? 'Todos' }}</dd>
            </div>
            <div>
                <dt>Actividad</dt>
                <dd>{{ $datos['nombre'] }}</dd>
            </div>
        </dl>

        {{-- Los datos ya validados viajan ocultos; el servidor los vuelve a validar al confirmar. --}}
        <form method="POST" action="{{ route('actividades.guardar') }}">
            @csrf

            @foreach ($datos as $campo => $valor)
                @if ($valor !== null)
                    <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
                @endif
            @endforeach

            <div class="formulario-acciones">
                <x-boton variante="outline-primary" name="accion" value="corregir">Corregir</x-boton>
                <x-boton name="accion" value="confirmar">Confirmar y guardar</x-boton>
            </div>
        </form>
    </div>
@endsection
