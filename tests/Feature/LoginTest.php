<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Models\Usuario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase; // deja la base de pruebas limpia en cada prueba

    private const CLAVE = 'clave-de-prueba';

    private const MENSAJE_INCORRECTO = 'El correo o la contraseña no son correctos.';

    private function usuario(string $correo, RolApp $rol = RolApp::Admin, bool $estado = true): Usuario
    {
        return Usuario::create([
            'nombre' => 'Prueba',
            'correo' => $correo,
            'contrasena_hash' => self::CLAVE,
            'rol_app' => $rol,
            'estado' => $estado,
        ]);
    }

    public function test_el_formulario_se_ve_y_trae_token_csrf(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('Iniciar sesión');
    }

    public function test_login_correcto_guarda_hash_y_entra_a_inicio(): void
    {
        $usuario = $this->usuario('a@prueba.test');

        $this->assertStringStartsWith('$2y$', $usuario->getRawOriginal('contrasena_hash'));
        $this->assertSame('admin', DB::table('usuarios')->where('correo', 'a@prueba.test')->value('rol_app'));

        $this->post('/login', ['correo' => 'a@prueba.test', 'contrasena' => self::CLAVE])
            ->assertRedirect(route('inicio'));

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_contrasena_incorrecta(): void
    {
        $this->usuario('b@prueba.test');

        $this->from('/login')
            ->post('/login', ['correo' => 'b@prueba.test', 'contrasena' => 'mala'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['acceso' => self::MENSAJE_INCORRECTO]);

        $this->assertGuest();
    }

    public function test_correo_inexistente_da_el_mismo_mensaje(): void
    {
        $this->post('/login', ['correo' => 'noexiste@prueba.test', 'contrasena' => 'x'])
            ->assertSessionHasErrors(['acceso' => self::MENSAJE_INCORRECTO]);
    }

    public function test_usuario_dado_de_baja_no_entra(): void
    {
        $this->usuario('c@prueba.test', RolApp::Admin, false);

        $this->post('/login', ['correo' => 'c@prueba.test', 'contrasena' => self::CLAVE])
            ->assertSessionHasErrors(['acceso' => 'Tu usuario está dado de baja. Contacta al administrador.']);

        $this->assertGuest();
    }

    public function test_la_baja_no_se_revela_sin_la_contrasena_correcta(): void
    {
        $this->usuario('d@prueba.test', RolApp::Admin, false);

        $this->post('/login', ['correo' => 'd@prueba.test', 'contrasena' => 'mala'])
            ->assertSessionHasErrors(['acceso' => self::MENSAJE_INCORRECTO]);
    }

    public function test_el_sexto_intento_se_bloquea_solo_para_ese_correo(): void
    {
        $this->usuario('e@prueba.test');
        $otro = $this->usuario('f@prueba.test');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['correo' => 'E@prueba.test', 'contrasena' => 'mala'])
                ->assertSessionHasErrors(['acceso' => self::MENSAJE_INCORRECTO]);
        }

        // Ni con la contraseña correcta, y aunque cambien las mayúsculas del correo.
        $this->post('/login', ['correo' => 'e@prueba.test', 'contrasena' => self::CLAVE])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('acceso');
        $this->assertGuest();

        // Otro correo desde la misma IP sigue entrando.
        $this->post('/login', ['correo' => 'f@prueba.test', 'contrasena' => self::CLAVE])
            ->assertRedirect(route('inicio'));
        $this->assertAuthenticatedAs($otro);
    }

    public function test_correo_enviado_como_arreglo_no_rompe(): void
    {
        $this->post('/login', ['correo' => ['a'], 'contrasena' => 'x'])
            ->assertSessionHasErrors('correo');
    }

    public function test_campos_vacios_dan_mensajes_en_espanol(): void
    {
        $this->post('/login', ['correo' => '', 'contrasena' => ''])
            ->assertSessionHasErrors(['correo' => 'Escribe tu correo.', 'contrasena' => 'Escribe tu contraseña.']);
    }

    public function test_la_pantalla_de_acceso_usa_recursos_locales_y_marca_configurable(): void
    {
        config(['marca.logo' => 'img/otro.png', 'marca.logo_alt' => 'Otra Empresa', 'marca.nombre' => 'Nombre Configurado']);

        $respuesta = $this->get('/login');

        foreach (['vendor/bootstrap/bootstrap.min.css', 'css/app.css', 'vendor/bootstrap/bootstrap.bundle.min.js', 'js/app.js', 'data-ver-clave hidden', 'data-enviando', 'marca-nombre">Nombre Configurado', 'img/otro.png', 'alt="Otra Empresa"'] as $texto) {
            $respuesta->assertSee($texto, false);
        }

        $respuesta->assertDontSee('<style', false)->assertDontSee('cdn', false)->assertDontSee('@vite', false);

        $html = $respuesta->getContent();
        $this->assertLessThan(strpos($html, 'css/app.css'), strpos($html, 'bootstrap.min.css'));
    }

    public function test_sin_sesion_redirige_a_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_cerrar_sesion(): void
    {
        $this->actingAs($this->usuario('g@prueba.test'))
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_con_sesion_no_ve_login(): void
    {
        $this->actingAs($this->usuario('h@prueba.test'))
            ->get('/login')
            ->assertRedirect('/');
    }

    public function test_los_permisos_por_rol_se_validan_en_el_servidor(): void
    {
        Route::middleware(['web', 'auth', 'can:administrar'])->get('/_solo-admin', fn () => 'ok');
        Route::middleware(['web', 'auth', 'can:dar-seguimiento'])->get('/_seguimiento', fn () => 'ok');

        $admin = $this->usuario('i@prueba.test');
        $consultor = $this->usuario('j@prueba.test', RolApp::Consultor);

        $this->actingAs($admin)->get('/_solo-admin')->assertOk();
        $this->actingAs($admin)->get('/_seguimiento')->assertOk();
        $this->actingAs($consultor)->get('/_seguimiento')->assertOk();
        $this->actingAs($consultor)->get('/_solo-admin')->assertForbidden();
    }

    public function test_el_seeder_crea_un_solo_admin(): void
    {
        config(['auth.primer_usuario' => ['nombre' => 'Inicial', 'correo' => 'k@prueba.test', 'contrasena' => 'secreta']]);

        (new DatabaseSeeder)->run();
        (new DatabaseSeeder)->run();

        $usuario = Usuario::where('correo', 'k@prueba.test')->sole();
        $this->assertSame(RolApp::Admin, $usuario->rol_app);
        $this->assertNull($usuario->id_empresa);
        $this->assertTrue(Hash::check('secreta', $usuario->contrasena_hash));
    }

    public function test_el_seeder_avisa_si_faltan_datos(): void
    {
        config(['auth.primer_usuario' => ['nombre' => null, 'correo' => null, 'contrasena' => null]]);

        $this->expectException(RuntimeException::class);

        (new DatabaseSeeder)->run();
    }
}
