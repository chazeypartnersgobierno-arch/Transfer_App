# Transfer_App

App web para dar seguimiento a la transferencia del conocimiento (primer cliente: EPM; debe servir para otras empresas).
Fases en orden: Onboarding → Transferencia → Estabilización → Validación.

## Stack

- Laravel 13 (PHP 8.3 o superior), un solo proyecto
- Vistas: Blade + CSS + JavaScript, con Bootstrap 5 local en `public/vendor/bootstrap` (sin CDN, npm, Vite ni Node.js)
- Diseño: paleta en variables en `public/css/app.css` (se carga después de Bootstrap; es la base de toda la app) y logo configurable en `config/marca.php`. El logo va siempre sobre fondo blanco
- Base de datos: MySQL 8 (WAMP local), InnoDB, utf8mb4
- Entorno local: Windows, PHP 8.4 en terminal. La app se corre con `php artisan serve`; de WAMP solo se usa MySQL (no configurar Apache ni virtual hosts)
- Tablas con migraciones de Laravel y acceso a datos con Eloquent (no SQL a mano)
- Login: manual, con la autenticación por sesión que trae Laravel (`Auth::attempt` + middleware `auth`) y las vistas propias del proyecto. No usar Breeze, Jetstream ni otros kits de inicio; no requiere JavaScript ni Node.js
- Carga desde Excel: paquete Laravel Excel (maatwebsite/excel)
- Pruebas: `php artisan test`, sobre la base `transfer_app_testing` (`tests/TestCase.php` las detiene si apuntan a otra base)

## Convenciones de Laravel en este proyecto

- Las tablas no siguen los nombres en inglés de Laravel: en cada modelo define `$table`, `$primaryKey` (`id_<tabla>`) y las FK en las relaciones.
- Fechas automáticas: `const CREATED_AT = 'fecha_creacion'` y `const UPDATED_AT = 'fecha_actualizacion'` (en `avances_validacion`, `UPDATED_AT = null`).
- La contraseña de `usuarios` está en `contrasena_hash`: ajusta el modelo para que el login de Laravel use ese campo y `correo` como usuario.
- Explica el código de forma sencilla: estoy aprendiendo Laravel.

## Reglas de trabajo

- Trabaja módulo por módulo. Antes de crear o cambiar archivos, explica el plan y espera mi autorización.
- No agregues, quites ni renombres tablas o campos sin preguntarme. El modelo de abajo es la fuente de verdad.
- Nombres de tablas y campos en español y en snake_case, igual que en el modelo.
- No inventes valores de listas: si falta uno, pregúntame.
- Nunca guardes contraseñas en texto: solo el hash (bcrypt).
- No se borra información: para dar de baja se usa `estado = 0`.

## Modelo de base de datos

Todas las tablas tienen `id_<tabla>` INT AUTO_INCREMENT como PK.
Salvo `fases`, `asistencias`, `sesiones`, `avances_validacion` y `accesos_solicitados`, todas tienen `estado` BOOLEAN (1 activo, 0 baja). En `accesos_solicitados`, `estado` es VARCHAR con el estado del acceso (ver "Campos de estado y rol") y esa tabla no tiene baja lógica.
Todas tienen `fecha_creacion` y `fecha_actualizacion` DATETIME automáticas (`avances_validacion` solo `fecha_creacion`).

| Tabla | Campos | Relaciones |
|---|---|---|
| empresas | nombre, sigla | — |
| fases | nombre, orden, tiene_sesiones | — |
| bloques | nombre | id_empresa → empresas |
| grupos | nombre | id_bloque → bloques |
| actividades | tipo, macroproceso, proceso, subproceso, nombre | id_empresa → empresas, id_bloque → bloques (NULL = aplica a todos los grupos de la empresa) |
| aplicativos | nombre | id_empresa → empresas |
| participantes | nombre, apellidos, correo, rol, fecha_ingreso, comentarios | id_grupo → grupos |
| usuarios | nombre, correo, contrasena_hash, rol_app | id_empresa → empresas (NULL = usuario Chazey, ve todas), id_participante → participantes (NULL) |
| accesos_solicitados | fecha_solicitud, fecha_otorgado (NULL), estado, comentarios | id_participante → participantes, id_aplicativo → aplicativos |
| seguimientos | fecha_inicio, fecha_fin (propuesta de cierre), fecha_cierre, estado_actividad, documentacion, comentarios, responsable, experto, documentador, lider_transferencia, lider_transferencia_gbs | id_actividad → actividades, id_grupo → grupos, id_fase → fases |
| sesiones | fecha, tema, modalidad, comentarios | id_seguimiento → seguimientos |
| asistencias | asistio, comentarios | id_sesion → sesiones, id_participante → participantes |
| avances_validacion | fecha_corte, porcentaje_esperado, porcentaje_real, comentarios | id_seguimiento → seguimientos, id_usuario → usuarios |

### Valores únicos (no se repiten)

- seguimientos: id_actividad + id_grupo + id_fase
- asistencias: id_sesion + id_participante
- avances_validacion: id_seguimiento + fecha_corte
- participantes.correo, usuarios.correo, empresas.sigla
- bloques: id_empresa + nombre · grupos: id_bloque + nombre · aplicativos: id_empresa + nombre

### Campos de estado y rol

Estos campos son VARCHAR (no ENUM). Sus valores son los de esta lista; no inventes otros:

- actividades.tipo: Onboarding, Transición (`App\Enums\TipoActividad`)
- seguimientos.estado_actividad: Pendiente, En curso, Completada (`App\Enums\EstadoActividad`). Sin valor se considera Pendiente. "Retrasado" no se guarda: se calcula cuando hoy es posterior a `fecha_fin` y el estado no es Completada
- sesiones.modalidad: Presencial, Virtual, Híbrida
- accesos_solicitados.estado: Solicitado, En trámite, Otorgado, Rechazado
- usuarios.rol_app: ver "Roles de la app"

En la app se capturan con lista desplegable (nunca texto libre) y Laravel valida que el valor esté en la lista antes de guardar.

`participantes.rol` es la excepción: por ahora es texto libre, obligatorio y de hasta 50 caracteres.

### Otras reglas

- Todas las FK con ON DELETE RESTRICT.
- `porcentaje_esperado`: solo 0, 25, 50, 75 o 100. `porcentaje_real`: de 0 a 100.
- `fecha_fin` no puede ser menor que `fecha_inicio`.
- `fecha_cierre` se llena sola al pasar a Completada y se vacía al reabrir; vuelve a llenarse al pasar otra vez a Completada.

## Roles de la app

Los códigos de `usuarios.rol_app` se guardan en minúsculas y están centralizados en el enum `App\Enums\RolApp`. Las etiquetas solo se muestran en las vistas.

| Código | Etiqueta | Permisos |
|---|---|---|
| admin | Administrador | Todo: usuarios, catálogos (empresas, fases, bloques, grupos, aplicativos, actividades), carga desde Excel y todo lo del consultor |
| consultor | Consultor | Seguimientos, sesiones, asistencia y avances de validación (crear, editar, consultar); catálogos solo lectura |

- Los permisos se validan en el servidor con los Gates `administrar` (solo admin), `administrar-empresas` y `administrar-fases` (solo admin de Chazey, `id_empresa` NULL) y `dar-seguimiento` (admin y consultor), no solo ocultando botones.
- Un usuario con empresa solo ve los datos de la suya; esto también se valida en el servidor.
- Los usuarios no se borran: se desactivan con `estado = 0`. Un usuario desactivado no puede iniciar sesión. Cerrar la sesión de quien ya estaba dentro queda para el módulo de usuarios.

## Reglas de negocio

- Las fases son las mismas para todas las empresas. En la app solo se edita su nombre: no se agregan ni se quitan, y el orden y `tiene_sesiones` son fijos. La lógica usa `orden` y `tiene_sesiones`, nunca el nombre.
- La fase va en el seguimiento, no en la actividad. El tipo de la actividad decide sus fases: Onboarding genera 1 seguimiento por grupo (fase de orden 1); Transición genera 3 por grupo (fases de orden 2, 3 y 4: Transferencia, Estabilización y Validación). Cada fase conserva sus reglas: Validación no tiene sesiones y lleva % esperado y real.
- Actividad con bloque: se genera solo para los grupos de ese bloque. Actividad sin bloque ("Todos los bloques"): se genera para todos los grupos de la empresa. Vale para ambos tipos, y el bloque siempre se elige de forma explícita.
- Las actividades se muestran en el orden en que se cargaron (por `id_actividad`); la carga las inserta en el orden del archivo.
- Al crear una actividad, la app rechaza otra con la misma empresa, tipo, bloque, macroproceso, proceso, subproceso y nombre, aunque esté dada de baja. Al editar, empresa, tipo y bloque quedan fijos.
- Cada actividad se registra una sola vez en `actividades`.
- Al crear un grupo, la app genera sus `seguimientos` según las reglas anteriores. Toda la generación pasa por `App\Services\GeneradorDeSeguimientos`, en transacción (todo o nada).
- Solo se generan combinaciones con grupo y actividad activos, y nunca se tocan los seguimientos existentes.
- Al crear una actividad o al reactivar un grupo o una actividad, se generan los seguimientos que falten. Antes se muestra cuántos se generarán y se pide confirmación.
- Al editar un grupo solo cambia el nombre. Si el bloque está mal, se da de baja y se crea uno nuevo.
- Los seguimientos no bloquean la baja de un grupo o de una actividad y quedan intactos. Antes de la baja se muestra cuántos tiene y se pide confirmación.
- Mientras el grupo o la actividad estén de baja, sus seguimientos no aparecen en las listas de trabajo ni cuentan en los porcentajes de avance (`Seguimiento::enTrabajo()`), pero se pueden consultar con un filtro.
- Fases con `tiene_sesiones = 1` registran sesiones y asistencia. Validación no tiene sesiones: registra avances por fecha en `avances_validacion`.
- El % actual de una actividad de Validación es su captura más reciente.
- Un grupo terminó una fase cuando todos sus seguimientos de esa fase están en estado "Completada". Esto se calcula, no se guarda.
- Los datos se cargan desde Excel; después los usuarios solo dan seguimiento.

## Carga desde Excel

- La empresa se elige en pantalla. La app genera la plantilla, con las hojas Instrucciones, Actividades y Participantes.
- Actividades: Macroproceso, Proceso, Subproceso, Actividad, Bloque, Tipo. Actividad, Bloque y Tipo son obligatorios; Bloque acepta "Todos". El ID y las fechas se ignoran: las fechas se capturan en pantalla en cada seguimiento.
- Participantes: Nombre, Apellidos, Correo, Fecha de ingreso, Bloque, Grupo, Rol. Un correo existente se marca como duplicado y no se vuelve a insertar.
- Se carga una sola vez por bloque y tipo: se rechaza la carga si el bloque ya tiene actividades de ese tipo, incluidas las dadas de baja o creadas a mano, indicando cuántas existen. Se calcula con una consulta. Los cambios posteriores se hacen en pantalla.
- Las filas repetidas en el archivo son error. Bloques y grupos inexistentes también: no se crean solos.
- Vista previa antes de guardar: registros nuevos, duplicados y con error, con el detalle por hoja y fila. Con errores no se puede confirmar. Todo se guarda en una transacción.
- Las capacitaciones quedan para un módulo aparte: la Tarea es el nombre de la capacitación, un proceso puede tener varias y probablemente tendrán sesiones y asistencia.
