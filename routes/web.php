<?php

use App\Http\Controllers\AccesoSolicitadoController;
use App\Http\Controllers\ActividadController;
use App\Http\Controllers\AplicativoController;
use App\Http\Controllers\AsignacionEmpresaController;
use App\Http\Controllers\AvanceValidacionController;
use App\Http\Controllers\BloqueController;
use App\Http\Controllers\CargaController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\EquipoDeBloqueController;
use App\Http\Controllers\FaseController;
use App\Http\Controllers\FasesDelGrupoController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MiContrasenaController;
use App\Http\Controllers\ParticipanteController;
use App\Http\Controllers\SeguimientoController;
use App\Http\Controllers\SesionController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// Solo para quien NO ha iniciado sesión.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'mostrarFormulario'])->name('login');
    // throttle:login aplica el límite de intentos definido en AppServiceProvider.
    Route::post('/login', [LoginController::class, 'iniciarSesion'])->middleware('throttle:login');
});

// Solo para quien SÍ ha iniciado sesión; a los demás los envía a /login.
// "activo" saca de la app a quien fue dado de baja con la sesión abierta.
Route::middleware(['auth', 'activo'])->group(function () {
    Route::view('/', 'inicio')->name('inicio');
    Route::post('/logout', [LoginController::class, 'cerrarSesion'])->name('logout');

    // Cada usuario cambia su propia contraseña.
    Route::get('/mi-contrasena', [MiContrasenaController::class, 'editar'])->name('mi-contrasena.editar');
    Route::put('/mi-contrasena', [MiContrasenaController::class, 'actualizar'])->name('mi-contrasena.actualizar');

    // Consultas: cualquier usuario con sesión.
    Route::get('/empresas', [EmpresaController::class, 'index'])->name('empresas.index');
    Route::get('/fases', [FaseController::class, 'index'])->name('fases.index');

    // Cambiar el nombre de una fase: quien no cumple el Gate recibe error 403.
    Route::middleware('can:administrar-fases')->group(function () {
        Route::get('/fases/{fase}/editar', [FaseController::class, 'editar'])->name('fases.editar');
        Route::put('/fases/{fase}', [FaseController::class, 'actualizar'])->name('fases.actualizar');
    });

    // Consultas de catálogos por empresa: cada usuario solo ve lo que le corresponde.
    Route::get('/bloques', [BloqueController::class, 'index'])->name('bloques.index');
    Route::get('/grupos', [GrupoController::class, 'index'])->name('grupos.index');
    Route::get('/aplicativos', [AplicativoController::class, 'index'])->name('aplicativos.index');
    Route::get('/actividades', [ActividadController::class, 'index'])->name('actividades.index');
    Route::get('/participantes', [ParticipanteController::class, 'index'])->name('participantes.index');

    // Participantes: admin y consultor (el consultor sabe quién entra o sale del grupo).
    Route::middleware('can:administrar-participantes')->group(function () {
        Route::get('/participantes/crear', [ParticipanteController::class, 'crear'])->name('participantes.crear');
        Route::post('/participantes', [ParticipanteController::class, 'guardar'])->name('participantes.guardar');
        Route::get('/participantes/{participante}/editar', [ParticipanteController::class, 'editar'])->name('participantes.editar');
        Route::put('/participantes/{participante}', [ParticipanteController::class, 'actualizar'])->name('participantes.actualizar');
        Route::patch('/participantes/{participante}/baja', [ParticipanteController::class, 'darDeBaja'])->name('participantes.baja');
        Route::patch('/participantes/{participante}/reactivar', [ParticipanteController::class, 'reactivar'])->name('participantes.reactivar');
    });

    // Accesos solicitados: admin y consultor, cada uno en las empresas que ve. No se borran: se corrigen.
    Route::middleware('can:administrar-accesos')->group(function () {
        Route::get('/accesos', [AccesoSolicitadoController::class, 'index'])->name('accesos.index');
        Route::get('/accesos/crear', [AccesoSolicitadoController::class, 'crear'])->name('accesos.crear');
        Route::post('/accesos', [AccesoSolicitadoController::class, 'guardar'])->name('accesos.guardar');
        Route::get('/accesos/{acceso}/editar', [AccesoSolicitadoController::class, 'editar'])->name('accesos.editar');
        Route::put('/accesos/{acceso}', [AccesoSolicitadoController::class, 'actualizar'])->name('accesos.actualizar');
    });

    // Cambios en catálogos por empresa: solo admin. Quien no cumple el Gate recibe error 403.
    Route::middleware('can:administrar')->group(function () {
        // Usuarios y sus asignaciones a empresas.
        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::get('/usuarios/crear', [UsuarioController::class, 'crear'])->name('usuarios.crear');
        Route::post('/usuarios', [UsuarioController::class, 'guardar'])->name('usuarios.guardar');
        Route::get('/usuarios/{usuario}/editar', [UsuarioController::class, 'editar'])->name('usuarios.editar');
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'actualizar'])->name('usuarios.actualizar');
        Route::patch('/usuarios/{usuario}/baja', [UsuarioController::class, 'darDeBaja'])->name('usuarios.baja');
        Route::patch('/usuarios/{usuario}/reactivar', [UsuarioController::class, 'reactivar'])->name('usuarios.reactivar');
        Route::get('/usuarios/{usuario}/asignaciones', [AsignacionEmpresaController::class, 'index'])->name('usuarios.asignaciones');
        Route::post('/usuarios/{usuario}/asignaciones', [AsignacionEmpresaController::class, 'guardar'])->name('usuarios.asignaciones.guardar');
        Route::patch('/asignaciones/{asignacion}/cerrar', [AsignacionEmpresaController::class, 'cerrar'])->name('asignaciones.cerrar');

        Route::get('/carga', [CargaController::class, 'index'])->name('carga.index');
        Route::get('/carga/plantilla', [CargaController::class, 'plantilla'])->name('carga.plantilla');
        Route::post('/carga', [CargaController::class, 'subir'])->name('carga.subir');
        Route::get('/carga/vista-previa', [CargaController::class, 'vistaPrevia'])->name('carga.vista-previa');
        Route::post('/carga/confirmar', [CargaController::class, 'confirmar'])->name('carga.confirmar');
        Route::post('/carga/cancelar', [CargaController::class, 'cancelar'])->name('carga.cancelar');

        Route::get('/bloques/crear', [BloqueController::class, 'crear'])->name('bloques.crear');
        Route::post('/bloques', [BloqueController::class, 'guardar'])->name('bloques.guardar');
        Route::get('/bloques/{bloque}/editar', [BloqueController::class, 'editar'])->name('bloques.editar');
        Route::put('/bloques/{bloque}', [BloqueController::class, 'actualizar'])->name('bloques.actualizar');
        Route::patch('/bloques/{bloque}/baja', [BloqueController::class, 'darDeBaja'])->name('bloques.baja');
        Route::patch('/bloques/{bloque}/reactivar', [BloqueController::class, 'reactivar'])->name('bloques.reactivar');

        Route::get('/grupos/crear', [GrupoController::class, 'crear'])->name('grupos.crear');
        Route::post('/grupos', [GrupoController::class, 'guardar'])->name('grupos.guardar');
        Route::get('/grupos/{grupo}/editar', [GrupoController::class, 'editar'])->name('grupos.editar');
        Route::put('/grupos/{grupo}', [GrupoController::class, 'actualizar'])->name('grupos.actualizar');
        Route::patch('/grupos/{grupo}/baja', [GrupoController::class, 'darDeBaja'])->name('grupos.baja');
        Route::patch('/grupos/{grupo}/reactivar', [GrupoController::class, 'reactivar'])->name('grupos.reactivar');

        Route::get('/actividades/crear', [ActividadController::class, 'crear'])->name('actividades.crear');
        Route::post('/actividades', [ActividadController::class, 'guardar'])->name('actividades.guardar');
        Route::get('/actividades/{actividad}/editar', [ActividadController::class, 'editar'])->name('actividades.editar');
        Route::put('/actividades/{actividad}', [ActividadController::class, 'actualizar'])->name('actividades.actualizar');
        Route::patch('/actividades/{actividad}/baja', [ActividadController::class, 'darDeBaja'])->name('actividades.baja');
        Route::patch('/actividades/{actividad}/reactivar', [ActividadController::class, 'reactivar'])->name('actividades.reactivar');

        Route::get('/aplicativos/crear', [AplicativoController::class, 'crear'])->name('aplicativos.crear');
        Route::post('/aplicativos', [AplicativoController::class, 'guardar'])->name('aplicativos.guardar');
        Route::get('/aplicativos/{aplicativo}/editar', [AplicativoController::class, 'editar'])->name('aplicativos.editar');
        Route::put('/aplicativos/{aplicativo}', [AplicativoController::class, 'actualizar'])->name('aplicativos.actualizar');
        Route::patch('/aplicativos/{aplicativo}/baja', [AplicativoController::class, 'darDeBaja'])->name('aplicativos.baja');
        Route::patch('/aplicativos/{aplicativo}/reactivar', [AplicativoController::class, 'reactivar'])->name('aplicativos.reactivar');
    });

    // Seguimiento: admin y consultor. Cada uno solo ve los de su empresa (Seguimiento::visiblePara).
    Route::middleware('can:dar-seguimiento')->group(function () {
        Route::get('/seguimientos', [SeguimientoController::class, 'index'])->name('seguimientos.index');
        Route::get('/seguimientos/{seguimiento}/editar', [SeguimientoController::class, 'editar'])->name('seguimientos.editar');
        Route::put('/seguimientos/{seguimiento}', [SeguimientoController::class, 'actualizar'])->name('seguimientos.actualizar');

        // Sesiones y asistencia (solo fases con sesiones). No se borran: se corrigen editándolas.
        Route::get('/seguimientos/{seguimiento}/sesiones/crear', [SesionController::class, 'crear'])->name('sesiones.crear');
        Route::post('/seguimientos/{seguimiento}/sesiones', [SesionController::class, 'guardar'])->name('sesiones.guardar');
        Route::get('/sesiones/{sesion}/editar', [SesionController::class, 'editar'])->name('sesiones.editar');
        Route::put('/sesiones/{sesion}', [SesionController::class, 'actualizar'])->name('sesiones.actualizar');

        // Fases del grupo en pestañas: avance secuencial (ver App\Services\AvanceDeFases).
        Route::get('/grupos/{grupo}/fases', [FasesDelGrupoController::class, 'mostrar'])->name('grupos.fases');

        // Avances de validación (solo fases sin sesiones). No se borran: se corrigen editándolos.
        Route::get('/seguimientos/{seguimiento}/avances/crear', [AvanceValidacionController::class, 'crear'])->name('avances.crear');
        Route::post('/seguimientos/{seguimiento}/avances', [AvanceValidacionController::class, 'guardar'])->name('avances.guardar');
        Route::get('/avances/{avance}/editar', [AvanceValidacionController::class, 'editar'])->name('avances.editar');
        Route::put('/avances/{avance}', [AvanceValidacionController::class, 'actualizar'])->name('avances.actualizar');

        // Equipo del bloque: responsable, documentador y líderes, iguales en todo el bloque.
        // Se muestra en la lista de seguimientos al filtrar por bloque; aquí solo se guarda.
        Route::put('/seguimientos/bloques/{bloque}/equipo', [EquipoDeBloqueController::class, 'actualizar'])->name('seguimientos.equipo.actualizar');
    });

    // Cambios en empresas: quien no cumple el Gate recibe error 403.
    Route::middleware('can:administrar-empresas')->group(function () {
        Route::get('/empresas/crear', [EmpresaController::class, 'crear'])->name('empresas.crear');
        Route::post('/empresas', [EmpresaController::class, 'guardar'])->name('empresas.guardar');
        Route::get('/empresas/{empresa}/editar', [EmpresaController::class, 'editar'])->name('empresas.editar');
        Route::put('/empresas/{empresa}', [EmpresaController::class, 'actualizar'])->name('empresas.actualizar');
        Route::patch('/empresas/{empresa}/baja', [EmpresaController::class, 'darDeBaja'])->name('empresas.baja');
        Route::patch('/empresas/{empresa}/reactivar', [EmpresaController::class, 'reactivar'])->name('empresas.reactivar');
    });
});
