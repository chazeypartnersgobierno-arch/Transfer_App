<?php

namespace App\View\Composers;

use App\Models\AvanceValidacion;
use App\Models\Fase;
use App\Models\Seguimiento;
use App\Models\Sesion;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * Un View Composer le pasa datos a una vista cada vez que se dibuja, sin que cada
 * controlador tenga que mandarlos. Este le da al menú lateral (layouts.app) las fases
 * del submenú de Seguimientos: cada fase es su propia pantalla.
 * Se registra en AppServiceProvider.
 */
class MenuDeSeguimientos
{
    public function __construct(private Request $request) {}

    public function compose(View $view): void
    {
        // Solo quien da seguimiento ve esa sección del menú: a los demás no se les hace la consulta.
        if (! $this->request->user()?->can('dar-seguimiento')) {
            $view->with(['menuFases' => collect(), 'menuFaseActual' => null, 'menuFiltros' => []]);

            return;
        }

        $fases = Fase::orderBy('orden')->get();

        $view->with([
            'menuFases' => $fases,
            'menuFaseActual' => $this->faseActual($fases),
            'menuFiltros' => $this->filtrosQueSeConservan(),
        ]);
    }

    /**
     * La fase que se resalta en el submenú: la de la lista que se está viendo o la del
     * seguimiento (o de su sesión o avance) que se está capturando. En otra pantalla, ninguna.
     */
    private function faseActual($fases): ?int
    {
        if ($this->request->routeIs('seguimientos.index')) {
            $id = $this->request->query('id_fase');

            // La misma regla que Fase::elegidaOPrimera, con las fases ya cargadas.
            return ($fases->firstWhere('id_fase', is_numeric($id) ? (int) $id : null) ?? $fases->first())?->id_fase;
        }

        // Las rutas reciben el modelo ya cargado (route model binding).
        $seguimiento = $this->request->route('seguimiento');
        $seguimiento ??= $this->request->route('sesion') instanceof Sesion ? $this->request->route('sesion')->seguimiento : null;
        $seguimiento ??= $this->request->route('avance') instanceof AvanceValidacion ? $this->request->route('avance')->seguimiento : null;

        return $seguimiento instanceof Seguimiento ? $seguimiento->id_fase : null;
    }

    /**
     * Si ya se está en la lista, al cambiar de fase se conservan los filtros (proceso, grupo,
     * estado, búsqueda...). La página se reinicia porque la nueva fase tiene otro número de filas.
     */
    private function filtrosQueSeConservan(): array
    {
        return $this->request->routeIs('seguimientos.index')
            ? Arr::except($this->request->query(), ['id_fase', 'page'])
            : [];
    }
}
