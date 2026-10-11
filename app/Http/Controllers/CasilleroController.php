<?php

namespace App\Http\Controllers;

use App\Models\Asignacion;
use App\Models\Casillero;
use App\Models\CredencialRfid;
use App\Models\EventoCasillero;
use App\Models\Rol;
use App\Models\UsuarioCrud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CasilleroController extends Controller
{
    private function esSuperadmin($user): bool
    {
        return $user && (int) $user->roles_id === Rol::SUPERADMIN;
    }

    private function esAdministrador($user): bool
    {
        return $user && (int) $user->roles_id === Rol::ADMINISTRADOR;
    }

    /**
     * Solo SUPERADMIN o ADMINISTRADOR pueden operar el módulo de casilleros.
     * Devuelve una respuesta 403 si no está autorizado, o null si lo está.
     */
    private function denegarSiNoEsAdministrativo($user)
    {
        if (!$this->esSuperadmin($user) && !$this->esAdministrador($user)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        return null;
    }

    /**
     * Localiza un casillero respetando el alcance del usuario autenticado.
     * - SUPERADMIN: cualquier casillero.
     * - ADMINISTRADOR: solo los de SU organización (derivada del token).
     * Si no existe o está fuera de alcance, devuelve null (el llamador
     * responde 404 sin revelar la existencia a otra organización).
     */
    private function buscarCasilleroEnAmbito($user, $id): ?Casillero
    {
        $query = Casillero::query()->whereKey($id);

        if (!$this->esSuperadmin($user)) {
            $query->where('organizaciones_id', $user->organizaciones_id);
        }

        return $query->first();
    }

    /**
     * Serializa un casillero exponiendo la ocupación, el bloqueo y su motivo
     * por separado. Nunca incluye datos sensibles del usuario.
     *
     * @param array<int,string> $uidsPorUsuario  mapa usuarios_id => uid
     */
    private function serializar(Casillero $c, array $uidsPorUsuario = []): array
    {
        $asignacion = $c->asignacionActiva;
        $usuario = $asignacion?->usuario;

        $usuarioData = null;
        if ($usuario) {
            $usuarioData = [
                'id'               => $usuario->id,
                'nombre'           => $usuario->nombre,
                'apellido_paterno' => $usuario->apellido_paterno,
                'apellido_materno' => $usuario->apellido_materno,
                'nombre_completo'  => trim(implode(' ', array_filter([
                    $usuario->nombre,
                    $usuario->apellido_paterno,
                    $usuario->apellido_materno,
                ]))),
                'correo'           => $usuario->correo,
            ];
        }

        return [
            'id'                => $c->id,
            'numero'            => $c->numero,
            // `estado` legado (varchar de presentación) se conserva por
            // compatibilidad, pero la ocupación real es el booleano `ocupado`.
            'estado'            => $c->estado,
            'ubicacion'         => $c->ubicacion,
            'organizaciones_id' => $c->organizaciones_id,
            // Ocupación derivada EXCLUSIVAMENTE de la asignación activa.
            'ocupado'           => $asignacion !== null,
            // Bloqueo como atributo independiente del casillero.
            'bloqueado'         => (bool) $c->bloqueado,
            'motivo_bloqueo'    => $c->motivo_bloqueo,
            'asignacion'        => $asignacion ? [
                'id'               => $asignacion->id,
                'fecha_asignacion' => $asignacion->fecha_asignacion,
            ] : null,
            'usuario'           => $usuarioData,
            'uid_rfid'          => $usuario ? ($uidsPorUsuario[$usuario->id] ?? null) : null,
        ];
    }

    /**
     * Listado de casilleros para el monitoreo.
     * - SUPERADMIN: casilleros de todas las organizaciones.
     * - ADMINISTRADOR: solo los de su propia organización (derivada del token).
     * - USUARIO (u otro): 403.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        // Eager loading para evitar N+1: asignación activa + su usuario.
        $query = Casillero::query()
            ->with(['asignacionActiva.usuario'])
            ->orderBy('id');

        if (!$this->esSuperadmin($user)) {
            // El alcance del administrador se obtiene del usuario autenticado,
            // nunca de un parámetro enviado por el cliente.
            $query->where('organizaciones_id', $user->organizaciones_id);
        }

        $casilleros = $query->get();

        // Pre-cargar los UID RFID de los usuarios asignados en una sola consulta.
        $usuariosIds = $casilleros
            ->map(fn ($c) => optional($c->asignacionActiva)->usuarios_id)
            ->filter()
            ->unique()
            ->values();

        $uidsPorUsuario = CredencialRfid::query()
            ->whereIn('usuarios_id', $usuariosIds)
            ->where('estado', true)
            ->get(['usuarios_id', 'uid'])
            ->groupBy('usuarios_id')
            ->map(fn ($grupo) => $grupo->first()->uid)
            ->all();

        $data = $casilleros->map(fn (Casillero $c) => $this->serializar($c, $uidsPorUsuario));

        return response()->json($data);
    }

    /**
     * Bloquea un casillero.
     * Reglas:
     *  - Solo SUPERADMIN/ADMINISTRADOR; el admin solo en su organización.
     *  - motivo_bloqueo ∈ { ADMINISTRATIVO, MANTENIMIENTO }.
     *  - ADMINISTRATIVO requiere asignación activa; MANTENIMIENTO no.
     *  - No modifica `asignaciones` ni `credenciales_rfid`.
     *  - Registra un evento en `eventos_casillero`.
     */
    public function bloquear(Request $request, $id)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'motivo_bloqueo' => ['required', 'string', 'in:'.implode(',', Casillero::MOTIVOS)],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $casillero = $this->buscarCasilleroEnAmbito($user, $id);
        if (!$casillero) {
            return response()->json(['message' => 'Casillero no encontrado.'], 404);
        }

        $motivo = $request->input('motivo_bloqueo');

        // Regla 8: no se permite bloqueo ADMINISTRATIVO sin asignación activa.
        if ($motivo === Casillero::MOTIVO_ADMINISTRATIVO && !$casillero->estaOcupado()) {
            return response()->json([
                'message' => 'No se puede aplicar un bloqueo administrativo a un casillero sin asignación activa.',
            ], 422);
        }

        DB::transaction(function () use ($casillero, $motivo) {
            $casillero->bloqueado = true;
            $casillero->motivo_bloqueo = $motivo;
            $casillero->save();

            // Registrar evento (no toca asignaciones ni credenciales).
            EventoCasillero::query()->create([
                'casilleros_id' => $casillero->id,
                'tipo_evento'   => 'BLOQUEO',
                'descripcion'   => 'Bloqueo '.$motivo,
                'fecha_hora'    => now(),
            ]);
        });

        // Releer con la asignación activa para serializar la ocupación real.
        $casillero->load('asignacionActiva.usuario');

        return response()->json([
            'message'   => 'Casillero bloqueado correctamente.',
            'casillero' => $this->serializar($casillero, $this->uidsDe($casillero)),
        ]);
    }

    /**
     * Desbloquea un casillero.
     * Reglas:
     *  - Solo SUPERADMIN/ADMINISTRADOR; el admin solo en su organización.
     *  - Establece bloqueado = false y motivo_bloqueo = null.
     *  - No modifica `asignaciones` ni `credenciales_rfid`; la ocupación se
     *    recalcula desde la asignación activa.
     *  - Registra un evento en `eventos_casillero`.
     */
    public function desbloquear(Request $request, $id)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $casillero = $this->buscarCasilleroEnAmbito($user, $id);
        if (!$casillero) {
            return response()->json(['message' => 'Casillero no encontrado.'], 404);
        }

        DB::transaction(function () use ($casillero) {
            $casillero->bloqueado = false;
            $casillero->motivo_bloqueo = null;
            $casillero->save();

            EventoCasillero::query()->create([
                'casilleros_id' => $casillero->id,
                'tipo_evento'   => 'DESBLOQUEO',
                'descripcion'   => 'Desbloqueo',
                'fecha_hora'    => now(),
            ]);
        });

        $casillero->load('asignacionActiva.usuario');

        return response()->json([
            'message'   => 'Casillero desbloqueado correctamente.',
            'casillero' => $this->serializar($casillero, $this->uidsDe($casillero)),
        ]);
    }

    /**
     * Usuarios ELEGIBLES para asignarse a un casillero.
     * Reglas:
     *  - Solo SUPERADMIN/ADMINISTRADOR (si no, 403).
     *  - El casillero debe existir y estar en el ámbito del usuario (si no, 404).
     *  - Candidatos: cuentas con rol USUARIO, activas (estado = true), de la
     *    MISMA organización del casillero.
     *  - Se excluye al propio usuario autenticado (no puede asignarse a sí mismo)
     *    y, por el filtro de rol, cualquier cuenta ADMINISTRADOR/SUPERADMIN.
     */
    public function usuariosAsignables(Request $request, $id)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $casillero = $this->buscarCasilleroEnAmbito($user, $id);
        if (!$casillero) {
            return response()->json(['message' => 'Casillero no encontrado.'], 404);
        }

        $candidatos = UsuarioCrud::query()
            ->where('estado', true)
            ->where('roles_id', Rol::USUARIO)
            ->where('organizaciones_id', $casillero->organizaciones_id)
            ->where('id', '!=', $user->id) // no asignarse a sí mismo
            ->orderBy('id')
            ->get(['id', 'nombre', 'apellido_paterno', 'apellido_materno', 'correo']);

        return response()->json($candidatos);
    }

    /**
     * Asigna un casillero a un usuario.
     * Reglas (todas verificadas en servidor):
     *  - Solo SUPERADMIN/ADMINISTRADOR; el admin solo en su organización.
     *  - El casillero no puede estar bloqueado.
     *  - El casillero no puede tener una asignación activa.
     *  - El usuario objetivo: rol USUARIO, activo, de la MISMA organización del
     *    casillero, y distinto del autenticado.
     *  - Transacción con bloqueo de fila (lockForUpdate) para evitar carreras.
     *  - Crea la fila en `asignaciones` (estado = true, fecha_asignacion = now).
     *  - Registra un evento ASIGNACION.
     *  - NO toca credenciales_rfid.
     */
    public function asignar(Request $request, $id)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'usuarios_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $casillero = $this->buscarCasilleroEnAmbito($user, $id);
        if (!$casillero) {
            return response()->json(['message' => 'Casillero no encontrado.'], 404);
        }

        if ($casillero->bloqueado) {
            return response()->json([
                'message' => 'No se puede asignar un casillero bloqueado.',
            ], 422);
        }

        $usuarioId = (int) $request->input('usuarios_id');

        // El usuario objetivo debe ser USUARIO activo de la MISMA organización
        // del casillero, y no puede ser el propio autenticado.
        $objetivo = UsuarioCrud::query()
            ->where('id', $usuarioId)
            ->where('estado', true)
            ->where('roles_id', Rol::USUARIO)
            ->where('organizaciones_id', $casillero->organizaciones_id)
            ->first();

        if (!$objetivo || $objetivo->id === $user->id) {
            return response()->json([
                'message' => 'El usuario seleccionado no es válido para este casillero.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($casillero, $objetivo) {
                // Bloqueo de fila del casillero para serializar asignaciones
                // concurrentes sobre el mismo casillero.
                $casilleroBloqueado = Casillero::query()
                    ->whereKey($casillero->id)
                    ->lockForUpdate()
                    ->first();

                // Revalidar la ocupación DENTRO de la transacción (otra solicitud
                // pudo haber asignado el casillero justo antes).
                $yaAsignado = Asignacion::query()
                    ->where('casilleros_id', $casilleroBloqueado->id)
                    ->where('estado', true)
                    ->lockForUpdate()
                    ->exists();

                if ($yaAsignado) {
                    // Abortar con un marcador para traducir a 409 fuera.
                    throw new \RuntimeException('CASILLERO_OCUPADO');
                }

                Asignacion::query()->create([
                    'usuarios_id'      => $objetivo->id,
                    'casilleros_id'    => $casilleroBloqueado->id,
                    'fecha_asignacion' => now(),
                    'fecha_liberacion' => null,
                    'estado'           => true,
                ]);

                EventoCasillero::query()->create([
                    'casilleros_id' => $casilleroBloqueado->id,
                    'tipo_evento'   => 'ASIGNACION',
                    'descripcion'   => 'Asignación al usuario '.$objetivo->id,
                    'fecha_hora'    => now(),
                ]);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'CASILLERO_OCUPADO') {
                return response()->json([
                    'message' => 'El casillero ya tiene una asignación activa.',
                ], 409);
            }
            throw $e;
        }

        $casillero->load('asignacionActiva.usuario');

        return response()->json([
            'message'   => 'Casillero asignado correctamente.',
            'casillero' => $this->serializar($casillero, $this->uidsDe($casillero)),
        ], 201);
    }

    /**
     * Consulta de la asignación activa de un casillero (botón "Ver usuario").
     * - Solo SUPERADMIN/ADMINISTRADOR; el admin solo en su organización.
     * - Devuelve datos del usuario, del casillero, el UID RFID real (o null)
     *   y los estados de ocupación/bloqueo. No expone datos sensibles.
     */
    public function asignacion(Request $request, $id)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $casillero = $this->buscarCasilleroEnAmbito($user, $id);
        if (!$casillero) {
            return response()->json(['message' => 'Casillero no encontrado.'], 404);
        }

        $casillero->load('asignacionActiva.usuario');

        if (!$casillero->asignacionActiva) {
            return response()->json([
                'message' => 'El casillero no tiene una asignación activa.',
            ], 404);
        }

        return response()->json($this->serializar($casillero, $this->uidsDe($casillero)));
    }

    /**
     * Libera (baja lógica) la asignación activa de un casillero.
     * - Solo SUPERADMIN/ADMINISTRADOR; el admin solo en su organización.
     * - Cierra la asignación: estado = false, fecha_liberacion = now().
     * - NO borra la cuenta del usuario, NI el historial, NI la credencial RFID.
     * - NO altera el bloqueo (bloqueado/motivo_bloqueo permanecen).
     * - Si no hay asignación activa, responde 409.
     * - Registra un evento LIBERACION.
     */
    public function liberar(Request $request, $id)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $casillero = $this->buscarCasilleroEnAmbito($user, $id);
        if (!$casillero) {
            return response()->json(['message' => 'Casillero no encontrado.'], 404);
        }

        try {
            DB::transaction(function () use ($casillero) {
                $activa = Asignacion::query()
                    ->where('casilleros_id', $casillero->id)
                    ->where('estado', true)
                    ->lockForUpdate()
                    ->first();

                if (!$activa) {
                    throw new \RuntimeException('SIN_ASIGNACION_ACTIVA');
                }

                // Baja lógica: se conserva el registro histórico.
                $activa->estado = false;
                $activa->fecha_liberacion = now();
                $activa->save();

                EventoCasillero::query()->create([
                    'casilleros_id' => $casillero->id,
                    'tipo_evento'   => 'LIBERACION',
                    'descripcion'   => 'Liberación del usuario '.$activa->usuarios_id,
                    'fecha_hora'    => now(),
                ]);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'SIN_ASIGNACION_ACTIVA') {
                return response()->json([
                    'message' => 'El casillero no tiene una asignación activa.',
                ], 409);
            }
            throw $e;
        }

        // Releer sin asignación activa: la ocupación debe recalcularse a libre,
        // pero el bloqueo (si lo hubiera) se mantiene intacto.
        $casillero->load('asignacionActiva.usuario');

        return response()->json([
            'message'   => 'Casillero liberado correctamente.',
            'casillero' => $this->serializar($casillero, $this->uidsDe($casillero)),
        ]);
    }

    /**
     * Mapa usuarios_id => uid para el usuario asignado de un solo casillero.
     */
    private function uidsDe(Casillero $casillero): array
    {
        $usuarioId = optional($casillero->asignacionActiva)->usuarios_id;
        if (!$usuarioId) {
            return [];
        }

        return CredencialRfid::query()
            ->where('usuarios_id', $usuarioId)
            ->where('estado', true)
            ->get(['usuarios_id', 'uid'])
            ->groupBy('usuarios_id')
            ->map(fn ($grupo) => $grupo->first()->uid)
            ->all();
    }
}
