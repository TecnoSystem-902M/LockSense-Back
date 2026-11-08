<?php

namespace App\Http\Controllers;

use App\Models\Casillero;
use App\Models\Dispositivo;
use App\Models\Organizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * LockSense - CRUD de dispositivos ESP32 (solo SUPERADMIN).
 *
 *   GET    /api/superadmin/dispositivos                 listar
 *   GET    /api/superadmin/dispositivos/opciones        organizaciones activas con sus casilleros
 *   POST   /api/superadmin/dispositivos                 registrar (devuelve el token UNA vez)
 *   PUT    /api/superadmin/dispositivos/{id}            modificar MAC / organización / casilleros
 *   POST   /api/superadmin/dispositivos/{id}/token      regenerar token (devuelve el nuevo)
 *   PATCH  /api/superadmin/dispositivos/{id}/estado     dar de baja / reactivar
 *
 * El token es por ESP32 (no por casillero). En la base solo se guarda su
 * hash SHA-256, el mismo que valida el middleware AutenticarDispositivo.
 * No se borran dispositivos: se dan de baja para conservar su historial.
 */
class GestionDispositivoController extends Controller
{
    private const ROL_SUPERADMIN = 1;
    private const TIPO_SISTEMA   = 'Sistema';

    // MAC en formato AA:BB:CC:DD:EE:FF (como la imprime el ESP32).
    private const FORMATO_MAC = '/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/';

    public function index(Request $request): JsonResponse
    {
        $this->soloSuperadmin($request);

        $dispositivos = Dispositivo::query()
            ->with(['organizacion', 'casilleros' => fn ($q) => $q->orderBy('numero')])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Dispositivo $d) => $this->formatear($d));

        return response()->json(['dispositivos' => $dispositivos]);
    }

    /** Organizaciones activas con sus casilleros, para el formulario. */
    public function opciones(Request $request): JsonResponse
    {
        $this->soloSuperadmin($request);

        $organizaciones = Organizacion::query()
            ->where(fn ($q) => $q->whereNull('tipo')->orWhere('tipo', '!=', self::TIPO_SISTEMA))
            ->where('estado', 'A')
            ->with(['casilleros' => fn ($q) => $q->with('dispositivo')->orderBy('numero')])
            ->orderBy('nombre')
            ->get()
            ->map(fn (Organizacion $org) => [
                'id'         => $org->id,
                'nombre'     => $org->nombre,
                'casilleros' => $org->casilleros->map(fn (Casillero $c) => [
                    'id'              => $c->id,
                    'numero'          => $c->numero,
                    // Solo cuenta como ocupado si lo controla un dispositivo ACTIVO.
                    'dispositivos_id' => $c->dispositivo?->estado === 'A' ? $c->dispositivos_id : null,
                    'dispositivo_mac' => $c->dispositivo?->estado === 'A' ? $c->dispositivo->identificador : null,
                ])->values(),
            ]);

        return response()->json(['organizaciones' => $organizaciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->soloSuperadmin($request);

        $datos = $this->validar($request);
        $token = $this->generarToken();

        $dispositivo = DB::transaction(function () use ($datos, $token) {
            $dispositivo = Dispositivo::create([
                'identificador'     => $datos['identificador'],
                'tipo'              => $datos['tipo'],
                'token'             => hash('sha256', $token),
                'estado'            => 'A',
                'organizaciones_id' => $datos['organizaciones_id'],
            ]);

            if ($dispositivo->tipo === Dispositivo::TIPO_CERRADURAS) {
                $this->vincularCasilleros($dispositivo, $datos['casilleros']);
            }

            return $dispositivo;
        });

        return response()->json([
            'message'     => 'Dispositivo registrado. Copia el token: no se volverá a mostrar.',
            'token'       => $token,
            'dispositivo' => $this->formatear($dispositivo->fresh(['organizacion', 'casilleros'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->soloSuperadmin($request);

        $dispositivo = Dispositivo::findOrFail($id);
        $datos = $this->validar($request, $dispositivo);

        DB::transaction(function () use ($dispositivo, $datos) {
            // El tipo no se cambia al modificar (se registraría otro dispositivo).
            $dispositivo->update([
                'identificador'     => $datos['identificador'],
                'organizaciones_id' => $datos['organizaciones_id'],
            ]);

            if ($dispositivo->tipo === Dispositivo::TIPO_CERRADURAS) {
                $this->vincularCasilleros($dispositivo, $datos['casilleros']);
            }
        });

        return response()->json([
            'message'     => 'Dispositivo actualizado correctamente.',
            'dispositivo' => $this->formatear($dispositivo->fresh(['organizacion', 'casilleros'])),
        ]);
    }

    /** Genera un token nuevo; el anterior deja de funcionar de inmediato. */
    public function regenerarToken(Request $request, int $id): JsonResponse
    {
        $this->soloSuperadmin($request);

        $dispositivo = Dispositivo::findOrFail($id);
        $token = $this->generarToken();

        $dispositivo->update(['token' => hash('sha256', $token)]);

        return response()->json([
            'message' => 'Token regenerado. Actualiza el código del ESP32 con el nuevo token.',
            'token'   => $token,
        ]);
    }

    public function cambiarEstado(Request $request, int $id): JsonResponse
    {
        $this->soloSuperadmin($request);

        $datos = $request->validate([
            'estado' => ['required', Rule::in(['A', 'I'])],
        ]);

        $dispositivo = Dispositivo::findOrFail($id);

        DB::transaction(function () use ($dispositivo, $datos) {
            $dispositivo->update(['estado' => $datos['estado']]);

            // Al darlo de baja se liberan sus casilleros, para que otro
            // ESP32 (por ejemplo, el que lo reemplaza) pueda tomarlos.
            if ($datos['estado'] === 'I') {
                Casillero::query()
                    ->where('dispositivos_id', $dispositivo->id)
                    ->update(['dispositivos_id' => null]);
            }
        });

        return response()->json([
            'message'     => $datos['estado'] === 'A'
                ? 'Dispositivo reactivado. Asígnale sus casilleros si es de cerraduras.'
                : 'Dispositivo dado de baja.',
            'dispositivo' => $this->formatear($dispositivo->fresh(['organizacion', 'casilleros'])),
        ]);
    }

    // ==================================================
    // Auxiliares
    // ==================================================

    private function soloSuperadmin(Request $request): void
    {
        abort_unless((int) $request->user()->roles_id === self::ROL_SUPERADMIN, 403,
            'Solo el superadministrador puede gestionar dispositivos.');
    }

    /** 64 caracteres hexadecimales, igual que los tokens que ya usan los ESP32. */
    private function generarToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function validar(Request $request, ?Dispositivo $dispositivo = null): array
    {
        // La MAC se guarda en mayúsculas, igual que la manda el ESP32.
        // Se aceptan guiones (AA-BB-...) y se convierten a dos puntos.
        $request->merge([
            'identificador' => str_replace('-', ':', strtoupper(trim((string) $request->input('identificador')))),
        ]);

        // Al modificar, el tipo es el que ya tiene.
        $tipo = $dispositivo?->tipo ?? $request->input('tipo');

        $validator = Validator::make($request->all(), [
            'identificador'     => [
                'required', 'regex:' . self::FORMATO_MAC,
                Rule::unique('dispositivos', 'identificador')->ignore($dispositivo?->id),
            ],
            'tipo'              => $dispositivo
                ? ['nullable']
                : ['required', Rule::in([Dispositivo::TIPO_CERRADURAS, Dispositivo::TIPO_CAMARA])],
            'organizaciones_id' => ['required', 'integer', 'exists:organizaciones,id'],
            'casilleros'        => $tipo === Dispositivo::TIPO_CERRADURAS
                ? ['required', 'array', 'min:1']
                : ['nullable', 'array'],
            'casilleros.*'      => ['integer', 'distinct'],
        ], [
            'identificador.required'     => 'La MAC es obligatoria.',
            'identificador.regex'        => 'La MAC debe tener el formato AA:BB:CC:DD:EE:FF.',
            'identificador.unique'       => 'Ya hay un dispositivo registrado con esa MAC.',
            'tipo.required'              => 'Selecciona el tipo de dispositivo.',
            'tipo.in'                    => 'El tipo seleccionado no es válido.',
            'organizaciones_id.required' => 'Selecciona la organización.',
            'organizaciones_id.exists'   => 'La organización seleccionada no existe.',
            'casilleros.required'        => 'Selecciona al menos un casillero.',
            'casilleros.min'             => 'Selecciona al menos un casillero.',
        ]);

        $validator->after(function ($validator) use ($request, $dispositivo, $tipo) {
            if ($validator->errors()->has('organizaciones_id')) {
                return;
            }

            $org = Organizacion::find($request->input('organizaciones_id'));

            if (! $org || $org->tipo === self::TIPO_SISTEMA) {
                $validator->errors()->add('organizaciones_id', 'La organización seleccionada no es válida.');
                return;
            }

            $esSuOrganizacion = $dispositivo && (int) $dispositivo->organizaciones_id === (int) $org->id;

            if ($org->estado !== 'A' && ! $esSuOrganizacion) {
                $validator->errors()->add('organizaciones_id', 'La organización está dada de baja.');
                return;
            }

            if ($tipo !== Dispositivo::TIPO_CERRADURAS || $validator->errors()->has('casilleros')) {
                return;
            }

            $ids = array_map('intval', (array) $request->input('casilleros', []));

            $casilleros = Casillero::query()
                ->with('dispositivo')
                ->whereIn('id', $ids)
                ->get();

            // Todos deben ser de la organización elegida...
            if ($casilleros->count() !== count($ids)
                || $casilleros->contains(fn ($c) => (int) $c->organizaciones_id !== (int) $org->id)) {
                $validator->errors()->add('casilleros', 'Algún casillero no pertenece a la organización seleccionada.');
                return;
            }

            // ...y no estar controlados por OTRO dispositivo activo.
            $ocupado = $casilleros->first(fn ($c) => $c->dispositivos_id
                && (! $dispositivo || (int) $c->dispositivos_id !== (int) $dispositivo->id)
                && $c->dispositivo?->estado === 'A');

            if ($ocupado) {
                $validator->errors()->add('casilleros',
                    "El casillero {$ocupado->numero} ya lo controla otro dispositivo ({$ocupado->dispositivo->identificador}).");
            }
        });

        return $validator->validate();
    }

    /** Liga exactamente los casilleros indicados; los demás que tenía se liberan. */
    private function vincularCasilleros(Dispositivo $dispositivo, array $ids): void
    {
        $ids = array_map('intval', $ids);

        Casillero::query()
            ->where('dispositivos_id', $dispositivo->id)
            ->whereNotIn('id', $ids)
            ->update(['dispositivos_id' => null]);

        Casillero::query()
            ->whereIn('id', $ids)
            ->update(['dispositivos_id' => $dispositivo->id]);
    }

    private function formatear(Dispositivo $dispositivo): array
    {
        return [
            'id'              => $dispositivo->id,
            'identificador'   => $dispositivo->identificador,
            'tipo'            => $dispositivo->tipo,
            'estado'          => $dispositivo->estado,
            'ultima_conexion' => $dispositivo->ultima_conexion?->format('d/m/Y H:i'),
            'organizacion'    => $dispositivo->organizacion ? [
                'id'     => $dispositivo->organizacion->id,
                'nombre' => $dispositivo->organizacion->nombre,
            ] : null,
            'casilleros'      => $dispositivo->casilleros->map(fn (Casillero $c) => [
                'id'     => $c->id,
                'numero' => $c->numero,
            ])->values(),
        ];
    }
}