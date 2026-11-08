<?php

namespace App\Services;

use App\Models\Acceso;
use App\Models\Anomalia;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HU7 (IA_PATRONES): detecta accesos inusuales para cada usuario con
 * Isolation Forest (servicio de Python, POST /v1/patrones).
 *
 * Se llama DESPUÉS de responder al ESP32 (no retrasa la apertura).
 *
 * Datos de cada acceso que se mandan:
 *   hora              hora del día en decimal (8.5 = 08:30)
 *   dia_semana        0 = domingo ... 6 = sábado
 *   accesos_dia       accesos autorizados del usuario ese día, contando este
 *   fallidos_previos  intentos denegados en el mismo lector en los 10 min anteriores
 */
class DetectorPatrones
{
    // Cuántos accesos anteriores del usuario se usan para aprender "lo normal".
    private const MAX_HISTORIAL = 200;

    // Por debajo de esto no se evalúa (el servicio también lo valida).
    private const MINIMO_HISTORIAL = 20;

    private const MINUTOS_FALLIDOS = 10;

    public function __construct(
        private ClienteFacial $clienteFacial,
        private RegistroAnomalias $registroAnomalias,
    ) {
    }

    public function evaluar(int $accesoId): void
    {
        try {
            $this->evaluarAcceso($accesoId);
        } catch (Throwable $e) {
            // Nunca debe romper el flujo de acceso: solo se registra.
            Log::error('Patrones: error al evaluar el acceso.', [
                'acceso_id' => $accesoId,
                'exception' => get_class($e),
            ]);
        }
    }

    private function evaluarAcceso(int $accesoId): void
    {
        $actual = Acceso::with('casillero')->find($accesoId);

        if (! $actual || $actual->resultado !== Acceso::AUTORIZADO || ! $actual->usuarios_id) {
            return;
        }

        // Accesos autorizados anteriores del usuario (los más recientes).
        $historial = Acceso::query()
            ->where('usuarios_id', $actual->usuarios_id)
            ->where('resultado', Acceso::AUTORIZADO)
            ->where('id', '<', $actual->id)
            ->orderByDesc('fecha_hora')
            ->limit(self::MAX_HISTORIAL)
            ->get(['id', 'fecha_hora', 'dispositivos_id', 'usuarios_id'])
            ->sortBy('fecha_hora')
            ->values();

        if ($historial->count() < self::MINIMO_HISTORIAL) {
            return;
        }

        $todos = $historial->push($actual);

        // Intentos denegados en los lectores usados, desde 10 min antes del acceso más viejo.
        $denegados = Acceso::query()
            ->where('resultado', Acceso::DENEGADO)
            ->whereIn('dispositivos_id', $todos->pluck('dispositivos_id')->filter()->unique())
            ->where('fecha_hora', '>=', Carbon::parse($todos->first()->fecha_hora)->subMinutes(self::MINUTOS_FALLIDOS))
            ->where('fecha_hora', '<=', Carbon::parse($actual->fecha_hora))
            ->get(['fecha_hora', 'dispositivos_id']);

        $caracteristicas = $this->caracteristicas($todos, $denegados);

        $datosActual = $caracteristicas->pop();

        $resultado = $this->clienteFacial->analizarPatron($caracteristicas->all(), $datosActual);

        if (! $resultado['ok'] || ! $resultado['evaluado'] || ! $resultado['anomalo']) {
            return;
        }

        $casillero = $actual->casillero ? "el casillero {$actual->casillero->numero}" : 'su casillero';

        $this->registroAnomalias->registrar([
            'tipo'              => Anomalia::TIPO_PATRON_INUSUAL,
            'descripcion'       => mb_substr(
                "Acceso inusual a {$casillero}. " . implode(' ', $resultado['motivos']),
                0,
                255
            ),
            'nivel_riesgo'      => Anomalia::RIESGO_MEDIO,
            'confianza'         => round($resultado['confianza'], 4),
            'origen'            => Anomalia::ORIGEN_IA_PATRONES,
            'organizaciones_id' => $actual->casillero?->organizaciones_id,
            'dispositivos_id'   => $actual->dispositivos_id,
            'casilleros_id'     => $actual->casilleros_id,
            'usuarios_id'       => $actual->usuarios_id,
            'accesos_id'        => $actual->id,
        ]);
    }

    /**
     * Convierte cada acceso en sus 4 datos.
     * $accesos debe venir ordenado del más viejo al más nuevo.
     */
    private function caracteristicas(Collection $accesos, Collection $denegados): Collection
    {
        $porDia = [];   // 'Y-m-d' => accesos contados hasta ahora

        return $accesos->map(function (Acceso $acceso) use (&$porDia, $denegados) {
            $fecha = Carbon::parse($acceso->fecha_hora);
            $dia   = $fecha->format('Y-m-d');

            $porDia[$dia] = ($porDia[$dia] ?? 0) + 1;

            $desde = $fecha->copy()->subMinutes(self::MINUTOS_FALLIDOS);

            $fallidos = $denegados->filter(fn ($d) => (int) $d->dispositivos_id === (int) $acceso->dispositivos_id
                && Carbon::parse($d->fecha_hora)->between($desde, $fecha))->count();

            return [
                'hora'             => round($fecha->hour + $fecha->minute / 60, 4),
                'dia_semana'       => $fecha->dayOfWeek,   // 0 = domingo
                'accesos_dia'      => $porDia[$dia],
                'fallidos_previos' => $fallidos,
            ];
        });
    }
}