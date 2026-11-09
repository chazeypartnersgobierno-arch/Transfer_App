<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Models\AsignacionEmpresa;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Módulo de usuarios: lista, alta, edición, baja, asignaciones y "Mi contraseña".
 */
class UsuarioTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Usuario $admin;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->usuario(RolApp::Admin, 'Admin');
        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
    }

    private function usuario(RolApp $rol = RolApp::Consultor, string $nombre = 'Prueba', ?Empresa $empresa = null, bool $estado = true): Usuario
    {
        $this->contador++;

        return $this->asignarEmpresa(Usuario::create([
            'nombre' => $nombre,
            'correo' => "u{$this->contador}@prueba.test",
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
            'estado' => $estado,
        ]), $empresa);
    }

    /** Datos válidos para crear un consultor. */
    private function datos(array $cambios = []): array
    {
        return [
            'nombre' => 'Ana Pérez',
            'correo' => 'ana@prueba.test',
            'rol_app' => 'consultor',
            'id_empresa' => $this->empresa->id_empresa,
            'fecha_inicio' => today()->toDateString(),
            'contrasena' => 'secreta123',
            'contrasena_confirmation' => 'secreta123',
            ...$cambios,
        ];
    }

    // ===== Permisos =====

    public function test_solo_el_admin_administra_usuarios(): void
    {
        $consultor = $this->usuario(empresa: $this->empresa);
        $otro = $this->usuario(empresa: $this->empresa);

        $this->actingAs($consultor)->get('/usuarios')->assertForbidden();
        $this->actingAs($consultor)->get('/usuarios/crear')->assertForbidden();
        $this->actingAs($consultor)->post('/usuarios', $this->datos())->assertForbidden();
        $this->actingAs($consultor)->get("/usuarios/{$otro->id_usuario}/editar")->assertForbidden();
        $this->actingAs($consultor)->patch("/usuarios/{$otro->id_usuario}/baja")->assertForbidden();
        $this->actingAs($consultor)->get("/usuarios/{$otro->id_usuario}/asignaciones")->assertForbidden();
        $this->actingAs($consultor)->post("/usuarios/{$otro->id_usuario}/asignaciones", ['id_empresa' => $this->empresa->id_empresa, 'fecha_inicio' => today()->toDateString()])->assertForbidden();

        $this->actingAs($consultor)->get('/')->assertDontSee('href="'.route('usuarios.index').'"', false);
        $this->assertTrue($otro->fresh()->estado);
    }

    public function test_la_lista_muestra_rol_y_empresa(): void
    {
        $this->usuario(RolApp::Consultor, 'Carla Asignada', $this->empresa);
        $this->usuario(RolApp::Consultor, 'Sergio Sinempresa');

        $this->actingAs($this->admin)->get('/usuarios')
            ->assertOk()
            ->assertSeeInOrder(['Carla Asignada', 'Consultor', 'Empresa Uno'])
            ->assertSeeInOrder(['Sergio Sinempresa', 'Sin asignar']);

        $this->actingAs($this->admin)->get("/usuarios?id_empresa={$this->empresa->id_empresa}")
            ->assertSee('Carla Asignada')
            ->assertDontSee('Sergio Sinempresa');

        $this->actingAs($this->admin)->get('/usuarios?buscar=sergio')
            ->assertSee('Sergio Sinempresa')
            ->assertDontSee('Carla Asignada');
    }

    // ===== Crear =====

    public function test_crear_un_consultor_guarda_hash_y_su_primera_asignacion(): void
    {
        $this->actingAs($this->admin)->get('/usuarios/crear')
            ->assertOk()
            ->assertSee('name="id_empresa"', false)
            ->assertSee('value="'.today()->format('Y-m-d').'"', false)
            ->assertSee('name="contrasena_confirmation"', false);

        $this->actingAs($this->admin)->post('/usuarios', $this->datos([
            'correo' => '  ANA@Prueba.TEST ',
            'fecha_inicio' => today()->subMonth()->toDateString(),
        ]))->assertRedirect(route('usuarios.index'))->assertSessionHas('exito');

        $ana = Usuario::where('correo', 'ana@prueba.test')->sole();
        $this->assertSame(RolApp::Consultor, $ana->rol_app);
        $this->assertNotSame('secreta123', $ana->contrasena_hash);
        $this->assertTrue(Hash::check('secreta123', $ana->contrasena_hash));

        $asignacion = $ana->asignaciones()->sole();
        $this->assertTrue($asignacion->activa);
        $this->assertSame($this->empresa->id_empresa, $asignacion->id_empresa);
        $this->assertSame(today()->subMonth()->toDateString(), $asignacion->fecha_inicio->toDateString());
    }

    public function test_crear_un_admin_no_le_asigna_empresa(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['rol_app' => 'admin', 'id_empresa' => '', 'fecha_inicio' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Usuario::where('correo', 'ana@prueba.test')->sole()->asignaciones()->count());
    }

    public function test_valida_los_datos_al_crear(): void
    {
        $this->usuario()->update(['correo' => 'repetido@prueba.test', 'estado' => false]);
        $deBaja = Empresa::create(['nombre' => 'Baja', 'sigla' => 'BAJ', 'estado' => false]);

        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['correo' => 'REPETIDO@prueba.test']))
            ->assertSessionHasErrors(['correo' => 'Ya hay un usuario con este correo (puede estar dado de baja).']);
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['contrasena_confirmation' => 'otra-cosa']))
            ->assertSessionHasErrors(['contrasena' => 'Las contraseñas no coinciden.']);
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['contrasena' => 'corta', 'contrasena_confirmation' => 'corta']))
            ->assertSessionHasErrors(['contrasena' => 'La contraseña debe tener al menos 8 caracteres.']);
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['rol_app' => 'jefe']))
            ->assertSessionHasErrors(['rol_app' => 'Elige un rol de la lista.']);
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['id_empresa' => '']))
            ->assertSessionHasErrors(['id_empresa' => 'Elige la empresa del consultor.']);
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['id_empresa' => $deBaja->id_empresa]))
            ->assertSessionHasErrors(['id_empresa' => 'La empresa no existe o está dada de baja.']);
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['fecha_inicio' => today()->addDay()->toDateString()]))
            ->assertSessionHasErrors(['fecha_inicio' => 'La fecha no puede ser futura.']);

        $this->assertFalse(Usuario::where('correo', 'ana@prueba.test')->exists());
        $this->assertSame(0, AsignacionEmpresa::count());
    }

    // ===== Editar =====

    public function test_editar_sin_contrasena_conserva_la_anterior(): void
    {
        $consultor = $this->usuario(empresa: $this->empresa);

        $this->actingAs($this->admin)->get("/usuarios/{$consultor->id_usuario}/editar")
            ->assertOk()
            ->assertDontSee('name="id_empresa"', false)
            ->assertSee('Cambiar su empresa');

        $this->actingAs($this->admin)->put("/usuarios/{$consultor->id_usuario}", [
            'nombre' => 'Nuevo nombre',
            'correo' => $consultor->correo,
            'rol_app' => 'consultor',
            'contrasena' => '',
            'contrasena_confirmation' => '',
        ])->assertSessionHasNoErrors();

        $consultor->refresh();
        $this->assertSame('Nuevo nombre', $consultor->nombre);
        $this->assertTrue(Hash::check('clave-de-prueba', $consultor->contrasena_hash));
    }

    public function test_editar_con_contrasena_la_cambia(): void
    {
        $consultor = $this->usuario(empresa: $this->empresa);

        $this->actingAs($this->admin)->put("/usuarios/{$consultor->id_usuario}", [
            'nombre' => $consultor->nombre,
            'correo' => $consultor->correo,
            'rol_app' => 'consultor',
            'contrasena' => 'nueva-clave',
            'contrasena_confirmation' => 'nueva-clave',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nueva-clave', $consultor->fresh()->contrasena_hash));
    }

    public function test_consultor_que_pasa_a_admin_cierra_sus_asignaciones(): void
    {
        $consultor = $this->usuario(empresa: $this->empresa);

        $this->actingAs($this->admin)->put("/usuarios/{$consultor->id_usuario}", [
            'nombre' => $consultor->nombre,
            'correo' => $consultor->correo,
            'rol_app' => 'admin',
        ])->assertSessionHasNoErrors();

        $this->assertSame(RolApp::Admin, $consultor->fresh()->rol_app);
        $this->assertSame(0, $consultor->asignaciones()->activas()->count());
        $this->assertSame(1, $consultor->asignaciones()->count()); // el historial se conserva
    }

    public function test_admin_que_pasa_a_consultor_necesita_empresa(): void
    {
        $otroAdmin = $this->usuario(RolApp::Admin);
        $url = "/usuarios/{$otroAdmin->id_usuario}";
        $datos = ['nombre' => $otroAdmin->nombre, 'correo' => $otroAdmin->correo, 'rol_app' => 'consultor'];

        $this->actingAs($this->admin)->get("{$url}/editar")->assertSee('name="id_empresa"', false);

        $this->actingAs($this->admin)->put($url, $datos)
            ->assertSessionHasErrors(['id_empresa' => 'Elige la empresa del consultor.']);
        $this->assertSame(RolApp::Admin, $otroAdmin->fresh()->rol_app);

        $this->actingAs($this->admin)->put($url, [...$datos, 'id_empresa' => $this->empresa->id_empresa, 'fecha_inicio' => today()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(RolApp::Consultor, $otroAdmin->fresh()->rol_app);
        $this->assertSame([$this->empresa->id_empresa], $otroAdmin->idsEmpresasVisibles());
    }

    public function test_el_admin_no_se_quita_su_propio_rol_ni_se_da_de_baja(): void
    {
        $url = "/usuarios/{$this->admin->id_usuario}";

        $this->actingAs($this->admin)->put($url, [
            'nombre' => 'Admin',
            'correo' => $this->admin->correo,
            'rol_app' => 'consultor',
            'id_empresa' => $this->empresa->id_empresa,
            'fecha_inicio' => today()->toDateString(),
        ])->assertSessionHasErrors(['rol_app' => 'No puedes quitarte el rol de administrador.']);

        $this->actingAs($this->admin)->patch("{$url}/baja")->assertSessionHas('error', 'No puedes darte de baja a ti mismo.');

        $this->admin->refresh();
        $this->assertSame(RolApp::Admin, $this->admin->rol_app);
        $this->assertTrue($this->admin->estado);
        $this->assertSame(0, AsignacionEmpresa::count());
    }

    // ===== Baja y reactivar =====

    public function test_la_baja_cierra_la_sesion_en_el_siguiente_clic(): void
    {
        $consultor = $this->usuario(empresa: $this->empresa);

        $this->actingAs($this->admin)->patch("/usuarios/{$consultor->id_usuario}/baja")->assertSessionHas('exito');
        $this->assertFalse($consultor->fresh()->estado);

        // Laravel vuelve a leer al usuario en cada petición; aquí se simula con refresh().
        $this->actingAs($consultor->fresh())->get('/seguimientos')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['acceso' => 'Tu usuario está dado de baja. Contacta al administrador.']);
        $this->assertGuest();
    }

    public function test_reactivar_se_bloquea_si_su_empresa_asignada_esta_de_baja(): void
    {
        $consultor = $this->usuario(empresa: $this->empresa, estado: false);
        $this->empresa->update(['estado' => false]);

        $this->actingAs($this->admin)->patch("/usuarios/{$consultor->id_usuario}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar a Prueba: su empresa (Empresa Uno) está dada de baja.');
        $this->assertFalse($consultor->fresh()->estado);

        $this->actingAs($this->admin)->patch('/asignaciones/'.$consultor->asignaciones()->sole()->id_usuario_empresa.'/cerrar');

        $this->actingAs($this->admin)->patch("/usuarios/{$consultor->id_usuario}/reactivar")->assertSessionHas('exito');
        $this->assertTrue($consultor->fresh()->estado);
    }

    // ===== Pantalla Asignaciones =====

    public function test_asignar_otra_empresa_desde_la_pantalla(): void
    {
        $otra = Empresa::create(['nombre' => 'Empresa Dos', 'sigla' => 'DOS']);
        $consultor = $this->usuario(RolApp::Consultor, 'Carla', $this->empresa);
        $url = "/usuarios/{$consultor->id_usuario}/asignaciones";

        // No ofrece la empresa con la que ya trabaja.
        $this->actingAs($this->admin)->get($url)
            ->assertOk()
            ->assertSee('Trabaja con Empresa Uno.')
            ->assertSee('<option value="'.$otra->id_empresa.'"', false)
            ->assertDontSee('<option value="'.$this->empresa->id_empresa.'"', false);

        $this->actingAs($this->admin)->post($url, ['id_empresa' => $otra->id_empresa, 'fecha_inicio' => today()->toDateString()])
            ->assertRedirect($url)
            ->assertSessionHas('exito', 'Carla ahora trabaja con Empresa Dos.');

        $this->assertSame([$otra->id_empresa], $consultor->idsEmpresasVisibles());

        // Las reglas del servicio llegan como errores del formulario.
        $this->actingAs($this->admin)->post($url, ['id_empresa' => $otra->id_empresa, 'fecha_inicio' => today()->toDateString()])
            ->assertSessionHasErrors(['id_empresa' => 'Ya trabaja con Empresa Dos.']);
    }

    public function test_la_pantalla_de_empresa_es_corta_y_clara(): void
    {
        $consultor = $this->usuario(RolApp::Consultor, 'Carlos Larrarte', $this->empresa);
        $url = "/usuarios/{$consultor->id_usuario}/asignaciones";

        // Sin otra empresa: solo el aviso, sin formulario.
        $this->actingAs($this->admin)->get($url)
            ->assertSee('Empresa de Carlos Larrarte')
            ->assertSee('Por ahora no hay otra empresa a la cual cambiarlo.')
            ->assertDontSee('Cambiar de empresa')
            ->assertDontSee('name="id_empresa"', false);

        // Con otra empresa: el formulario con el texto corto, sin explicar la regla de la fecha.
        Empresa::create(['nombre' => 'Empresa Dos', 'sigla' => 'DOS']);

        $this->actingAs($this->admin)->get($url)
            ->assertSee('Cambiar de empresa')
            ->assertSee('Al cambiarlo de empresa, su asignación actual se cierra automáticamente. Lo que ya capturó se conserva.')
            ->assertDontSee('debe ser posterior')
            ->assertDontSee('Por ahora no hay otra empresa');
    }

    public function test_el_admin_no_tiene_pantalla_de_asignaciones(): void
    {
        $this->actingAs($this->admin)->get("/usuarios/{$this->admin->id_usuario}/asignaciones")
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('error', 'Los administradores no tienen empresa asignada.');
    }

    // ===== Mi contraseña =====

    public function test_cada_usuario_cambia_su_contrasena(): void
    {
        $consultor = $this->usuario(empresa: $this->empresa);

        $this->actingAs($consultor)->get('/mi-contrasena')->assertOk()->assertSee('Contraseña actual');

        $this->actingAs($consultor)->put('/mi-contrasena', [
            'contrasena_actual' => 'equivocada',
            'contrasena' => 'nueva-clave',
            'contrasena_confirmation' => 'nueva-clave',
        ])->assertSessionHasErrors(['contrasena_actual' => 'La contraseña actual no es correcta.']);

        $this->actingAs($consultor)->put('/mi-contrasena', [
            'contrasena_actual' => 'clave-de-prueba',
            'contrasena' => 'clave-de-prueba',
            'contrasena_confirmation' => 'clave-de-prueba',
        ])->assertSessionHasErrors(['contrasena' => 'La nueva contraseña debe ser distinta de la actual.']);

        $this->actingAs($consultor)->put('/mi-contrasena', [
            'contrasena_actual' => 'clave-de-prueba',
            'contrasena' => 'nueva-clave',
            'contrasena_confirmation' => 'nueva-clave',
        ])->assertRedirect(route('inicio'))->assertSessionHas('exito', 'Se cambió tu contraseña.');

        $this->assertTrue(Hash::check('nueva-clave', $consultor->fresh()->contrasena_hash));
    }
}
