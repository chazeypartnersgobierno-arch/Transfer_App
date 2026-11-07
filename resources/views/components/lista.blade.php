{{--
    Lista desplegable con etiqueta y mensaje de error.
    Uso: <x-lista name="id_empresa" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" required />
    opciones: valor => texto. Los atributos extra se pasan directo al select.
--}}
@props(['name', 'label', 'opciones', 'value' => null, 'vacia' => 'Elige una opción'])

<div class="mb-3">
    <label for="{{ $name }}" class="form-label">{{ $label }}</label>

    <select
        id="{{ $name }}"
        name="{{ $name }}"
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        {{ $attributes->class(['form-select', 'is-invalid' => $errors->has($name)]) }}
    >
        <option value="">{{ $vacia }}</option>
        @foreach ($opciones as $valor => $texto)
            <option value="{{ $valor }}" @selected((string) $valor === (string) $value)>{{ $texto }}</option>
        @endforeach
    </select>

    @error($name)
        <div id="{{ $name }}-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
