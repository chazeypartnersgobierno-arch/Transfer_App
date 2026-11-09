<?php

namespace App\Http\Controllers;

use App\Exports\PlantillaDeCarga;
use App\Models\Empresa;
use App\Models\Seguimiento;
use App\Services\ArchivosDeCarga;
use App\Services\CargaDesdeExcel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Carga desde Excel. Solo admin: la ruta está protegida con el Gate administrar.
 *
 * Pasos: descargar la plantilla → subir el archivo → vista previa → confirmar.
 * El archivo subido espera en storage hasta que se confirma o se cancela; en la sesión
 * solo queda su nombre y la empresa. Al confirmar se vuelve a revisar el archivo
 * completo: no se confía en lo que se mostró antes.
 */
class CargaController extends Controller
{
    /** Llave de la sesión con la carga pendiente: ['archivo' => ..., 'id_empresa' => ..., 'original' => ...]. */
    private const SESION = 'carga';

    public function index(Request $request, ArchivosDeCarga $archivos): View
    {
        // Aprovecha para borrar los archivos viejos, por si la tarea programada no corre.
        $archivos->limpiarViejos();

        // La carga solo la hace el admin (siempre de Chazey): elige la empresa en pantalla.
        $empresas = Empresa::where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_empresa');

        return view('carga.index', ['empresas' => $empresas]);
    }

    public function plantilla(Request $request): BinaryFileResponse|RedirectResponse
    {
        $empresa = $this->empresaElegida($request);

        $archivo = 'plantilla_carga_'.Str::slug($empresa->sigla, '_').'.xlsx';

        return Excel::download(new PlantillaDeCarga($empresa), $archivo);
    }

    /**
     * Recibe el archivo, lo guarda mientras se confirma y lleva a la vista previa.
     */
    public function subir(Request $request, ArchivosDeCarga $archivos): RedirectResponse
    {
        $empresa = $this->empresaElegida($request);

        $request->validate([
            // mimes revisa el contenido del archivo, no solo su extensión.
            'archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ], [
            'archivo.required' => 'Elige el archivo.',
            'archivo.file' => 'No se recibió el archivo. Vuelve a intentarlo.',
            'archivo.uploaded' => 'No se recibió el archivo. Vuelve a intentarlo.',
            'archivo.mimes' => 'El archivo debe ser de Excel (.xlsx).',
            'archivo.max' => 'El archivo no puede pesar más de 10 MB.',
        ]);

        // Si había otra carga pendiente, su archivo ya no sirve.
        $archivos->borrar($request->session()->get(self::SESION.'.archivo'));

        $request->session()->put(self::SESION, [
            'archivo' => $archivos->guardar($request->file('archivo')),
            'id_empresa' => $empresa->id_empresa,
            'original' => $request->file('archivo')->getClientOriginalName(),
        ]);

        return redirect()->route('carga.vista-previa');
    }

    /**
     * Muestra qué se guardaría, sin guardar nada. Se puede recargar sin volver a subir el archivo.
     */
    public function vistaPrevia(Request $request, ArchivosDeCarga $archivos, CargaDesdeExcel $carga): View|RedirectResponse
    {
        [$empresa, $ruta] = $this->cargaPendiente($request, $archivos);

        if ($ruta === null) {
            return redirect()->route('carga.index')->with('error', 'La vista previa ya no está disponible. Vuelve a subir el archivo.');
        }

        return view('carga.vista-previa', [
            'empresa' => $empresa,
            'original' => $request->session()->get(self::SESION.'.original'),
            'resultado' => $carga->revisar($empresa, $ruta),
        ]);
    }

    public function confirmar(Request $request, ArchivosDeCarga $archivos, CargaDesdeExcel $carga): RedirectResponse
    {
        [$empresa, $ruta] = $this->cargaPendiente($request, $archivos);

        if ($ruta === null) {
            return redirect()->route('carga.index')->with('error', 'La carga ya no está disponible. Vuelve a subir el archivo.');
        }

        // Se revisa de nuevo: entre la vista previa y la confirmación pudo cambiar la base.
        $resultado = $carga->revisar($empresa, $ruta);

        if (! $resultado->sePuedeConfirmar()) {
            return redirect()->route('carga.vista-previa')->with('error', 'No se guardó nada: la revisión encontró errores o no hay registros nuevos.');
        }

        $totales = $carga->guardar($resultado);

        $this->olvidarCarga($request, $archivos);

        return redirect()->route('carga.index')->with('exito', $this->mensajeDeExito($empresa, $totales, $resultado->totales('Participantes')['duplicado']));
    }

    /**
     * Descarta la carga pendiente y borra su archivo.
     */
    public function cancelar(Request $request, ArchivosDeCarga $archivos): RedirectResponse
    {
        $this->olvidarCarga($request, $archivos);

        return redirect()->route('carga.index')->with('exito', 'Se canceló la carga. No se guardó nada.');
    }

    /**
     * Empresa activa elegida en pantalla.
     */
    private function empresaElegida(Request $request): Empresa
    {
        $datos = $request->validate([
            'id_empresa' => ['required', 'integer', Rule::exists('empresas', 'id_empresa')->where('estado', true)],
        ], [
            'id_empresa.required' => 'Elige una empresa.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.exists' => 'La empresa no existe o está dada de baja.',
        ]);

        return Empresa::find($datos['id_empresa']);
    }

    /**
     * La carga guardada en la sesión, si su archivo sigue ahí y la empresa sigue activa.
     *
     * @return array{0: ?Empresa, 1: ?string}
     */
    private function cargaPendiente(Request $request, ArchivosDeCarga $archivos): array
    {
        $pendiente = $request->session()->get(self::SESION);
        $empresa = Empresa::where('estado', true)->find($pendiente['id_empresa'] ?? null);
        $ruta = $empresa !== null ? $archivos->ruta($pendiente['archivo'] ?? null) : null;

        return [$empresa, $ruta];
    }

    private function olvidarCarga(Request $request, ArchivosDeCarga $archivos): void
    {
        $archivos->borrar($request->session()->pull(self::SESION.'.archivo'));
        $request->session()->forget(self::SESION);
    }

    private function mensajeDeExito(Empresa $empresa, array $totales, int $duplicados): string
    {
        $partes = [];

        if ($totales['actividades'] > 0) {
            $partes[] = $totales['actividades'].($totales['actividades'] === 1 ? ' actividad' : ' actividades')
                .' ('.Seguimiento::textoCantidad($totales['seguimientos']).')';
        }

        if ($totales['participantes'] > 0) {
            $partes[] = $totales['participantes'].($totales['participantes'] === 1 ? ' participante' : ' participantes');
        }

        $mensaje = "Se cargaron en {$empresa->nombre}: ".implode(' y ', $partes).'.';

        if ($duplicados > 0) {
            $mensaje .= ' '.($duplicados === 1 ? 'Se omitió 1 participante duplicado.' : "Se omitieron {$duplicados} participantes duplicados.");
        }

        return $mensaje;
    }
}
