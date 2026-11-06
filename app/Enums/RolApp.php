<?php

namespace App\Enums;

/**
 * Lista única de roles de la app (campo usuarios.rol_app).
 * En la base de datos se guarda el código en minúsculas ('admin', 'consultor').
 * Las vistas muestran la etiqueta: $usuario->rol_app->etiqueta().
 */
enum RolApp: string
{
    case Admin = 'admin';
    case Consultor = 'consultor';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Consultor => 'Consultor',
        };
    }
}
