{{--
    Asistencia de un grupo. $opcion es el seguimiento (su grupo trae los participantes);
    $asistencias, los registros que ya existen, por id_participante (vacío al crear).
    Después de un error se muestra lo que se había marcado (old).
--}}
@php
    $id = $opcion->id_seguimiento;
    $participantes = $opcion->grupo->participantes;
    $conDatosAnteriores = session()->hasOldInput();
@endphp

<div class="tarjeta mb-3" data-asistencia="{{ $id }}">
    <div class="asistencia-encabezado">
        <h2 class="tarjeta-titulo mb-0">Asistencia · {{ $opcion->grupo->nombre }}</h2>

        @if ($participantes->isNotEmpty())
            {{-- Empiezan ocultos: public/js/app.js los muestra. Sin JavaScript se marca uno por uno. --}}
            <div class="acciones" data-botones-marcar hidden>
                <button type="button" class="btn btn-sm btn-outline-primary" data-marcar="1">Marcar todos</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-marcar="0">Desmarcar todos</button>
            </div>
        @endif
    </div>

    @if ($participantes->isEmpty())
        <p class="tabla-vacia mb-0">Este grupo no tiene participantes activos. La sesión se guarda sin asistencia.</p>
    @else
        <div class="table-responsive">
            <table class="table tabla-apilada">
                <thead>
                    <tr>
                        <th scope="col">Participante</th>
                        <th scope="col">Asistió</th>
                        <th scope="col">Comentario</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($participantes as $participante)
                        @php
                            $p = $participante->id_participante;
                            $registro = $asistencias[$p] ?? null;
                            $asistio = $conDatosAnteriores ? (bool) old("asistencia.{$id}.{$p}.asistio") : (bool) $registro?->asistio;
                            $comentario = $conDatosAnteriores ? old("asistencia.{$id}.{$p}.comentarios") : $registro?->comentarios;
                        @endphp
                        <tr>
                            <td class="celda-titulo">
                                {{ $participante->nombre }} {{ $participante->apellidos }}
                                @unless ($participante->estado)
                                    <span class="texto-secundario small">(de baja)</span>
                                @endunless
                                <div class="texto-secundario small">{{ $participante->correo }}</div>
                            </td>
                            <td class="celda-detalle" data-etiqueta="Asistió">
                                <div class="form-check mb-0">
                                    <input id="asistio_{{ $id }}_{{ $p }}" type="checkbox" name="asistencia[{{ $id }}][{{ $p }}][asistio]" value="1"
                                        class="form-check-input" data-asistio @checked($asistio)>
                                    <label for="asistio_{{ $id }}_{{ $p }}" class="form-check-label">Sí</label>
                                </div>
                            </td>
                            <td class="celda-detalle" data-etiqueta="Comentario">
                                <label for="comentario_{{ $id }}_{{ $p }}" class="visually-hidden">Comentario de {{ $participante->nombre }}</label>
                                <input id="comentario_{{ $id }}_{{ $p }}" type="text" name="asistencia[{{ $id }}][{{ $p }}][comentarios]" value="{{ $comentario }}"
                                    maxlength="10000" @class(['form-control', 'form-control-sm', 'is-invalid' => $errors->has("asistencia.{$id}.{$p}.comentarios")])>
                                @error("asistencia.{$id}.{$p}.comentarios")
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
