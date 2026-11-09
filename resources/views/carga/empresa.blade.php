{{-- Empresa de la carga: Chazey la elige; un usuario con empresa solo ve la suya (el servidor la asigna). --}}
@if ($empresas->isNotEmpty())
    <x-lista name="id_empresa" :id="$id" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" vacia="Elige una empresa" required />
@else
    <dl class="datos-fijos mb-3">
        <div>
            <dt>Empresa</dt>
            <dd>{{ $empresaPropia->nombre }}</dd>
        </div>
    </dl>
@endif
