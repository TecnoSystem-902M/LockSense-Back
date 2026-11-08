<?php

namespace App\Http\Controllers;

use App\Models\Acceso;
use App\Models\Anomalia;
use App\Models\Casillero;
use App\Models\EventoCasillero;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LockSense - Dashboard del usuario (rol USUARIO).
 *
 * GET /api/usuario/dashboard
 *   Nombre, estado de su casillero, accesos del mes, alertas de hoy y
 *   anomalías por día de los últimos 7 días (gráfica).
 *
 * GET /api/usuario/casillero
 *   Estado de la puerta (abierto, cerrado o bloqueado), sus 10 accesos
 *   más recientes y los 10 últimos estados de la puerta.
 *
 * GET /api/usuario/actividad
 *   Toda la actividad del usuario: sus accesos (autorizados y fallidos),
 *   los eventos de su casillero y las anomalías detectadas por la IA.
 *
 * Solo datos del propio usuario.
 */
class PanelUsuarioController extends Controller
{
    private const ROL_USUARIO = 3;

    // Estados que puede tener el casillero desde el punto de vista del usuario.
    private const ESTADO_DESOCUPADO = 'DESOCUPADO';   // no tiene casillero asignado
    private const ESTADO_BLOQUEADO  = 'BLOQUEADO';    // su casillero tiene un bloqueo activo
    private const ESTADO_ALERTA     = 'ALERTA';       // hubo una alerta hace poco
    private const ESTADO_SEGURO     = 'SEGURO';       // tiene casillero, sin bloqueo ni alertas recientes

    // Minutos que el panel muestra "En alerta" despues de la ultima alerta.
    private const MINUTOS_EN_ALERTA = 60;

    // Días que muestra la gráfica de anomalías.
    private const DIAS_GRAFICA = 7;

    private const DIAS_SEMANA = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    // Estado de la puerta (vista Mi casillero).
    private const PUERTA_ABIERTO   = 'ABIERTO';
    private const PUERTA_CERRADO   = 'CERRADO';
    private const PUERTA_BLOQUEADO = 'BLOQUEADO';

    // Eventos del ESP32 que indican que la puerta quedó abierta o cerrada.
    private const EVENTOS_ABIERTO = ['PUERTA_ABIERTA', 'APERTURA_NO_AUTORIZADA', 'PUERTA_ABIERTA_PROLONGADA'];
    private const EVENTOS_CERRADO = ['PUERTA_CERRADA'];

    private const LIMITE_REGISTROS = 10;

    private const METODOS = [
        'rfid'   => 'RFID/NFC',
        'facial' => 'Rostro',
    ];

    private const RIESGO_SEGURO     = 'Seguro';
    private const RIESGO_SOSPECHOSO = 'Sospechoso';

    // Eventos del casillero: texto para el usuario y nivel de riesgo.
    private const EVENTOS_ACTIVIDAD = [
        'PUERTA_ABIERTA'            => ['Abierto', self::RIESGO_SEGURO],
        'PUERTA_CERRADA'            => ['Cerrado', self::RIESGO_SEGURO],
        'PUERTA_NO_ABIERTA'         => ['Acceso autorizado sin abrir la puerta', self::RIESGO_SEGURO],
        'APERTURA_NO_AUTORIZADA'    => ['Apertura no autorizada', self::RIESGO_SOSPECHOSO],
        'VIBRACION_DETECTADA'       => ['La puerta ha sido golpeada', self::RIESGO_SOSPECHOSO],
        'PUERTA_ABIERTA_PROLONGADA' => ['Puerta abierta demasiado tiempo', self::RIESGO_SOSPECHOSO],
        'ASIGNACION'                => ['Casillero asignado', self::RIESGO_SEGURO],
        'BLOQUEO'                   => ['Casillero bloqueado por el administrador', self::RIESGO_SEGURO],
        'DESBLOQUEO'                => ['Casillero desbloqueado por el administrador', self::RIESGO_SEGURO],
    ];

    // Eventos causados por alguien desconocido o por el administrador.
    private const EVENTOS_DESCONOCIDO = ['APERTURA_NO_AUTORIZADA', 'VIBRACION_DETECTADA', 'PUERTA_ABIERTA_PROLONGADA'];
    private const EVENTOS_SISTEMA     = ['ASIGNACION', 'BLOQUEO', 'DESBLOQUEO'];

    // Motivos de acceso denegado.
    private const MOTIVOS_DENEGADO = [
        'tarjeta_inactiva'       => 'Intento de acceso con tarjeta inactiva',
        'casillero_bloqueado'    => 'Intento de acceso con el casillero bloqueado',
        'lector_bloqueado'       => 'Intento de acceso con el lector bloqueado',
        'sin_casillero_asignado' => 'Intento de acceso sin casillero asignado',
    ];

    // Anomalías detectadas por IA (las de reglas ya salen como eventos o accesos).
    private const ANOMALIAS_IA = [
        'PERSONAS_MULTIPLES' => 'Varias personas frente al casillero',
        'OBJETO_SOSPECHOSO'  => 'Objeto sospechoso detectado',
        'PATRON_INUSUAL'     => 'Patrón de acceso inusual',
    ];

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun',
                           'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function resumen(Request $request): JsonResponse
    {
        $usuario = $request->user();

        abort_unless((int) $usuario->roles_id === self::ROL_USUARIO, 403,
            'Solo los usuarios pueden ver este panel.');

        // Casillero asignado actualmente al usuario (si tiene).
        $casillero = Casillero::query()
            ->whereHas('asignacionActiva', fn ($q) => $q->where('usuarios_id', $usuario->id))
            ->with('bloqueoActivo')
            ->first();

        // Accesos autorizados del usuario en el mes actual.
        $accesosMes = Acceso::query()
            ->where('usuarios_id', $usuario->id)
            ->where('resultado', Acceso::AUTORIZADO)
            ->where('fecha_hora', '>=', now()->startOfMonth())
            ->count();

        // Anomalías detectadas HOY en el casillero del usuario.
        $alertasHoy = Anomalia::query()
            ->where('usuarios_id', $usuario->id)
            ->where('fecha_hora', '>=', now()->startOfDay())
            ->count();

        // ¿Hubo alguna alerta en los ultimos minutos?
        $alertaReciente = Anomalia::query()
            ->where('usuarios_id', $usuario->id)
            ->where('fecha_hora', '>=', now()->subMinutes(self::MINUTOS_EN_ALERTA))
            ->exists();

        return response()->json([
            'nombre'      => $usuario->nombre,
            'casillero'   => $casillero?->numero,
            'estado'      => $this->calcularEstado($casillero, $alertaReciente),
            'accesos_mes' => $accesosMes,
            'alertas_hoy' => $alertasHoy,
            'grafica'     => $this->anomaliasPorDia($usuario->id),
        ]);
    }

    /**
     * Anomalías del usuario en cada uno de los últimos 7 días (incluido hoy).
     * Incluye todas las fuentes: reglas, YOLO y (después) Isolation Forest.
     */
    private function anomaliasPorDia(int $usuarioId): array
    {
        $inicio = now()->startOfDay()->subDays(self::DIAS_GRAFICA - 1);

        $conteo = Anomalia::query()
            ->where('usuarios_id', $usuarioId)
            ->where('fecha_hora', '>=', $inicio)
            ->get(['id', 'fecha_hora'])
            ->countBy(fn (Anomalia $a) => Carbon::parse($a->fecha_hora)->format('Y-m-d'));

        $dias = [];

        for ($i = 0; $i < self::DIAS_GRAFICA; $i++) {
            $fecha = $inicio->copy()->addDays($i);

            $dias[] = [
                'fecha' => $fecha->format('d/m/Y'),
                'dia'   => self::DIAS_SEMANA[$fecha->dayOfWeek],
                'total' => $conteo->get($fecha->format('Y-m-d'), 0),
            ];
        }

        return $dias;
    }

    public function casillero(Request $request): JsonResponse
    {
        $usuario = $request->user();

        abort_unless((int) $usuario->roles_id === self::ROL_USUARIO, 403,
            'Solo los usuarios pueden ver su casillero.');

        $casillero = Casillero::query()
            ->whereHas('asignacionActiva', fn ($q) => $q->where('usuarios_id', $usuario->id))
            ->with(['asignacionActiva', 'bloqueoActivo'])
            ->first();

        // 10 accesos autorizados más recientes del usuario.
        $accesos = Acceso::query()
            ->where('usuarios_id', $usuario->id)
            ->where('resultado', Acceso::AUTORIZADO)
            ->orderByDesc('fecha_hora')
            ->limit(self::LIMITE_REGISTROS)
            ->get()
            ->map(fn (Acceso $acceso) => [
                'id'     => $acceso->id,
                'metodo' => self::METODOS[$acceso->metodo] ?? $acceso->metodo,
                'fecha'  => $this->formatearDia($acceso->fecha_hora),
                'hora'   => $acceso->fecha_hora?->format('H:i'),
            ]);

        if (! $casillero) {
            return response()->json([
                'casillero' => null,
                'estado'    => null,
                'accesos'   => $accesos,
                'estados'   => [],
            ]);
        }

        // Solo eventos desde que el casillero es de este usuario
        // (no se muestran los del dueño anterior).
        $desde = $casillero->asignacionActiva->fecha_asignacion;

        $eventosPuerta = EventoCasillero::query()
            ->where('casilleros_id', $casillero->id)
            ->whereIn('tipo_evento', array_merge(self::EVENTOS_ABIERTO, self::EVENTOS_CERRADO))
            ->when($desde, fn ($q) => $q->where('fecha_hora', '>=', $desde))
            ->orderByDesc('fecha_hora')
            ->limit(self::LIMITE_REGISTROS)
            ->get();

        $estados = $eventosPuerta->map(fn (EventoCasillero $evento) => [
            'id'     => $evento->id,
            'estado' => $this->estadoDeEvento($evento),
            'fecha'  => $this->formatearDia($evento->fecha_hora),
            'hora'   => $evento->fecha_hora?->format('H:i'),
        ]);

        // Estado actual: bloqueado manda; si no, el último evento de la puerta.
        // Sin eventos todavía, se asume cerrado.
        if ($casillero->bloqueoActivo) {
            $estadoActual = self::PUERTA_BLOQUEADO;
        } elseif ($eventosPuerta->isNotEmpty()) {
            $estadoActual = $this->estadoDeEvento($eventosPuerta->first());
        } else {
            $estadoActual = self::PUERTA_CERRADO;
        }

        return response()->json([
            'casillero' => $casillero->numero,
            'estado'    => $estadoActual,
            'accesos'   => $accesos,
            'estados'   => $estados,
        ]);
    }

    public function actividad(Request $request): JsonResponse
    {
        $usuario = $request->user();

        abort_unless((int) $usuario->roles_id === self::ROL_USUARIO, 403,
            'Solo los usuarios pueden ver su actividad.');

        $nombre = trim($usuario->nombre . ' ' . $usuario->apellido_pa);

        // 1) Accesos del usuario (autorizados y fallidos).
        $accesos = Acceso::query()
            ->where('usuarios_id', $usuario->id)
            ->get()
            ->map(fn (Acceso $acceso) => [
                'clave'      => 'acceso-' . $acceso->id,
                'usuario'    => $nombre,
                'tipo'       => $this->tipoDeAcceso($acceso),
                'riesgo'     => $acceso->resultado === Acceso::AUTORIZADO
                    ? self::RIESGO_SEGURO
                    : self::RIESGO_SOSPECHOSO,
                'fecha_hora' => $acceso->fecha_hora,
            ]);

        // 2) Eventos de su casillero actual, desde que se le asignó.
        $eventos = collect();

        $casillero = Casillero::query()
            ->whereHas('asignacionActiva', fn ($q) => $q->where('usuarios_id', $usuario->id))
            ->with('asignacionActiva')
            ->first();

        if ($casillero) {
            $desde = $casillero->asignacionActiva->fecha_asignacion;

            $eventos = EventoCasillero::query()
                ->where('casilleros_id', $casillero->id)
                ->whereIn('tipo_evento', array_keys(self::EVENTOS_ACTIVIDAD))
                ->when($desde, fn ($q) => $q->where('fecha_hora', '>=', $desde))
                ->get()
                ->map(function (EventoCasillero $evento) use ($nombre) {
                    [$tipo, $riesgo] = self::EVENTOS_ACTIVIDAD[$evento->tipo_evento];

                    return [
                        'clave'      => 'evento-' . $evento->id,
                        'usuario'    => $this->autorDeEvento($evento->tipo_evento, $nombre),
                        'tipo'       => $tipo,
                        'riesgo'     => $riesgo,
                        'fecha_hora' => $evento->fecha_hora,
                    ];
                });
        }

        // 3) Anomalías detectadas por la IA en su casillero.
        $anomalias = Anomalia::query()
            ->where('usuarios_id', $usuario->id)
            ->whereIn('tipo', array_keys(self::ANOMALIAS_IA))
            ->get()
            ->map(fn (Anomalia $anomalia) => [
                'clave'      => 'anomalia-' . $anomalia->id,
                'usuario'    => 'Desconocido',
                'tipo'       => self::ANOMALIAS_IA[$anomalia->tipo],
                'riesgo'     => self::RIESGO_SOSPECHOSO,
                'fecha_hora' => $anomalia->fecha_hora,
            ]);

        // Todo junto, del más reciente al más antiguo.
        $actividades = $accesos
            ->concat($eventos)
            ->concat($anomalias)
            ->filter(fn ($registro) => $registro['fecha_hora'] !== null)
            ->sortByDesc(fn ($registro) => $registro['fecha_hora']->timestamp)
            ->values();

        $total = $actividades->count();

        $actividades = $actividades->map(fn ($registro, $indice) => [
            'id'      => $total - $indice,   // el más reciente lleva el número más alto
            'clave'   => $registro['clave'],
            'usuario' => $registro['usuario'],
            'tipo'    => $registro['tipo'],
            'riesgo'  => $registro['riesgo'],
            'fecha'   => $registro['fecha_hora']->format('d/m/Y'),
            'hora'    => $registro['fecha_hora']->format('H:i'),
        ]);

        return response()->json([
            'total'       => $total,
            'actividades' => $actividades,
        ]);
    }

    private function tipoDeAcceso(Acceso $acceso): string
    {
        if ($acceso->resultado === Acceso::AUTORIZADO) {
            return $acceso->metodo === 'facial' ? 'Acceso facial' : 'Acceso RFID/NFC';
        }

        return self::MOTIVOS_DENEGADO[$acceso->motivo] ?? 'Intento de acceso fallido';
    }

    private function autorDeEvento(string $tipoEvento, string $nombre): string
    {
        if (in_array($tipoEvento, self::EVENTOS_DESCONOCIDO, true)) {
            return 'Desconocido';
        }

        if (in_array($tipoEvento, self::EVENTOS_SISTEMA, true)) {
            return 'Administrador';
        }

        return $nombre;
    }

    private function estadoDeEvento(EventoCasillero $evento): string
    {
        return in_array($evento->tipo_evento, self::EVENTOS_ABIERTO, true)
            ? self::PUERTA_ABIERTO
            : self::PUERTA_CERRADO;
    }

    /** "Hoy", "Ayer" o "30 oct". */
    private function formatearDia(?Carbon $fecha): ?string
    {
        if (! $fecha) {
            return null;
        }

        if ($fecha->isToday()) {
            return 'Hoy';
        }

        if ($fecha->isYesterday()) {
            return 'Ayer';
        }

        return $fecha->day . ' ' . self::MESES[$fecha->month - 1];
    }

    private function calcularEstado(?Casillero $casillero, bool $alertaReciente): string
    {
        if (! $casillero) {
            return self::ESTADO_DESOCUPADO;
        }

        if ($casillero->bloqueoActivo) {
            return self::ESTADO_BLOQUEADO;
        }

        // "En alerta" solo durante MINUTOS_EN_ALERTA despues de la ultima alerta.
        if ($alertaReciente) {
            return self::ESTADO_ALERTA;
        }

        return self::ESTADO_SEGURO;
    }
}