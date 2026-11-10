// Ayudas de los formularios. Son opcionales: sin JavaScript, todo sigue funcionando.

// Envía el formulario al cambiar un filtro; sin JavaScript queda el botón Buscar.
document.querySelectorAll('[data-enviar-al-cambiar]').forEach((campo) => {
    campo.addEventListener('change', () => campo.form.requestSubmit());
});

// Formulario de actividad: los procesos dependen de la empresa elegida.
// Es solo una ayuda: las reglas reales se validan en el servidor.
document.querySelectorAll('form[data-formulario-actividad]').forEach((formulario) => {
    const empresa = formulario.querySelector('[name="id_empresa"]'); // no existe si el usuario tiene empresa
    const proceso = formulario.querySelector('[name="id_proceso"]');

    const actualizar = () => {
        // Muestra solo los procesos de la empresa elegida.
        proceso.querySelectorAll('option[data-empresa]').forEach((opcion) => {
            const visible = !empresa || opcion.dataset.empresa === empresa.value;
            opcion.hidden = !visible;
            opcion.disabled = !visible;

            if (!visible && opcion.selected) {
                proceso.value = '';
            }
        });
    };

    empresa?.addEventListener('change', actualizar);
    actualizar();
});

// Asistencia: botones para marcar o desmarcar a todos los de un grupo.
document.querySelectorAll('[data-asistencia]').forEach((seccion) => {
    const botones = seccion.querySelector('[data-botones-marcar]');

    if (!botones) {
        return;
    }

    botones.hidden = false;
    botones.querySelectorAll('[data-marcar]').forEach((boton) => {
        boton.addEventListener('click', () => {
            seccion.querySelectorAll('input[data-asistio]:not(:disabled)').forEach((casilla) => {
                casilla.checked = boton.dataset.marcar === '1';
            });
        });
    });
});

// Asistencia: solo aparece quien ya había ingresado en la fecha de la sesión.
// Es solo una ayuda: el servidor guarda la asistencia solo de ellos.
document.querySelectorAll('input[data-fecha-sesion]').forEach((campoFecha) => {
    const filtrar = () => {
        const fecha = campoFecha.value; // formato aaaa-mm-dd: se puede comparar como texto

        document.querySelectorAll('[data-asistencia]').forEach((seccion) => {
            const filas = seccion.querySelectorAll('tr[data-participante]');
            let visibles = 0;

            filas.forEach((fila) => {
                const fuera = Boolean(fecha && fila.dataset.ingreso && fila.dataset.ingreso > fecha);
                fila.hidden = fuera;
                fila.querySelectorAll('input').forEach((campo) => { campo.disabled = fuera; });
                visibles += fuera ? 0 : 1;
            });

            const aviso = seccion.querySelector('[data-nadie-ingresado]');
            if (aviso) {
                aviso.hidden = filas.length === 0 || visibles > 0;
            }
        });
    };

    campoFecha.addEventListener('change', filtrar);
    filtrar();
});

// Nueva sesión: solo se ve la asistencia de los grupos elegidos.
// Es solo una ayuda: el servidor ignora la asistencia de los grupos no elegidos.
document.querySelectorAll('[data-elegir-grupo]').forEach((casilla) => {
    const seccion = document.querySelector('[data-asistencia="' + casilla.value + '"]');
    const actualizar = () => {
        if (seccion) {
            seccion.hidden = !casilla.checked;
        }
    };

    casilla.addEventListener('change', actualizar);
    actualizar();
});

// Nueva solicitud de acceso: botones para marcar o desmarcar a todos los participantes.
document.querySelectorAll('[data-marcar-todos]').forEach((seccion) => {
    const botones = seccion.querySelector('[data-botones-marcar]');

    if (!botones) {
        return;
    }

    botones.hidden = false;
    botones.querySelectorAll('[data-marcar]').forEach((boton) => {
        boton.addEventListener('click', () => {
            seccion.querySelectorAll('input[data-marcable]').forEach((casilla) => {
                casilla.checked = boton.dataset.marcar === '1';
            });
        });
    });
});

// Solicitud de acceso: la fecha otorgado solo se ve en el estado Otorgado.
// Es solo una ayuda: el servidor la exige en Otorgado y la vacía en los demás estados.
document.querySelectorAll('select[data-estado-acceso]').forEach((estado) => {
    const seccion = estado.form.querySelector('[data-solo-otorgado]');

    const actualizar = () => {
        seccion.hidden = estado.value !== 'Otorgado';
    };

    estado.addEventListener('change', actualizar);
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

// Equipo del proceso (lista de seguimientos): los campos empiezan bloqueados y se abren con "Editar".
// "Cancelar" regresa los valores guardados (data-original) y los vuelve a bloquear.
// Sin JavaScript los campos quedan abiertos y se puede guardar igual.
document.querySelectorAll('form[data-equipo-proceso]').forEach((formulario) => {
    const campos = formulario.querySelector('fieldset');
    const editar = formulario.querySelector('[data-equipo-editar]');
    const cancelar = formulario.querySelector('[data-equipo-cancelar]');
    const acciones = formulario.querySelector('[data-equipo-acciones]');

    const abrir = (abierto) => {
        campos.disabled = !abierto;
        editar.hidden = abierto;
        cancelar.hidden = !abierto;
        acciones.hidden = !abierto;
    };

    editar.addEventListener('click', () => {
        abrir(true);
        campos.querySelector('input')?.focus();
    });

    cancelar.addEventListener('click', () => {
        campos.querySelectorAll('input[data-original]').forEach((campo) => {
            campo.value = campo.dataset.original;
            campo.classList.remove('is-invalid');
            campo.removeAttribute('aria-invalid');
        });
        formulario.querySelectorAll('.invalid-feedback').forEach((error) => error.remove());
        abrir(false);
    });

    // Si el guardado falló por una validación, la tarjeta vuelve abierta para corregir.
    abrir(formulario.hasAttribute('data-abierto'));
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
