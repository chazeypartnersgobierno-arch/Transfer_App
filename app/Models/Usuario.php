<?php

namespace App\Models;

use App\Enums\RolApp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'id_empresa',
        'id_participante',
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

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa', 'id_empresa');
    }

    /**
     * Los usuarios de Chazey no pertenecen a una empresa y ven todas.
     */
    public function esChazey(): bool
    {
        return $this->id_empresa === null;
    }
}
