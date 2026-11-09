{{--
    Lista desplegable con etiqueta y mensaje de error.
    Uso: <x-lista name="id_empresa" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" required />
    opciones: valor => texto. Los atributos extra se pasan directo al select.
    datos (opcional): valor => atributos de esa opción, por ejemplo [3 => ['data-empresa' => 1]].
    id (opcional): por defecto es el name; cámbialo si la página tiene dos listas con el mismo name.
--}}
@props(['name', 'label', 'opciones', 'value' => null, 'vacia' => 'Elige una opción', 'datos' => [], 'id' => null])

@php
    $id ??= $name;
@endphp

<div class="mb-3">
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>

    <select
        id="{{ $id }}"
        name="{{ $name }}"
        @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        {{ $attributes->class(['form-select', 'is-invalid' => $errors->has($name)]) }}
    >
        <option value="">{{ $vacia }}</option>
        @foreach ($opciones as $valor => $texto)
            <option value="{{ $valor }}" @selected((string) $valor === (string) $value) {{ new \Illuminate\View\ComponentAttributeBag($datos[$valor] ?? []) }}>{{ $texto }}</option>
        @endforeach
    </select>

    @error($name)
        <div id="{{ $id }}-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
