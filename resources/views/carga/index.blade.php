@extends('layouts.app')

@section('titulo', 'Carga desde Excel')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Carga desde Excel</h1>
            <p class="pagina-subtitulo">Carga las actividades y los participantes de una empresa.</p>
        </div>
    </div>

    @if ($empresas->isEmpty())
        <x-alerta tipo="advertencia">Primero registra una empresa activa.</x-alerta>
    @else
        <div class="tarjeta tarjeta-formulario mb-3">
            <h2 class="tarjeta-titulo">1. Descarga la plantilla</h2>
            <p class="texto-secundario">
                Si después agregas procesos o grupos, descárgala de nuevo.
            </p>

            {{-- GET: descargar no cambia datos. --}}
            <form method="GET" action="{{ route('carga.plantilla') }}">
                @include('carga.empresa', ['id' => 'empresa_plantilla'])

                <div class="formulario-acciones">
                    <x-boton variante="outline-primary">Descargar plantilla</x-boton>
                </div>
            </form>
        </div>

        <div class="tarjeta tarjeta-formulario">
            <h2 class="tarjeta-titulo">2. Sube el archivo</h2>
            <p class="texto-secundario">
                Antes de guardar verás una vista previa.
            </p>

            {{-- multipart/form-data: necesario para enviar un archivo. --}}
            <form method="POST" action="{{ route('carga.subir') }}" enctype="multipart/form-data">
                @csrf

                @include('carga.empresa', ['id' => 'empresa_carga'])

                <x-campo name="archivo" label="Archivo de Excel (.xlsx)" type="file" accept=".xlsx" required />

                <div class="formulario-acciones">
                    <x-boton>Revisar archivo</x-boton>
                </div>
            </form>
        </div>
    @endif
@endsection
