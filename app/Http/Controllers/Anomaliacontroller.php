<?php

namespace App\Http\Controllers;

use App\Models\Anomalia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HU7: consulta y revision de anomalias (panel del ADMINISTRADOR).
 *
 *   GET   /api/anomalias              Lista (filtros: estado, tipo, nivel_riesgo, desde, hasta)
 *   GET   /api/anomalias/resumen      Conteos para el dashboard
 *   PATCH /api/anomalias/{id}/estado  Marcar como revisada o descartada
 *   GET   /api/anomalias/{id}/evidencia  Foto de evidencia (imagen JPG)
 *
 * Cada administrador solo ve las anomalias de su organizacion.
 */
class AnomaliaController extends Controller
{
    private const ROL_ADMINISTRADOR = 2;

    public function index(Request $request): JsonResponse
    {
        $usuario = $this->administrador($request);

        $filtros = $request->validate([
            'estado'       => ['nullable', Rule::in(Anomalia::ESTADOS)],
            'tipo'         => ['nullable', 'string', 'max:100'],
            'nivel_riesgo' => ['nullable', 'string', 'max:20'],
            'desde'        => ['nullable', 'date'],
            'hasta'        => ['nullable', 'date'],
            'por_pagina'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $anomalias = Anomalia::with(['casillero:id,numero', 'revisor:id,nombre,apellido_pa'])
            ->where('organizaciones_id', $usuario->organizaciones_id)
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($filtros['nivel_riesgo'] ?? null, fn ($q, $v) => $q->where('nivel_riesgo', $v))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('fecha_hora', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('fecha_hora', '<=', $v . ' 23:59:59'))
            ->orderByDesc('fecha_hora')
            ->orderByDesc('id')
            ->paginate($filtros['por_pagina'] ?? 20);

        $anomalias->getCollection()->transform(fn (Anomalia $a) => $this->formato($a));

        return response()->json($anomalias);
    }

    public function resumen(Request $request): JsonResponse
    {
        $usuario = $this->administrador($request);

        $base = Anomalia::where('organizaciones_id', $usuario->organizaciones_id);

        return response()->json([
            'pendientes'      => (clone $base)->where('estado', Anomalia::PENDIENTE)->count(),
            'ultimas_24h'     => (clone $base)->where('fecha_hora', '>=', now()->subDay())->count(),
            'por_nivel'       => (clone $base)->where('estado', Anomalia::PENDIENTE)
                                    ->selectRaw('nivel_riesgo, COUNT(*) AS total')
                                    ->groupBy('nivel_riesgo')
                                    ->pluck('total', 'nivel_riesgo'),
        ]);
    }

    public function actualizarEstado(Request $request, int $id): JsonResponse
    {
        $usuario = $this->administrador($request);

        $data = $request->validate([
            'estado' => ['required', Rule::in([Anomalia::REVISADA, Anomalia::DESCARTADA, Anomalia::PENDIENTE])],
        ]);

        $anomalia = Anomalia::where('organizaciones_id', $usuario->organizaciones_id)
            ->findOrFail($id);

        $anomalia->update([
            'estado'         => $data['estado'],
            'revisada_por'   => $data['estado'] === Anomalia::PENDIENTE ? null : $usuario->id,
            'fecha_revision' => $data['estado'] === Anomalia::PENDIENTE ? null : now(),
        ]);

        return response()->json([
            'message'  => 'Anomalía actualizada.',
            'anomalia' => $this->formato($anomalia->load(['casillero:id,numero', 'revisor:id,nombre,apellido_pa'])),
        ]);
    }

    /**
     * Devuelve la foto de evidencia. Esta guardada en el disco privado, asi
     * que solo se puede ver con el token del administrador.
     * (En React: pedirla con axios responseType 'blob' y mostrarla con
     *  URL.createObjectURL.)
     */
    public function evidencia(Request $request, int $id): StreamedResponse
    {
        $usuario = $this->administrador($request);

        $anomalia = Anomalia::where('organizaciones_id', $usuario->organizaciones_id)
            ->findOrFail($id);

        abort_unless($anomalia->evidencia_url && Storage::disk('local')->exists($anomalia->evidencia_url),
            404, 'Esta anomalía no tiene foto de evidencia.');

        return Storage::disk('local')->response($anomalia->evidencia_url);
    }

    // --------------------------------------------------

    private function administrador(Request $request)
    {
        $usuario = $request->user();

        abort_unless((int) $usuario->roles_id === self::ROL_ADMINISTRADOR, 403,
            'Solo el administrador puede consultar las anomalías.');

        return $usuario;
    }

    private function formato(Anomalia $a): array
    {
        return [
            'id'             => $a->id,
            'tipo'           => $a->tipo,
            'descripcion'    => $a->descripcion,
            'nivel_riesgo'   => $a->nivel_riesgo,
            'confianza'      => $a->confianza,
            'estado'         => $a->estado,
            'origen'         => $a->origen,
            'fecha_hora'     => $a->fecha_hora?->toIso8601String(),
            'casillero'      => $a->casillero?->numero,
            'tiene_evidencia' => (bool) $a->evidencia_url,
            'accesos_id'     => $a->accesos_id,
            'revisada_por'   => $a->revisor
                ? trim($a->revisor->nombre . ' ' . $a->revisor->apellido_pa)
                : null,
            'fecha_revision' => $a->fecha_revision?->toIso8601String(),
        ];
    }
}