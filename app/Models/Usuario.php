<?php

namespace App\Models;

use App\Enums\RolApp;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Usuario que entra a la app. Hereda de Authenticatable porque es lo que
 * Laravel necesita para poder iniciar sesión con este modelo.
 */
class Usuario extends Authenticatable
{
    // Nuestra tabla y nuestra llave no siguen los nombres en inglés de Laravel.
    protected $table = 'usuarios';

    protected $primaryKey = 'id_usuario';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    // Le dice al login que la contraseña está en este campo, no en "password".
    protected $authPasswordName = 'contrasena_hash';

    // La tabla no tiene remember_token: vacío desactiva la función "Recordarme".
    protected $rememberTokenName = '';

    // Campos que se pueden llenar de una sola vez con create().
    protected $fillable = [
        'nombre',
        'correo',
        'contrasena_hash',
        'rol_app',
        'estado',
    ];

    // Nunca se incluye el hash si el usuario se convierte a JSON o a arreglo.
    protected $hidden = ['contrasena_hash'];

    protected function casts(): array
    {
        return [
            'contrasena_hash' => 'hashed', // al guardar, convierte el texto en hash bcrypt
            'estado' => 'boolean',
            'rol_app' => RolApp::class,    // convierte 'admin' en RolApp::Admin
        ];
    }

    /**
     * Historial de empresas a las que se ha asignado (solo consultores).
     */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionEmpresa::class, 'id_usuario', 'id_usuario');
    }

    /**
     * El administrador siempre es de Chazey y ve todas las empresas.
     */
    public function esChazey(): bool
    {
        return $this->rol_app === RolApp::Admin;
    }

    /**
     * Empresas cuyos datos ve un consultor: las de sus asignaciones activas.
     * Hoy es como máximo una (ver App\Services\AsignadorDeEmpresas), pero se devuelve
     * una lista para que permitir varias no obligue a cambiar las consultas.
     * Sin asignación activa la lista queda vacía y el consultor no ve datos.
     * Se consulta en cada llamada para que un cambio de asignación se note de inmediato.
     *
     * @return list<int>
     */
    public function idsEmpresasVisibles(): array
    {
        return $this->asignaciones()->activas()->pluck('id_empresa')->all();
    }
}
