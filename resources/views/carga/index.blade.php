@extends('layouts.app')

@section('titulo', 'Carga desde Excel')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Carga desde Excel</h1>
            <p class="pagina-subtitulo">Carga las actividades y los participantes de una empresa.</p>
        </div>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <h2 class="tarjeta-titulo">Descarga la plantilla</h2>
        <p class="texto-secundario">
            Trae las hojas Instrucciones, Actividades y Participantes, con listas desplegables de los bloques y grupos activos de la empresa.
            Si después agregas bloques o grupos, descárgala de nuevo.
        </p>

        @if ($empresas->isEmpty() && $empresaPropia === null)
            <x-alerta tipo="advertencia">Primero registra una empresa activa.</x-alerta>
        @else
            {{-- GET: descargar no cambia datos. --}}
            <form method="GET" action="{{ route('carga.plantilla') }}">
                @if ($empresas->isNotEmpty())
                    <x-lista name="id_empresa" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" vacia="Elige una empresa" required />
                @else
                    <dl class="datos-fijos mb-3">
                        <div>
                            <dt>Empresa</dt>
                            <dd>{{ $empresaPropia->nombre }}</dd>
                        </div>
                    </dl>
                @endif

                <div class="formulario-acciones">
                    <x-boton>Descargar plantilla</x-boton>
                </div>
            </form>
        @endif
    </div>
@endsection
