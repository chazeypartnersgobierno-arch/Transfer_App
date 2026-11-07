<?php

namespace App\Enums;

/**
 * Lista única de estados de un seguimiento (campo seguimientos.estado_actividad).
 * Un seguimiento sin estado todavía se considera Pendiente.
 * "Retrasado" no es un estado: se calcula con fecha_fin, la fecha propuesta de cierre.
 */
enum EstadoActividad: string
{
    case Pendiente = 'Pendiente';
    case EnCurso = 'En curso';
    case Completada = 'Completada';
}
