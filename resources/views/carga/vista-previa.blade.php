{{-- Revisión del archivo antes de guardar. Aquí no se ha guardado nada todavía. --}}
@extends('layouts.app')

@section('titulo', 'Vista previa de la carga')

@php
    use App\Services\ResultadoDeCarga;

    $etiquetas = [
        ResultadoDeCarga::NUEVA => 'Nuevo',
        ResultadoDeCarga::DUPLICADO => 'Duplicado',
        ResultadoDeCarga::ERROR => 'Con error',
    ];
    $porGuardar = [
        'Actividades' => count($resultado->porGuardar('Actividades')),
        'Participantes' => count($resultado->porGuardar('Participantes')),
    ];

    // "3 actividades y 1 participante", sin mencionar lo que está en cero.
    $queSeGuarda = collect([
        $porGuardar['Actividades'] > 0 ? $porGuardar['Actividades'].($porGuardar['Actividades'] === 1 ? ' actividad' : ' actividades') : null,
        $porGuardar['Participantes'] > 0 ? $porGuardar['Participantes'].($porGuardar['Participantes'] === 1 ? ' participante' : ' participantes') : null,
    ])->filter()->implode(' y ');
@endphp

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Vista previa de la carga</h1>
            <p class="pagina-subtitulo">{{ $empresa->nombre }} · {{ $original }}</p>
        </div>
    </div>

    @if ($resultado->erroresGenerales !== [])
        <x-alerta tipo="error">
            <ul class="mb-0 ps-3">
                @foreach ($resultado->erroresGenerales as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alerta>
    @endif

    <div class="row g-3 mb-3">
        @foreach (['Actividades', 'Participantes'] as $hoja)
            @php($totales = $resultado->totales($hoja))
            <div class="col-md-6">
                <div class="tarjeta tarjeta-formulario h-100">
                    <h2 class="tarjeta-titulo">{{ $hoja }}</h2>
                    <dl class="resumen-carga">
                        <div><dt>Nuevos</dt><dd>{{ $totales[ResultadoDeCarga::NUEVA] }}</dd></div>
                        <div><dt>Duplicados</dt><dd>{{ $totales[ResultadoDeCarga::DUPLICADO] }}</dd></div>
                        <div @class(['con-error' => $totales[ResultadoDeCarga::ERROR] > 0])><dt>Con error</dt><dd>{{ $totales[ResultadoDeCarga::ERROR] }}</dd></div>
                    </dl>
                    @if ($hoja === 'Actividades' && ! $resultado->hayErrores() && $porGuardar['Actividades'] > 0)
                        <p class="texto-secundario mb-0 mt-2">{{ \App\Models\Seguimiento::seGeneraran($resultado->seguimientosPorGenerar) }}.</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="tarjeta tarjeta-formulario mb-3">
        @if ($resultado->sePuedeConfirmar())
            <x-alerta tipo="advertencia">
                Al confirmar se guardarán {{ $queSeGuarda }}. Los duplicados no se insertan.
            </x-alerta>
        @elseif ($resultado->hayErrores())
            <x-alerta tipo="error">Corrige los errores en el archivo y vuelve a subirlo.</x-alerta>
        @else
            <x-alerta tipo="advertencia">No hay registros nuevos para guardar.</x-alerta>
        @endif

        <div class="formulario-acciones mt-0">
            <form method="POST" action="{{ route('carga.cancelar') }}">
                @csrf
                <x-boton variante="outline-primary">{{ $resultado->sePuedeConfirmar() ? 'Cancelar' : 'Subir otro archivo' }}</x-boton>
            </form>

            {{-- Solo sin errores. El servidor vuelve a revisar el archivo antes de guardar. --}}
            @if ($resultado->sePuedeConfirmar())
                <form method="POST" action="{{ route('carga.confirmar') }}">
                    @csrf
                    <x-boton>Confirmar y guardar</x-boton>
                </form>
            @endif
        </div>
    </div>

    @foreach ($resultado->filas as $hoja => $filas)
        @continue($filas === [])

        <div class="tarjeta mb-3">
            <h2 class="tarjeta-titulo px-3 pt-3">Hoja {{ $hoja }}</h2>
            <div class="table-responsive">
                <table class="table tabla-apilada">
                    <thead>
                        <tr>
                            <th scope="col">Fila</th>
                            <th scope="col">Estado</th>
                            @foreach (array_keys($filas[0]['mostrar']) as $columna)
                                <th scope="col">{{ $columna }}</th>
                            @endforeach
                            <th scope="col">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($filas as $fila)
                            <tr>
                                <td class="celda-titulo">Fila {{ $fila['fila'] }}</td>
                                <td class="celda-estado"><span class="estado estado-carga-{{ $fila['estado'] }}">{{ $etiquetas[$fila['estado']] }}</span></td>
                                @foreach ($fila['mostrar'] as $columna => $valor)
                                    <td class="celda-detalle" data-etiqueta="{{ $columna }}">{{ $valor !== '' ? $valor : '—' }}</td>
                                @endforeach
                                <td class="celda-detalle" data-etiqueta="Detalle">
                                    @if ($fila['errores'] !== [])
                                        <ul class="lista-errores">
                                            @foreach ($fila['errores'] as $error)
                                                <li>{{ $error }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        {{ $fila['aviso'] ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
@endsection
