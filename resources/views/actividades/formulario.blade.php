{{-- Se usa para crear y para editar. Al editar, empresa, fase y bloque quedan fijos. --}}
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
        {{-- data-formulario-actividad: app.js muestra y filtra el bloque según la empresa y la fase. --}}
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

                {{-- data-sin-bloque marca la fase que no lleva bloque. --}}
                <x-lista
                    name="id_fase"
                    label="Fase"
                    :opciones="$fases->pluck('nombre', 'id_fase')"
                    :value="old('id_fase')"
                    :datos="$fases->filter->esOnboarding()->mapWithKeys(fn ($fase) => [$fase->id_fase => ['data-sin-bloque' => 'true']])->all()"
                    vacia="Elige una fase"
                    required
                />

                {{-- data-empresa indica de qué empresa es cada bloque. --}}
                <div data-campo-bloque>
                    <x-lista
                        name="id_bloque"
                        label="Bloque"
                        :opciones="$bloques->pluck('nombre', 'id_bloque')"
                        :value="old('id_bloque')"
                        :datos="$bloques->mapWithKeys(fn ($bloque) => [$bloque->id_bloque => ['data-empresa' => $bloque->id_empresa]])->all()"
                        vacia="Elige un bloque"
                    />
                </div>
            @else
                @method('PUT')
            @endif

            <x-campo name="macroproceso" label="Macroproceso (opcional)" :value="old('macroproceso', $actividad->macroproceso)" maxlength="255" />
            <x-campo name="proceso" label="Proceso (opcional)" :value="old('proceso', $actividad->proceso)" maxlength="255" />
            <x-campo name="subproceso" label="Subproceso (opcional)" :value="old('subproceso', $actividad->subproceso)" maxlength="255" />
            <x-campo name="nombre" label="Nombre" :value="old('nombre', $actividad->nombre)" maxlength="500" required />

            @unless ($esNueva)
                <dl class="datos-fijos">
                    <div>
                        <dt>Empresa</dt>
                        <dd>{{ $actividad->empresa->nombre }}</dd>
                    </div>
                    <div>
                        <dt>Fase</dt>
                        <dd>{{ $actividad->fase->nombre }}</dd>
                    </div>
                    <div>
                        <dt>Bloque</dt>
                        <dd>{{ $actividad->bloque?->nombre ?? 'Todos' }}</dd>
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
