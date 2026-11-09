{{-- Empresa de la carga: la elige el admin (siempre de Chazey). --}}
<x-lista name="id_empresa" :id="$id" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" vacia="Elige una empresa" required />
