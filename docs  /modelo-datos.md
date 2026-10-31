# Modelo de datos — Transfer_App

> Fuente: `docs/Base_de_datos_transferencia_es.xlsx` (diseño aprobado). Este archivo es su versión en texto para Claude Code.
> Convenciones: tablas en plural y snake_case; llave primaria `id_<singular>`; las llaves foráneas usan el mismo nombre que la primaria a la que apuntan.

## Relaciones

```
empresas ─┬─< bloques ─< grupos
          ├─< macroprocesos ─< procesos ─< subprocesos ─< actividades (id_subproceso opcional)
          ├─< participantes   (id_bloque, id_grupo)
          ├─< usuarios        (id_participante)
          └─< aplicativos ─< accesos_solicitados >─ participantes
bloques ─< actividades (id_bloque opcional)
actividades ─< seguimientos >─ grupos
fases ─< seguimientos
seguimientos ─< sesiones ─< asistencias >─ participantes
seguimientos ─< validaciones
```

## Tablas

### empresas

Empresas cliente (EPM y las que sigan). Raíz del esquema multiempresa.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_empresa | INT | PK | No |
| nombre | VARCHAR(300) |  | No |
| sigla | VARCHAR(150) |  | Sí |
| estado | BOOLEAN |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### bloques

Bloques de transferencia de una empresa.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_bloque | INT | PK | No |
| id_empresa | INT | FK → empresas | No |
| nombre | VARCHAR(300) |  | No |
| estado | BOOLEAN |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### grupos

Grupos dentro de un bloque.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_grupo | INT | PK | No |
| id_bloque | INT | FK → bloques | No |
| nombre | VARCHAR(300) |  | No |
| estado | BOOLEAN |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### macroprocesos

Primer nivel de la jerarquía de procesos.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_macroproceso | INT | PK | No |
| id_empresa | INT | FK → empresas | No |
| nombre | VARCHAR(300) |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### procesos

Segundo nivel de la jerarquía.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_proceso | INT | PK | No |
| id_macroproceso | INT | FK → macroprocesos | No |
| nombre | VARCHAR(300) |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### subprocesos

Tercer nivel de la jerarquía.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_subproceso | INT | PK | No |
| id_proceso | INT | FK → procesos | No |
| nombre | VARCHAR(300) |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### actividades

Catálogo de actividades. Las de Onboarding son generales: id_subproceso e id_bloque vacíos; se dan de alta una sola vez y aplican a todos los bloques. Las de Transferencia/Estabilización/Validación llevan su subproceso y su bloque.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_actividad | INT | PK | No |
| id_subproceso | INT | FK → subprocesos | Sí |
| id_bloque | INT | FK → bloques | Sí |
| nombre | VARCHAR(300) |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### fases

Catálogo fijo: 1 Onboarding, 2 Transferencia, 3 Estabilización (las tres con tiene_sesiones = true), 4 Validación (tiene_sesiones = false).

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_fase | INT | PK | No |
| nombre | VARCHAR(150) |  | No |
| orden | INT |  | No |
| tiene_sesiones | BOOLEAN |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### participantes

Personas de la transferencia. El rol es un campo del participante (no hay tabla de roles).

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_participante | INT | PK | No |
| id_empresa | INT | FK → empresas | No |
| id_bloque | INT | FK → bloques | No |
| id_grupo | INT | FK → grupos | Sí |
| nombre | VARCHAR(300) |  | No |
| apellidos | VARCHAR(300) |  | No |
| correo | VARCHAR(300) |  | No |
| fecha_ingreso | DATE |  | Sí |
| estado | BOOLEAN |  | No |
| comentarios | VARCHAR(300) |  | Sí |
| rol | VARCHAR(300) |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### usuarios

Cuentas de acceso a la app. contrasena se guarda cifrada (hash), nunca en texto plano.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_usuario | INT | PK | No |
| id_empresa | INT | FK → empresas | No |
| id_participante | INT | FK → participantes | Sí |
| correo | VARCHAR(300) |  | No |
| nombre | VARCHAR(300) |  | No |
| contrasena | VARCHAR(255) |  | No |
| rol_app | VARCHAR(300) |  | No |
| estado | BOOLEAN |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### aplicativos

Sistemas a los que se solicita acceso.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_aplicativo | INT | PK | No |
| id_empresa | INT | FK → empresas | No |
| nombre | VARCHAR(300) |  | No |
| estado | BOOLEAN |  | No |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### accesos_solicitados

Solicitudes de acceso de un participante a un aplicativo.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_acceso | INT | PK | No |
| id_participante | INT | FK → participantes | No |
| id_aplicativo | INT | FK → aplicativos | No |
| fecha_solicitud | DATE |  | No |
| fecha_otorgado | DATE |  | Sí |
| estado | VARCHAR(300) |  | No |
| comentarios | VARCHAR(300) |  | Sí |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### seguimientos

Tabla central: una actividad ejecutada por un grupo en una fase, con fechas y estado. El bloque se obtiene del grupo. responsable, experto, documentador, lider_transferencia y lider_transferencia_gbs son campos de captura de texto (personas distintas; pueden ser de Chazey o de EPM).

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_seguimiento | INT | PK | No |
| id_actividad | INT | FK → actividades | No |
| id_grupo | INT | FK → grupos | No |
| id_fase | INT | FK → fases | No |
| fecha_inicio | DATE |  | Sí |
| fecha_fin | DATE |  | Sí |
| estado_actividad | VARCHAR(300) |  | No |
| documentacion | VARCHAR(300) |  | Sí |
| comentarios | VARCHAR(300) |  | Sí |
| responsable | VARCHAR(300) |  | Sí |
| experto | VARCHAR(300) |  | Sí |
| documentador | VARCHAR(300) |  | Sí |
| lider_transferencia | VARCHAR(300) |  | Sí |
| lider_transferencia_gbs | VARCHAR(300) |  | Sí |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### sesiones

Sesiones de un seguimiento (solo en fases con tiene_sesiones = true).

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_sesion | INT | PK | No |
| id_seguimiento | INT | FK → seguimientos | No |
| fecha | DATE |  | No |
| tema | VARCHAR(300) |  | Sí |
| modalidad | VARCHAR(300) |  | Sí |
| comentarios | VARCHAR(300) |  | Sí |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### asistencias

Asistencia de un participante a una sesión.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_asistencia | INT | PK | No |
| id_sesion | INT | FK → sesiones | No |
| id_participante | INT | FK → participantes | No |
| asistio | BOOLEAN |  | No |
| comentarios | VARCHAR(300) |  | Sí |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

### validaciones

Periodos de monitoreo de un seguimiento en fase Validación: cada fila es un periodo (fecha_inicio a fecha_fin) con % esperado y % real. Varias filas = historial.

| Campo | Tipo | Llave | Nulo |
|---|---|---|---|
| id_validacion | INT | PK | No |
| id_seguimiento | INT | FK → seguimientos | No |
| fecha_inicio | DATE |  | No |
| fecha_fin | DATE |  | No |
| pct_esperado | INT |  | No |
| pct_real | INT |  | No |
| comentarios | VARCHAR(300) |  | Sí |
| fecha_creacion | DATETIME |  | No |
| fecha_actualizacion | DATETIME |  | No |

## Valores de los campos de texto con opciones

Son VARCHAR en la BD; la app muestra solo estas opciones (ajustables):

| Campo | Valores |
|---|---|
| seguimientos.estado_actividad | Pendiente, Retrasada, Terminada |
| fases.nombre | Onboarding, Transferencia, Estabilización, Validación |
| validaciones.pct_esperado | 0, 25, 50, 75, 100 |
| accesos_solicitados.estado | *por definir* |
| participantes.rol | *por definir* |
| usuarios.rol_app | *por definir* |
| sesiones.modalidad | Presencial, Virtual |
