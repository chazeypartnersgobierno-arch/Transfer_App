# Transfer_App

App web para dar seguimiento a la transferencia del conocimiento (primer cliente: EPM; debe servir para otras empresas).
Fases en orden: Onboarding → Transferencia → Estabilización → Validación.

## Stack

- Laravel 13 (PHP 8.3 o superior), un solo proyecto
- Vistas: Blade + CSS + JavaScript (se reutilizan las vistas HTML del prototipo)
- Base de datos: MySQL 8 (WAMP local), InnoDB, utf8mb4
- Entorno local: Windows, PHP 8.4 en terminal. La app se corre con `php artisan serve`; de WAMP solo se usa MySQL (no configurar Apache ni virtual hosts)
- Tablas con migraciones de Laravel y acceso a datos con Eloquent (no SQL a mano)
- Login: manual, con la autenticación por sesión que trae Laravel (`Auth::attempt` + middleware `auth`) y las vistas propias del proyecto. No usar Breeze, Jetstream ni otros kits de inicio; no requiere JavaScript ni Node.js
- Carga desde Excel: paquete Laravel Excel (maatwebsite/excel)

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
| actividades | macroproceso, proceso, subproceso, nombre | id_empresa → empresas, id_fase → fases, id_bloque → bloques (NULL = Onboarding, aplica a todos) |
| aplicativos | nombre | id_empresa → empresas |
| participantes | nombre, apellidos, correo, rol, fecha_ingreso, comentarios | id_grupo → grupos |
| usuarios | nombre, correo, contrasena_hash, rol_app | id_empresa → empresas (NULL = usuario Chazey, ve todas), id_participante → participantes (NULL) |
| accesos_solicitados | fecha_solicitud, fecha_otorgado (NULL), estado, comentarios | id_participante → participantes, id_aplicativo → aplicativos |
| seguimientos | fecha_inicio, fecha_fin, estado_actividad, documentacion, comentarios, responsable, experto, documentador, lider_transferencia, lider_transferencia_gbs | id_actividad → actividades, id_grupo → grupos |
| sesiones | fecha, tema, modalidad, comentarios | id_seguimiento → seguimientos |
| asistencias | asistio, comentarios | id_sesion → sesiones, id_participante → participantes |
| avances_validacion | fecha_corte, porcentaje_esperado, porcentaje_real, comentarios | id_seguimiento → seguimientos, id_usuario → usuarios |

### Valores únicos (no se repiten)

- seguimientos: id_actividad + id_grupo
- asistencias: id_sesion + id_participante
- avances_validacion: id_seguimiento + fecha_corte
- participantes.correo, usuarios.correo, empresas.sigla
- bloques: id_empresa + nombre · grupos: id_bloque + nombre · aplicativos: id_empresa + nombre

### Campos de estado y rol

Estos campos son VARCHAR (no ENUM). Sus valores se definirán más adelante; no los inventes:

- seguimientos.estado_actividad
- sesiones.modalidad
- participantes.rol
- usuarios.rol_app
- accesos_solicitados.estado

En la app se capturan con lista desplegable (nunca texto libre) y Laravel valida que el valor esté en la lista antes de guardar.

### Otras reglas

- Todas las FK con ON DELETE RESTRICT.
- `porcentaje_esperado`: solo 0, 25, 50, 75 o 100. `porcentaje_real`: de 0 a 100.
- `fecha_fin` no puede ser menor que `fecha_inicio`.

## Reglas de negocio

- Cada actividad se registra una sola vez en `actividades`. Onboarding no lleva bloque; Transferencia, Estabilización y Validación sí, porque cambian por bloque.
- Al crear un grupo, la app genera sus `seguimientos`: actividades de Onboarding (id_bloque NULL) + actividades del bloque del grupo.
- Fases con `tiene_sesiones = 1` registran sesiones y asistencia. Validación no tiene sesiones: registra avances por fecha en `avances_validacion`.
- El % actual de una actividad de Validación es su captura más reciente.
- Un grupo terminó una fase cuando todos sus seguimientos de esa fase están en estado "Completada". Esto se calcula, no se guarda.
- Los datos se cargan desde Excel; después los usuarios solo dan seguimiento.
