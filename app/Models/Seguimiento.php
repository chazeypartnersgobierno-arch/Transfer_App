<?php

namespace App\Models;

use App\Enums\EstadoActividad;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Seguimiento extends Model
{
    protected $table = 'seguimientos';

    protected $primaryKey = 'id_seguimiento';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    /**
     * Equipo del bloque: estos datos son los mismos en todos los seguimientos de un bloque.
     * Se guardan en cada seguimiento, pero se capturan una sola vez por bloque
     * (EquipoDeBloqueController) y los seguimientos nuevos los copian (GeneradorDeSeguimientos).
     * El experto no está aquí: es individual de cada seguimiento.
     */
    public const CAMPOS_DEL_BLOQUE = [
        'responsable' => 'Responsable',
        'documentador' => 'Documentador',
        'lider_transferencia' => 'Líder de transferencia',
        'lider_transferencia_gbs' => 'Líder de transferencia GBS',
    ];

    protected $fillable = [
        'id_actividad',
        'id_grupo',
        'id_fase',
        'fecha_inicio',
        'fecha_fin', // fecha propuesta de cierre
        'estado_actividad',
        'documentacion',
        'comentarios',
        'responsable',
        'experto',
        'documentador',
        'lider_transferencia',
        'lider_transferencia_gbs',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_cierre' => 'date',
            'estado_actividad' => EstadoActividad::class,
            'estado' => 'boolean',
        ];
    }

    /**
     * Laravel ejecuta booted() una vez por modelo. Aquí se registra una regla que corre
     * antes de cada guardado: fecha_cierre se llena al pasar a Completada y se vacía
     * al reabrir, sin importar qué pantalla haga el cambio.
     */
    protected static function booted(): void
    {
        static::saving(function (Seguimiento $seguimiento) {
            if ($seguimiento->isDirty('estado_actividad')) {
                $seguimiento->fecha_cierre = $seguimiento->estado_actividad === EstadoActividad::Completada
                    ? today()
                    : null;
            }
        });
    }

    public function fase(): BelongsTo
    {
        return $this->belongsTo(Fase::class, 'id_fase', 'id_fase');
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class, 'id_actividad', 'id_actividad');
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class, 'id_grupo', 'id_grupo');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class, 'id_seguimiento', 'id_seguimiento');
    }

    /**
     * Avances de validación (solo en la fase sin sesiones).
     */
    public function avances(): HasMany
    {
        return $this->hasMany(AvanceValidacion::class, 'id_seguimiento', 'id_seguimiento');
    }

    /**
     * El % actual es la captura más reciente: el avance con la fecha de corte mayor.
     * latestOfMany: Laravel trae solo ese avance, también al cargar muchos seguimientos.
     */
    public function avanceActual(): HasOne
    {
        return $this->hasOne(AvanceValidacion::class, 'id_seguimiento', 'id_seguimiento')->latestOfMany('fecha_corte');
    }

    /**
     * Si ya tiene fecha de inicio y fecha fin. Sin las dos no se registran sesiones ni avances.
     */
    public function tieneFechas(): bool
    {
        return $this->fecha_inicio !== null && $this->fecha_fin !== null;
    }

    /**
     * Si una fecha cae entre la fecha de inicio y la fecha fin (ambas incluidas).
     */
    public function fechaDentroDelRango(CarbonInterface|string $fecha): bool
    {
        return $this->tieneFechas()
            && Carbon::parse($fecha)->startOfDay()->betweenIncluded($this->fecha_inicio, $this->fecha_fin);
    }

    /**
     * Texto del rango, por ejemplo "del 01/03/2027 al 31/03/2027".
     */
    public function rangoDeFechas(): string
    {
        return $this->tieneFechas()
            ? "del {$this->fecha_inicio->format('d/m/Y')} al {$this->fecha_fin->format('d/m/Y')}"
            : 'sin fechas';
    }

    /**
     * Si su fase registra sesiones y asistencia (tiene_sesiones = 1). Validación no.
     */
    public function tieneSesiones(): bool
    {
        return $this->fase->tiene_sesiones;
    }

    /**
     * Solo los seguimientos que cuentan para el trabajo diario y los porcentajes de avance:
     * su grupo y su actividad deben estar activos. Los demás siguen guardados y se consultan con un filtro.
     * Se usa así: Seguimiento::enTrabajo()->get()
     */
    public function scopeEnTrabajo(Builder $consulta): Builder
    {
        return $consulta
            ->whereHas('grupo', fn ($q) => $q->where('estado', true))
            ->whereHas('actividad', fn ($q) => $q->where('estado', true));
    }

    /**
     * Filtra los seguimientos que un usuario puede ver: Chazey ve todos; los demás,
     * solo los de su empresa (la empresa sale de la actividad).
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        // La empresa sale del propio registro (actividad.bloque.empresa), nunca de la asignación del usuario:
        // si a un consultor lo cambian de empresa, lo que capturó se queda en la empresa original.
        return $usuario->esChazey()
            ? $consulta
            : $consulta->whereHas('actividad.bloque', fn ($q) => $q->whereIn('id_empresa', $usuario->idsEmpresasVisibles()));
    }

    /**
     * Laravel llama a este método para convertir un {seguimiento} de la URL en un modelo.
     * Si es de otra empresa, responde 404, como si no existiera.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::visiblePara(auth()->user())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * Todos los seguimientos de un bloque, también los de grupos o actividades de baja.
     */
    public function scopeDelBloque(Builder $consulta, int $idBloque): Builder
    {
        return $consulta->whereHas('grupo', fn ($q) => $q->where('id_bloque', $idBloque));
    }

    /**
     * Filtra por estado. Pendiente incluye los que todavía no tienen estado.
     */
    public function scopeConEstado(Builder $consulta, EstadoActividad $estado): Builder
    {
        return $estado === EstadoActividad::Pendiente
            ? $consulta->where(fn ($q) => $q->whereNull('estado_actividad')->orWhere('estado_actividad', $estado))
            : $consulta->where('estado_actividad', $estado);
    }

    /**
     * Retrasados: hoy es posterior a la fecha fin y no están completados.
     * Es la misma regla que estaRetrasado(), pero como consulta para filtrar.
     */
    public function scopeRetrasados(Builder $consulta): Builder
    {
        return $consulta
            ->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '<', today())
            ->where(fn ($q) => $q->whereNull('estado_actividad')->orWhere('estado_actividad', '!=', EstadoActividad::Completada));
    }

    /**
     * Estado para mostrar: sin valor se considera Pendiente.
     */
    public function estadoActual(): EstadoActividad
    {
        return $this->estado_actividad ?? EstadoActividad::Pendiente;
    }

    /**
     * "Retrasado" no se guarda: se calcula cada vez, porque depende del día de hoy.
     */
    public function estaRetrasado(): bool
    {
        return $this->fecha_fin !== null
            && today()->greaterThan($this->fecha_fin)
            && $this->estadoActual() !== EstadoActividad::Completada;
    }

    /**
     * En trabajo si su grupo y su actividad están activos (igual que scopeEnTrabajo).
     * Si no, solo se consulta: no se edita.
     */
    public function estaEnTrabajo(): bool
    {
        return $this->grupo->estado && $this->actividad->estado;
    }

    /**
     * Texto con la cantidad, para los mensajes: "1 seguimiento" o "3 seguimientos".
     */
    public static function textoCantidad(int $cantidad): string
    {
        return $cantidad === 1 ? '1 seguimiento' : "{$cantidad} seguimientos";
    }

    /**
     * Aviso previo: "Se generará 1 seguimiento" o "Se generarán 3 seguimientos".
     */
    public static function seGeneraran(int $cantidad): string
    {
        return ($cantidad === 1 ? 'Se generará ' : 'Se generarán ').self::textoCantidad($cantidad);
    }

    /**
     * Resultado: "Se generó 1 seguimiento" o "Se generaron 3 seguimientos".
     */
    public static function seGeneraron(int $cantidad): string
    {
        return ($cantidad === 1 ? 'Se generó ' : 'Se generaron ').self::textoCantidad($cantidad);
    }

    /**
     * Aviso antes de dar de baja un grupo o una actividad.
     */
    public static function avisoDeBaja(int $cantidad): string
    {
        return match ($cantidad) {
            0 => 'No tiene seguimientos.',
            1 => 'Tiene 1 seguimiento: queda intacto, pero no aparecerá en las listas de trabajo ni contará en el avance mientras esté de baja.',
            default => "Tiene {$cantidad} seguimientos: quedan intactos, pero no aparecerán en las listas de trabajo ni contarán en el avance mientras esté de baja.",
        };
    }
}
