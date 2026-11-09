<?php

namespace App\Http\Controllers;

use App\Enums\RolApp;
use App\Models\Empresa;
use App\Models\Usuario;
use App\Services\AsignadorDeEmpresas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Usuarios de la app (solo admin). No se borran: se dan de baja con estado = 0.
 * La empresa del consultor no es un campo del usuario: es su asignación activa
 * (ver AsignacionEmpresaController y App\Services\AsignadorDeEmpresas).
 */
class UsuarioController extends Controller
{
    public function index(Request $request): View
    {
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $rol = is_string($request->query('rol')) ? RolApp::tryFrom($request->query('rol')) : null;
        $idEmpresa = is_numeric($request->query('id_empresa')) ? (int) $request->query('id_empresa') : null;

        $usuarios = Usuario::with('asignacionesActivas.empresa') // trae las empresas de una vez
            ->when(! $verBajas, fn ($consulta) => $consulta->where('estado', true))
            ->when($rol !== null, fn ($consulta) => $consulta->where('rol_app', $rol->value))
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->whereHas('asignacionesActivas', fn ($q) => $q->where('id_empresa', $idEmpresa)))
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                // addcslashes: para que % y _ se busquen como texto normal.
                $texto = '%'.addcslashes($buscar, '%_\\').'%';
                $consulta->where(fn ($q) => $q->where('nombre', 'like', $texto)->orWhere('correo', 'like', $texto));
            })
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        $empresas = Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa');

        return view('usuarios.index', compact('usuarios', 'buscar', 'verBajas', 'rol', 'idEmpresa', 'empresas'));
    }

    public function crear(): View
    {
        return view('usuarios.formulario', [
            'usuario' => new Usuario,
            'empresas' => $this->empresasActivas(),
        ]);
    }

    /**
     * Crea el usuario y, si es consultor, su primera asignación. Todo o nada.
     */
    public function guardar(Request $request, AsignadorDeEmpresas $asignador): RedirectResponse
    {
        $this->limpiarCorreo($request);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'correo' => $this->reglasDeCorreo(),
            'rol_app' => ['required', Rule::enum(RolApp::class)],
            ...$this->reglasDeAsignacion('required_if:rol_app,'.RolApp::Consultor->value),
            'contrasena' => ['required', ...$this->reglasDeContrasena()],
        ], $this->mensajes());

        $usuario = DB::transaction(function () use ($datos, $asignador) {
            $usuario = Usuario::create([
                'nombre' => $datos['nombre'],
                'correo' => $datos['correo'],
                'rol_app' => $datos['rol_app'],
                'contrasena_hash' => $datos['contrasena'], // el modelo la convierte en hash
            ]);

            if ($usuario->rol_app === RolApp::Consultor) {
                $asignador->asignar($usuario, Empresa::findOrFail($datos['id_empresa']), Carbon::parse($datos['fecha_inicio']));
            }

            return $usuario;
        });

        return redirect()->route('usuarios.index')->with('exito', "Se creó el usuario {$usuario->nombre}.");
    }

    public function editar(Usuario $usuario): View
    {
        return view('usuarios.formulario', [
            'usuario' => $usuario,
            'empresas' => $this->empresasActivas(),
        ]);
    }

    /**
     * Cambia nombre, correo, rol y, si se escribe, la contraseña.
     * - Consultor que pasa a admin: se cierran sus asignaciones (el admin ve todas).
     * - Admin que pasa a consultor: hay que elegirle empresa en el mismo formulario.
     * La empresa de un consultor se cambia en la pantalla Asignaciones.
     */
    public function actualizar(Request $request, Usuario $usuario, AsignadorDeEmpresas $asignador): RedirectResponse
    {
        $this->limpiarCorreo($request);

        $eraAdmin = $usuario->rol_app === RolApp::Admin;

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'correo' => $this->reglasDeCorreo($usuario),
            'rol_app' => ['required', Rule::enum(RolApp::class)],
            // La empresa solo se pide si un admin pasa a consultor.
            ...($eraAdmin ? $this->reglasDeAsignacion('required_if:rol_app,'.RolApp::Consultor->value) : []),
            'contrasena' => ['nullable', ...$this->reglasDeContrasena()],
        ], $this->mensajes());

        $nuevoRol = RolApp::from($datos['rol_app']);

        // Para que la app nunca se quede sin quien la administre.
        if ($usuario->is($request->user()) && $nuevoRol !== RolApp::Admin) {
            return back()->withInput()->withErrors(['rol_app' => 'No puedes quitarte el rol de administrador.']);
        }

        DB::transaction(function () use ($usuario, $datos, $nuevoRol, $eraAdmin, $asignador) {
            $usuario->fill([
                'nombre' => $datos['nombre'],
                'correo' => $datos['correo'],
                'rol_app' => $nuevoRol,
            ]);

            if (filled($datos['contrasena'] ?? null)) {
                $usuario->contrasena_hash = $datos['contrasena'];
            }

            $usuario->save();

            if (! $eraAdmin && $nuevoRol === RolApp::Admin) {
                $asignador->cerrarTodas($usuario);
            }

            if ($eraAdmin && $nuevoRol === RolApp::Consultor) {
                $asignador->asignar($usuario, Empresa::findOrFail($datos['id_empresa']), Carbon::parse($datos['fecha_inicio']));
            }
        });

        return redirect()->route('usuarios.index')->with('exito', "Se actualizó el usuario {$usuario->nombre}.");
    }

    /**
     * No se borra nada: la baja solo cambia estado a 0. Si tenía la sesión abierta,
     * el middleware UsuarioActivo la cierra en su siguiente clic.
     */
    public function darDeBaja(Request $request, Usuario $usuario): RedirectResponse
    {
        if ($usuario->is($request->user())) {
            return back()->with('error', 'No puedes darte de baja a ti mismo.');
        }

        $usuario->update(['estado' => false]);

        return back()->with('exito', "Se dio de baja el usuario {$usuario->nombre}.");
    }

    public function reactivar(Usuario $usuario): RedirectResponse
    {
        $empresaDeBaja = $usuario->asignacionesActivas()->with('empresa')->get()
            ->map->empresa
            ->first(fn (Empresa $empresa) => ! $empresa->estado);

        if ($empresaDeBaja !== null) {
            return back()->with('error', "No se puede reactivar a {$usuario->nombre}: su empresa ({$empresaDeBaja->nombre}) está dada de baja.");
        }

        $usuario->update(['estado' => true]);

        return back()->with('exito', "Se reactivó el usuario {$usuario->nombre}.");
    }

    private function empresasActivas()
    {
        return Empresa::where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_empresa');
    }

    /**
     * Los correos se guardan sin espacios y en minúsculas.
     */
    private function limpiarCorreo(Request $request): void
    {
        if (is_string($request->input('correo'))) {
            $request->merge(['correo' => mb_strtolower(trim($request->input('correo')))]);
        }
    }

    /**
     * El correo no se repite, aunque el otro usuario esté dado de baja. Al editar puede seguir igual.
     */
    private function reglasDeCorreo(?Usuario $usuario = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            Rule::unique('usuarios', 'correo')->ignore($usuario?->id_usuario, 'id_usuario'),
        ];
    }

    /**
     * Empresa y fecha de inicio de la primera asignación de un consultor.
     */
    private function reglasDeAsignacion(string $cuandoSeExige): array
    {
        return [
            'id_empresa' => ['nullable', $cuandoSeExige, 'integer', Rule::exists('empresas', 'id_empresa')->where('estado', true)],
            'fecha_inicio' => ['nullable', $cuandoSeExige, 'date', 'before_or_equal:today'],
        ];
    }

    private function reglasDeContrasena(): array
    {
        return ['string', 'min:8', 'max:255', 'confirmed'];
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
            'correo.required' => 'Escribe el correo.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.max' => 'El correo no puede tener más de 255 caracteres.',
            'correo.unique' => 'Ya hay un usuario con este correo (puede estar dado de baja).',
            'rol_app.required' => 'Elige un rol.',
            'rol_app.enum' => 'Elige un rol de la lista.',
            'id_empresa.required_if' => 'Elige la empresa del consultor.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.exists' => 'La empresa no existe o está dada de baja.',
            'fecha_inicio.required_if' => 'Escribe la fecha.',
            'fecha_inicio.date' => 'Escribe una fecha válida.',
            'fecha_inicio.before_or_equal' => 'La fecha no puede ser futura.',
            'contrasena.required' => 'Escribe la contraseña.',
            'contrasena.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'contrasena.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'contrasena.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
