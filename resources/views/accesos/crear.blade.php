{{--
    Registrar una solicitud para uno o varios participantes a la vez (una por persona).
    Arriba se filtra (empresa, bloque, grupo); abajo se elige el aplicativo y a quiénes.
    Quien ya tiene una solicitud en curso de ese aplicativo se omite y se avisa al guardar.
--}}
@extends('layouts.app')

@section('titulo', 'Nueva solicitud de acceso')

@section('contenido')
    <div class="pagina-encabezado">
        <h1 class="pagina-titulo">Nueva solicitud de acceso</h1>
    </div>

    <div class="tarjeta mb-3">
        <form method="GET" action="{{ route('accesos.crear') }}" class="herramientas">
            @if ($empresas->count() > 1)
                <label for="filtro_empresa" class="visually-hidden">Empresa</label>
                <select id="filtro_empresa" name="id_empresa" class="form-select filtro" data-enviar-al-cambiar>
                    <option value="">Elige una empresa</option>
                    @foreach ($empresas as $id => $nombre)
                        <option value="{{ $id }}" @selected($id === $idEmpresa)>{{ $nombre }}</option>
                    @endforeach
                </select>
            @endif

            @if ($idEmpresa !== null)
                <label for="filtro_bloque" class="visually-hidden">Filtrar por bloque</label>
                <select id="filtro_bloque" name="id_bloque" class="form-select filtro" data-enviar-al-cambiar>
                    <option value="">Todos los bloques</option>
                    @foreach ($bloques as $id => $nombre)
                        <option value="{{ $id }}" @selected($id === $idBloque)>{{ $nombre }}</option>
                    @endforeach
                </select>

                <label for="filtro_grupo" class="visually-hidden">Filtrar por grupo</label>
                <select id="filtro_grupo" name="id_grupo" class="form-select filtro" data-enviar-al-cambiar>
                    <option value="">Todos los grupos</option>
                    @foreach ($grupos as $id => $nombre)
                        <option value="{{ $id }}" @selected($id === $idGrupo)>{{ $nombre }}</option>
                    @endforeach
                </select>
            @endif

            <x-boton variante="outline-primary">Filtrar</x-boton>
        </form>
    </div>

    @if ($idEmpresa === null)
        <x-alerta tipo="advertencia">Elige la empresa.</x-alerta>
    @elseif ($aplicativos->isEmpty())
        <x-alerta tipo="advertencia">Esta empresa no tiene aplicativos activos.</x-alerta>
    @else
        <form method="POST" action="{{ route('accesos.guardar') }}">
            @csrf
            <input type="hidden" name="id_empresa" value="{{ $idEmpresa }}">

            <div class="tarjeta tarjeta-formulario mb-3">
                <h2 class="tarjeta-titulo">Solicitud</h2>

                <div class="row">
                    <div class="col-sm-6">
                        <x-lista name="id_aplicativo" label="Aplicativo" :opciones="$aplicativos" :value="old('id_aplicativo')" vacia="Elige un aplicativo" required />
                    </div>
                    <div class="col-sm-6">
                        <x-campo name="fecha_solicitud" label="Fecha de solicitud" type="date" :value="old('fecha_solicitud', today()->format('Y-m-d'))" :max="today()->format('Y-m-d')" required />
                    </div>
                </div>

                <div class="mb-0">
                    <label for="comentarios" class="form-label">Comentarios (opcional)</label>
                    <textarea id="comentarios" name="comentarios" rows="2" maxlength="10000"
                        @error('comentarios') aria-invalid="true" aria-describedby="comentarios-error" @enderror
                        @class(['form-control', 'is-invalid' => $errors->has('comentarios')])>{{ old('comentarios') }}</textarea>
                    @error('comentarios')
                        <div id="comentarios-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="tarjeta mb-3" data-marcar-todos>
                <div class="asistencia-encabezado">
                    <h2 class="tarjeta-titulo mb-0">Participantes</h2>

                    @if ($participantes->isNotEmpty())
                        {{-- Empiezan ocultos: public/js/app.js los muestra. Sin JavaScript se marca uno por uno. --}}
                        <div class="acciones" data-botones-marcar hidden>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-marcar="1">Marcar todos</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-marcar="0">Desmarcar todos</button>
                        </div>
                    @endif
                </div>

                @error('participantes')
                    <div class="px-3"><x-alerta tipo="error">{{ $message }}</x-alerta></div>
                @enderror
                @error('participantes.*')
                    <div class="px-3"><x-alerta tipo="error">{{ $message }}</x-alerta></div>
                @enderror

                @if ($participantes->isEmpty())
                    <p class="tabla-vacia mb-0">No hay participantes activos con estos filtros.</p>
                @else
                    <div class="table-responsive">
                        <table class="table tabla-apilada">
                            <thead>
                                <tr>
                                    <th scope="col">Elegir</th>
                                    <th scope="col">Participante</th>
                                    <th scope="col">Grupo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($participantes as $participante)
                                    <tr>
                                        <td class="celda-detalle" data-etiqueta="Elegir">
                                            <div class="form-check mb-0">
                                                <input id="participante_{{ $participante->id_participante }}" type="checkbox" name="participantes[]"
                                                    value="{{ $participante->id_participante }}" class="form-check-input" data-marcable
                                                    @checked(in_array((string) $participante->id_participante, (array) old('participantes', []), true))>
                                                <label for="participante_{{ $participante->id_participante }}" class="visually-hidden">Elegir a {{ $participante->nombreCompleto() }}</label>
                                            </div>
                                        </td>
                                        <td class="celda-titulo">
                                            {{ $participante->nombreCompleto() }}
                                            <div class="texto-secundario small">{{ $participante->correo }}</div>
                                        </td>
                                        <td class="celda-detalle" data-etiqueta="Grupo">{{ $participante->grupo->bloque->nombre }} › {{ $participante->grupo->nombre }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="formulario-acciones mb-3">
                <a href="{{ route('accesos.index') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Registrar</x-boton>
            </div>
        </form>
    @endif
@endsection
