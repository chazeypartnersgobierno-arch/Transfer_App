{{-- Se usa para crear y para editar. Al editar, empresa, tipo y proceso quedan fijos. --}}
@extends('layouts.app')

@php
    $esNueva = ! $actividad->exists;
    $titulo = $esNueva ? 'Nueva actividad' : 'Editar actividad';
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <h1 class="pagina-titulo">{{ $titulo }}</h1>
    </div>

    <div class="tarjeta tarjeta-formulario">
        {{-- data-formulario-actividad: app.js muestra solo los procesos de la empresa elegida. --}}
        <form method="POST" action="{{ $esNueva ? route('actividades.guardar') : route('actividades.actualizar', $actividad) }}" @if ($esNueva) data-formulario-actividad @endif>
            @csrf

            @if ($esNueva)
                {{-- Un usuario con empresa no elige: el servidor le asigna la suya. --}}
                @if ($empresas->isNotEmpty())
                    <x-lista name="id_empresa" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" vacia="Elige una empresa" required />
                @else
                    @error('id_empresa')
                        <x-alerta tipo="error">{{ $message }}</x-alerta>
                    @enderror
                @endif

                <x-lista name="tipo" label="Tipo" :opciones="\App\Enums\TipoActividad::opciones()" :value="old('tipo')" vacia="Elige un tipo" required />
                <p class="ayuda-campo">Onboarding genera 1 seguimiento por grupo. Transición genera 3: Transferencia, Validación y Estabilización.</p>

                {{-- data-empresa indica de qué empresa es cada proceso. --}}
                <x-lista
                    name="id_proceso"
                    label="Proceso"
                    :opciones="$procesos->pluck('nombre', 'id_proceso')->all()"
                    :value="old('id_proceso')"
                    :datos="$procesos->mapWithKeys(fn ($proceso) => [$proceso->id_proceso => ['data-empresa' => $proceso->id_empresa]])->all()"
                    vacia="Elige un proceso"
                    required
                />
                <p class="ayuda-campo">La actividad se genera para los grupos de este proceso.</p>
            @else
                @method('PUT')
            @endif

            <x-campo name="macroproceso" label="Macroproceso (opcional)" :value="old('macroproceso', $actividad->macroproceso)" maxlength="255" />
            <x-campo name="nombre" label="Nombre" :value="old('nombre', $actividad->nombre)" maxlength="500" required />

            @unless ($esNueva)
                <dl class="datos-fijos">
                    <div>
                        <dt>Empresa</dt>
                        <dd>{{ $actividad->empresa->nombre }}</dd>
                    </div>
                    <div>
                        <dt>Tipo</dt>
                        <dd>{{ $actividad->tipo->value }}</dd>
                    </div>
                    <div>
                        <dt>Proceso</dt>
                        <dd>{{ $actividad->proceso->nombre }}</dd>
                    </div>
                </dl>
            @endunless

            <div class="formulario-acciones">
                <a href="{{ route('actividades.index') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Guardar</x-boton>
            </div>
        </form>
    </div>
@endsection
