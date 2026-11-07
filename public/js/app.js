// Ayudas de los formularios. Son opcionales: sin JavaScript, todo sigue funcionando.

// Envía el formulario al cambiar un filtro; sin JavaScript queda el botón Buscar.
document.querySelectorAll('[data-enviar-al-cambiar]').forEach((campo) => {
    campo.addEventListener('change', () => campo.form.requestSubmit());
});

// Formulario de actividad: los bloques dependen de la empresa elegida.
// Es solo una ayuda: las reglas reales se validan en el servidor.
document.querySelectorAll('form[data-formulario-actividad]').forEach((formulario) => {
    const empresa = formulario.querySelector('[name="id_empresa"]'); // no existe si el usuario tiene empresa
    const bloque = formulario.querySelector('[name="id_bloque"]');

    const actualizar = () => {
        // Muestra solo los bloques de la empresa elegida. "Todos los bloques" siempre se ve.
        bloque.querySelectorAll('option[data-empresa]').forEach((opcion) => {
            const visible = !empresa || opcion.dataset.empresa === empresa.value;
            opcion.hidden = !visible;
            opcion.disabled = !visible;

            if (!visible && opcion.selected) {
                bloque.value = '';
            }
        });
    };

    empresa?.addEventListener('change', actualizar);
    actualizar();
});

// Botón para ver u ocultar la contraseña.
document.querySelectorAll('[data-ver-clave]').forEach((boton) => {
    const campo = boton.parentElement.querySelector('input');

    boton.hidden = false;

    const mostrar = (visible) => {
        campo.type = visible ? 'text' : 'password';
        boton.setAttribute('aria-pressed', String(visible));
        boton.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    };

    boton.addEventListener('click', () => mostrar(campo.type === 'password'));

    // Al enviar, vuelve a ocultarla para que el navegador la trate como contraseña.
    campo.form.addEventListener('submit', () => mostrar(false));
});

// Aviso de mayúsculas activadas mientras se escribe la contraseña.
document.querySelectorAll('[data-aviso-mayus]').forEach((aviso) => {
    const campo = aviso.parentElement.querySelector('input');

    const revisar = (evento) => {
        if (evento.getModifierState) {
            aviso.hidden = !evento.getModifierState('CapsLock');
        }
    };

    campo.addEventListener('keydown', revisar);
    campo.addEventListener('keyup', revisar);
    campo.addEventListener('blur', () => { aviso.hidden = true; });
});

// Al enviar, desactiva el botón y muestra el progreso. Evita envíos dobles por un doble clic.
document.querySelectorAll('form[data-enviando]').forEach((formulario) => {
    const boton = formulario.querySelector('button[type="submit"]');
    const textoOriginal = boton.textContent;

    formulario.addEventListener('submit', () => {
        const giro = document.createElement('span');
        giro.className = 'spinner-border spinner-border-sm me-2';
        giro.setAttribute('aria-hidden', 'true');

        boton.disabled = true;
        boton.replaceChildren(giro, formulario.dataset.enviando);
    });

    // Si el usuario vuelve con "Atrás", el navegador puede restaurar la página tal como quedó.
    window.addEventListener('pageshow', () => {
        boton.disabled = false;
        boton.textContent = textoOriginal;
    });
});
