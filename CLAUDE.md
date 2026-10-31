# Transfer_App — Seguimiento de Transferencia del Conocimiento

App web multiempresa para dar seguimiento a la transferencia del conocimiento
(primer cliente: EPM; debe poder replicarse a otras empresas).
Migra la solución actual de Power Apps + listas de SharePoint a una app web.

## Stack
- **Frontend:** React + Vite + TypeScript, React Router, TanStack Query, Tailwind CSS.
- **Backend:** Node.js + NestJS (TypeScript), Prisma ORM.
- **Base de datos:** PostgreSQL.
- **Autenticación:** JWT con `rol_app` e `id_empresa` en el token.
- **Carga de Excel:** `exceljs` en el backend.
- **Pruebas:** Vitest (frontend), Jest (backend).

## Documentos de referencia (leer antes de crear algo)
- `docs/modelo-datos.md` — **modelo de datos aprobado. Es la fuente de verdad.**
  No agregar tablas ni campos que no estén ahí sin preguntar.
- `docs/Base_de_datos_transferencia_es.xlsx` — mismo modelo en Excel (diseño original).
- `prototipo/` — vistas HTML del prototipo (referencia visual; no se modifican).

## Estructura del repo
```
/docs/        Modelo de datos y documentación
/prototipo/   Vistas HTML del prototipo
/frontend/    App React
/backend/     API NestJS (+ prisma/schema.prisma)
```

## Reglas de negocio
- Fases en orden: Onboarding → Transferencia → Estabilización → Validación.
- Onboarding, Transferencia y Estabilización registran sesiones y asistencia.
- Validación NO tiene sesiones: se registran periodos en `validaciones`
  (fecha_inicio, fecha_fin, pct_esperado, pct_real); varias filas = historial.
- Actividades de Onboarding son generales (sin bloque ni subproceso) y aplican a todos los bloques.
  Las de Transferencia, Estabilización y Validación pertenecen a un bloque y a un subproceso.
- El bloque de un seguimiento se obtiene de su grupo (`seguimientos → grupos → bloques`).
- responsable, experto, documentador, lider_transferencia y lider_transferencia_gbs
  son campos de texto de captura (personas distintas; pueden ser de Chazey o de EPM).
- El rol es un campo de `participantes` (no hay tabla de roles).
- Carga masiva inicial desde Excel; después los usuarios solo dan seguimiento.

## Validaciones que debe hacer la app (la BD no las garantiza sola)
1. Si la actividad tiene `id_bloque`, debe coincidir con el bloque del grupo del seguimiento;
   si no tiene, aplica a cualquier bloque.
2. Solo se crean sesiones si la fase del seguimiento tiene `tiene_sesiones = true`.
3. Solo se crean registros en `validaciones` para seguimientos en fase Validación.
4. `pct_esperado` solo acepta 0, 25, 50, 75, 100; `pct_real` entre 0 y 100.
5. `fecha_fin` ≥ `fecha_inicio` (seguimientos y validaciones);
   `fecha_otorgado` ≥ `fecha_solicitud` (accesos).
6. Un participante no se repite en la misma sesión (índice único sesión + participante).
7. Multiempresa: toda consulta se filtra por el `id_empresa` del usuario autenticado,
   y todo lo relacionado (bloque, grupo, actividad, participante, aplicativo) es de la misma empresa.
8. Los campos con opciones (estado_actividad, estado de acceso, rol, rol_app, modalidad)
   son VARCHAR en la BD; la app solo permite los valores listados en `docs/modelo-datos.md`.

## Convenciones de código
- Nombres de tablas y campos en español, tal como están en `docs/modelo-datos.md`.
- Todo cambio de BD con migraciones de Prisma, nunca SQL manual.
- Validar entradas con DTOs + class-validator en el backend.
- El diseño visual se toma de `prototipo/`; no cambiar el estilo sin que se pida.
- Credenciales solo en `.env` (nunca en el código); mantener `.env.example` actualizado.

## Forma de trabajo
- Antes de cada módulo, proponer un plan y esperar aprobación.
- Construir módulo por módulo, completo: esquema → API → pantalla → pruebas.
- Un commit por módulo terminado, con mensaje descriptivo en español.
