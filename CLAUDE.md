# Transfer_App

App web para dar seguimiento a la transferencia del conocimiento (primer cliente: EPM; debe servir para otras empresas).
Fases en orden: Onboarding → Transferencia → Validación → Estabilización.

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
Salvo `fases`, `asistencias`, `sesiones`, `avances_validacion`, `accesos_solicitados` y `usuario_empresa`, todas tienen `estado` BOOLEAN (1 activo, 0 baja). En `accesos_solicitados`, `estado` es VARCHAR con el estado del acceso (ver "Campos de estado y rol") y esa tabla no tiene baja lógica. `usuario_empresa` usa `activa` en lugar de `estado`: sus filas no se borran, se cierran.
Todas tienen `fecha_creacion` y `fecha_actualizacion` DATETIME automáticas (`avances_validacion` solo `fecha_creacion`).

| Tabla | Campos | Relaciones |
|---|---|---|
| empresas | nombre, sigla | — |
| fases | nombre, orden, tiene_sesiones | — |
| bloques | nombre | id_empresa → empresas |
| grupos | nombre | id_bloque → bloques |
| actividades | tipo, macroproceso, proceso, subproceso, nombre | id_empresa → empresas, id_bloque → bloques (obligatorio) |
| aplicativos | nombre | id_empresa → empresas |
| participantes | nombre, apellidos, correo, rol, fecha_ingreso, comentarios | id_grupo → grupos |
| usuarios | nombre, correo, contrasena_hash, rol_app | — (la empresa del consultor va en `usuario_empresa`; los participantes no son usuarios) |
| usuario_empresa | fecha_inicio, fecha_fin (NULL mientras está activa), activa | id_usuario → usuarios, id_empresa → empresas |
| accesos_solicitados | fecha_solicitud, fecha_otorgado (NULL), estado, comentarios | id_participante → participantes, id_aplicativo → aplicativos |
| seguimientos | fecha_inicio, fecha_fin (propuesta de cierre), fecha_cierre, estado_actividad, documentacion, comentarios, responsable, experto, documentador, lider_transferencia, lider_transferencia_gbs | id_actividad → actividades, id_grupo → grupos, id_fase → fases, id_usuario → usuarios (NULL, autor) |
| sesiones | fecha, tema, modalidad, comentarios | id_seguimiento → seguimientos, id_usuario → usuarios (NULL, autor) |
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
- En `seguimientos`, `responsable`, `experto`, `documentador`, `lider_transferencia` y `lider_transferencia_gbs` son texto libre de hasta 255 caracteres. `responsable`, `documentador`, `lider_transferencia` y `lider_transferencia_gbs` son el equipo del bloque: iguales en todos los seguimientos de un bloque. Se guardan en cada seguimiento, se capturan una vez por bloque (pantalla Equipo del bloque, que actualiza todos sus seguimientos, también los de baja) y los seguimientos nuevos los copian. `experto` es individual de cada seguimiento.
- `documentacion` es texto libre de hasta 500 caracteres.
- Un seguimiento de un grupo o una actividad dados de baja solo se consulta: no se edita.

## Roles de la app

Los códigos de `usuarios.rol_app` se guardan en minúsculas y están centralizados en el enum `App\Enums\RolApp`. Las etiquetas solo se muestran en las vistas.

| Código | Etiqueta | Permisos |
|---|---|---|
| admin | Administrador | Todo: usuarios, catálogos (empresas, fases, bloques, grupos, aplicativos, actividades), carga desde Excel y todo lo del consultor |
| consultor | Consultor | Seguimientos, sesiones, asistencia y avances de validación (crear, editar, consultar); catálogos solo lectura |

- Los permisos se validan en el servidor con los Gates `administrar`, `administrar-empresas` y `administrar-fases` (solo admin; hoy son la misma regla) y `dar-seguimiento` (admin y consultor), no solo ocultando botones.
- El administrador siempre es de Chazey y ve todas las empresas (`Usuario::esChazey()`). No existen admins de empresa.
- El consultor solo ve los datos de las empresas de su asignación activa (`Usuario::idsEmpresasVisibles()`); sin asignación activa no ve datos. Esto también se valida en el servidor.
- Los usuarios no se borran: se desactivan con `estado = 0`. Un usuario desactivado no puede iniciar sesión, y si ya estaba dentro sale en su siguiente clic (middleware `activo`, `App\Http\Middleware\UsuarioActivo`).
- El correo del usuario se guarda en minúsculas y no se repite, aunque el otro esté de baja. La contraseña la pone el admin al crear (opcional al editar, mínimo 8 caracteres) y cada usuario la cambia en "Mi contraseña" escribiendo la actual.
- Un admin no puede quitarse su propio rol ni darse de baja. Si un consultor pasa a admin, sus asignaciones se cierran; si un admin pasa a consultor, se le elige empresa en el mismo formulario.
- Autor: `seguimientos.id_usuario` y `sesiones.id_usuario` guardan quien hizo la última captura o corrección (como en `avances_validacion`). La asistencia toma el autor de su sesión. Los seguimientos generados por la app quedan sin autor y la pantalla Equipo del bloque no cambia el autor.

## Asignaciones de empresa

- Solo el administrador gestiona las asignaciones. Solo los consultores se asignan; el admin no lleva asignaciones.
- Toda asignación pasa por `App\Services\AsignadorDeEmpresas`, en transacción. No se borran: se cierran (`activa = 0`, `fecha_fin` = hoy).
- Regla actual: un consultor tiene UNA asignación activa. Al asignarlo a otra empresa, la anterior se cierra. La regla vive solo en `AsignadorDeEmpresas::cerrarLasAnteriores()`; para permitir varias se quita esa llamada (la visibilidad ya trabaja con una lista de empresas).
- `fecha_inicio` es hoy por defecto, editable por el admin para registrar asignaciones que empezaron antes; no puede ser futura. No se asigna a una empresa de baja ni a la misma empresa que ya está activa.
- Los datos pertenecen a la empresa, no al usuario: la empresa de un seguimiento (y de sus sesiones, asistencias y avances) sale de su cadena `seguimiento → actividad → bloque → empresa`, nunca de la asignación del usuario. Al cambiar a un consultor de empresa, lo que capturó se queda en la empresa original.
- La baja de una empresa se bloquea mientras tenga consultores activos con asignación activa a ella.

## Reglas de negocio

- Las fases son las mismas para todas las empresas. En la app solo se edita su nombre: no se agregan ni se quitan, y el orden y `tiene_sesiones` son fijos. La lógica usa `orden` y `tiene_sesiones`, nunca el nombre. El orden se define solo en `fases.orden`: para cambiarlo se hace una migración de datos (como `2026_11_10_000001_poner_validacion_antes_de_estabilizacion`) y se actualiza `FaseSeeder`.
- La fase va en el seguimiento, no en la actividad. El tipo de la actividad decide sus fases: Onboarding genera 1 seguimiento por grupo (fase de orden 1); Transición genera 3 por grupo (fases de orden 2, 3 y 4: Transferencia, Validación y Estabilización). Cada fase conserva sus reglas: Validación no tiene sesiones y lleva % esperado y real.
- Toda actividad lleva un bloque real de su empresa, también las de Onboarding (cada bloque tiene su propio Onboarding). Se genera solo para los grupos de ese bloque. No existe la opción "Todos los bloques".
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
- Una sesión se captura una vez y se eligen uno o varios grupos (seguimientos de la misma actividad y fase, en trabajo): la app crea una sesión por grupo con su propia asistencia, en una transacción. Después, cada sesión se edita por separado.
- La fecha de una sesión debe estar entre la `fecha_inicio` y la `fecha_fin` de su seguimiento (ambas incluidas). Sin las dos fechas no se registran sesiones; con varios grupos, la fecha debe caber en las fechas de cada uno. Un seguimiento con sesiones no puede quitar sus fechas ni cambiarlas dejando sesiones fuera.
- Las sesiones no se borran: se corrigen editándolas. Si ya hay una sesión con la misma fecha en ese seguimiento, se avisa y se pide confirmación antes de guardar.
- La asistencia guarda un registro por participante activo del grupo (asistió sí o no; la casilla empieza sin marcar). Al editar aparecen también quienes entraron después y quienes ya tenían registro aunque estén de baja.
- El % actual de una actividad de Validación es su captura más reciente (la de fecha de corte mayor).
- Los avances de validación se capturan un grupo (un seguimiento) a la vez. La fecha de corte debe estar entre la `fecha_inicio` y la `fecha_fin` del seguimiento (ambas incluidas) y no puede ser futura; sin las dos fechas no se registran avances. Un seguimiento con avances no puede quitar sus fechas ni cambiarlas dejando avances fuera.
- No hay dos avances con la misma fecha de corte en un seguimiento: si ya existe, se avisa y se corrige editando el existente. Los avances no se borran: se corrigen editándolos, e `id_usuario` queda con quien hizo la última captura o corrección.
- Un grupo terminó una fase cuando todos sus seguimientos en trabajo de esa fase están en estado "Completada". Esto se calcula, no se guarda. Una fase sin seguimientos cuenta como completa.
- Avance secuencial (`App\Services\AvanceDeFases`): la primera fase siempre está habilitada; cada una de las demás se habilita para un grupo cuando todas las anteriores están completas para ese grupo. Cada grupo avanza por su cuenta, sin depender de los otros grupos del bloque.
- En una fase no habilitada, sus seguimientos solo se consultan: no se editan, ni se crean o editan sesiones o avances. Se valida en el servidor y se muestra un aviso con candado que dice qué fase falta concluir y cuántas de sus actividades están terminadas. El equipo del bloque sí se edita.
- Se permite reabrir una fase completa (pasar un seguimiento de Completada a otro estado), con un aviso: las fases siguientes vuelven a bloquearse y lo ya capturado en ellas queda intacto, solo de consulta.
- La pantalla Fases del grupo muestra las fases en pestañas, en orden: completas con ✔, la actual resaltada y las bloqueadas deshabilitadas con candado. Una fase bloqueada no carga sus actividades.
- Los datos se cargan desde Excel; después los usuarios solo dan seguimiento.

## Carga desde Excel

- La empresa se elige en pantalla. La app genera la plantilla, con las hojas Instrucciones, Actividades y Participantes.
- Actividades: Macroproceso, Proceso, Subproceso, Actividad, Bloque, Tipo. Actividad, Bloque y Tipo son obligatorios; Bloque debe ser un bloque activo de la empresa (no se acepta "Todos"). El ID y las fechas se ignoran: las fechas se capturan en pantalla en cada seguimiento.
- Participantes: Nombre, Apellidos, Correo, Fecha de ingreso, Bloque, Grupo, Rol. Un correo que ya existe en la base (en cualquier empresa) se marca como duplicado y no se vuelve a insertar; un correo repetido dentro del archivo es error. La fecha de ingreso acepta fecha de Excel o texto dd/mm/aaaa; una fecha que no existe es error.
- Bloques o grupos inexistentes o dados de baja son error, y el mensaje dice cuál de los dos casos es.
- Al comparar datos del archivo se ignoran mayúsculas, acentos y espacios de más (igual que MySQL con utf8mb4_0900_ai_ci). Se guarda el texto limpio; los correos, en minúsculas.
- El archivo subido espera en `storage/app/private/cargas` hasta confirmar o cancelar. Los que nadie confirmó se borran a las 24 horas (`php artisan cargas:limpiar`, programado a diario y también al abrir la pantalla de carga).
- Se carga una sola vez por bloque y tipo: se rechaza la carga si el bloque ya tiene actividades de ese tipo, incluidas las dadas de baja o creadas a mano, indicando cuántas existen. Se calcula con una consulta. Los cambios posteriores se hacen en pantalla.
- Las filas repetidas en el archivo son error. Bloques y grupos inexistentes también: no se crean solos.
- Vista previa antes de guardar: registros nuevos, duplicados y con error, con el detalle por hoja y fila. Con errores no se puede confirmar. Todo se guarda en una transacción.
- Las capacitaciones quedan para un módulo aparte: la Tarea es el nombre de la capacitación, un proceso puede tener varias y probablemente tendrán sesiones y asistencia.
