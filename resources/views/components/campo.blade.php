{{--
    Campo de formulario con etiqueta y mensaje de error.
    Uso: <x-campo name="correo" label="Correo" type="email" :value="old('correo')" required />
    Los atributos extra (required, autofocus...) se pasan directo al input.
    Con type="password" agrega el botón de ver contraseña y el aviso de mayúsculas.
--}}
@props(['name', 'label', 'type' => 'text', 'value' => null])

<div class="mb-3">
    <label for="{{ $name }}" class="form-label">{{ $label }}</label>

    {{-- El error debe quedar junto al input: Bootstrap solo lo muestra si son hermanos. --}}
    <div @class(['campo-clave' => $type === 'password'])>
        <input
            id="{{ $name }}"
            name="{{ $name }}"
            type="{{ $type }}"
            value="{{ $value }}"
            @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
            {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}
        >

        @if ($type === 'password')
            {{-- Empieza oculto: public/js/app.js lo muestra, así sin JavaScript no queda un botón inútil. --}}
            <button type="button" class="ver-clave" aria-label="Mostrar contraseña" aria-pressed="false" data-ver-clave hidden>
                <svg class="icono-ver" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="icono-ocultar" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.5 6.6A17.2 17.2 0 0 0 2 12s3.6 7 10 7c1.9 0 3.6-.6 5-1.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
            </button>
        @endif

        @error($name)
            <div id="{{ $name }}-error" class="invalid-feedback">{{ $message }}</div>
        @enderror

        @if ($type === 'password')
            <div class="aviso-mayus" role="status" data-aviso-mayus hidden>Las mayúsculas están activadas.</div>
        @endif
    </div>
</div>
