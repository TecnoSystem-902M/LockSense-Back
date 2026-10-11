<?php

namespace App\Http\Controllers;

use App\Models\Asignacion;
use App\Models\Bloqueo;
use App\Models\Casillero;
use App\Models\CredencialRfid;
use App\Models\EventoCasillero;
use App\Models\Rol;
use App\Models\Usuario;
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

    private function denegarSiNoEsAdministrativo($user)
    {
        if (!$this->esSuperadmin($user) && !$this->esAdministrador($user)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        return null;
    }

    private function buscarCasilleroEnAmbito($user, $id): ?Casillero
    {
        $query = Casillero::query()->whereKey($id);

        if (!$this->esSuperadmin($user)) {
            $query->where('organizaciones_id', $user->organizaciones_id);
        }

        return $query->first();
    }

    /**
     * Recalcula y guarda `casilleros.estado` según las relaciones.
     * Prioridad: Bloqueado > Ocupado > Libre.
     */
    private function sincronizarEstado(Casillero $casillero): void
    {
        if ($casillero->estaBloqueado()) {
            $casillero->estado = 'Bloqueado';
        } elseif ($casillero->estaOcupado()) {
            $casillero->estado = 'Ocupado';
        } else {
            $casillero->estado = 'Libre';
        }
        $casillero->save();
    }

    private function serializar(Casillero $c, array $uidsPorUsuario = []): array
    {
        $asignacion = $c->asignacionActiva;
        $usuario = $asignacion?->usuario;
        $bloqueo = $c->bloqueoActivo;

        $usuarioData = null;
        if ($usuario) {
            $usuarioData = [
                'id'              => $usuario->id,
                'nombre'          => $usuario->nombre,
                'apellido_pa'     => $usuario->apellido_pa,
                'apellido_ma'     => $usuario->apellido_ma,
                'nombre_completo' => trim(implode(' ', array_filter([
                    $usuario->nombre,
                    $usuario->apellido_pa,
                    $usuario->apellido_ma,
                ]))),
                'email'           => $usuario->email,
            ];
        }

        return [
            'id'                => $c->id,
            'numero'            => $c->numero,
            'estado'            => $c->estado,
            'ubicacion'         => $c->ubicacion,
            'organizaciones_id' => $c->organizaciones_id,
            'ocupado'           => $asignacion !== null,
            'bloqueado'         => $bloqueo !== null,
            'motivo_bloqueo'    => $bloqueo?->tipo,
            'asignacion'        => $asignacion ? [
                'id'               => $asignacion->id,
                'fecha_asignacion' => $asignacion->fecha_asignacion,
            ] : null,
            'usuario'           => $usuarioData,
            'uid_rfid'          => $usuario ? ($uidsPorUsuario[$usuario->id] ?? null) : null,
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $query = Casillero::query()
            ->with(['asignacionActiva.usuario', 'bloqueoActivo'])
            ->orderBy('id');

        if (!$this->esSuperadmin($user)) {
            $query->where('organizaciones_id', $user->organizaciones_id);
        }

        $casilleros = $query->get();

        $usuariosIds = $casilleros
            ->map(fn ($c) => optional($c->asignacionActiva)->usuarios_id)
            ->filter()
            ->unique()
            ->values();

        $uidsPorUsuario = CredencialRfid::query()
            ->whereIn('usuarios_id', $usuariosIds)
            ->where('estado', 'A')
            ->get(['usuarios_id', 'codigo'])
            ->groupBy('usuarios_id')
            ->map(fn ($grupo) => $grupo->first()->codigo)
            ->all();

        $data = $casilleros->map(fn (Casillero $c) => $this->serializar($c, $uidsPorUsuario));

        return response()->json($data);
    }

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

        if ($casillero->estaBloqueado()) {
            return response()->json([
                'message' => 'El casillero ya está bloqueado.',
            ], 409);
        }

        $motivo = $request->input('motivo_bloqueo');

        if ($motivo === Casillero::MOTIVO_ADMINISTRATIVO && !$casillero->estaOcupado()) {
            return response()->json([
                'message' => 'No se puede aplicar un bloqueo administrativo a un casillero sin asignación activa.',
            ], 422);
        }

        DB::transaction(function () use ($casillero, $motivo) {
            Bloqueo::query()->create([
                'casilleros_id' => $casillero->id,
                'tipo'          => $motivo,
                'motivo'        => 'Bloqueo '.$motivo,
                'inicio'        => now(),
                'fin'           => null,
                'activo'        => 'A',
            ]);

            EventoCasillero::query()->create([
                'casilleros_id' => $casillero->id,
                'tipo_evento'   => 'BLOQUEO',
                'descripcion'   => 'Bloqueo '.$motivo,
                'fecha_hora'    => now(),
            ]);
        });

        $casillero->load(['asignacionActiva.usuario', 'bloqueoActivo']);
        $this->sincronizarEstado($casillero);   // 👈 actualiza estado

        return response()->json([
            'message'   => 'Casillero bloqueado correctamente.',
            'casillero' => $this->serializar($casillero, $this->uidsDe($casillero)),
        ]);
    }

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

        $bloqueoActivo = $casillero->bloqueoActivo;
        if (!$bloqueoActivo) {
            return response()->json([
                'message' => 'El casillero no está bloqueado.',
            ], 409);
        }

        DB::transaction(function () use ($casillero, $bloqueoActivo) {
            $bloqueoActivo->activo = 'I';
            $bloqueoActivo->fin = now();
            $bloqueoActivo->save();

            EventoCasillero::query()->create([
                'casilleros_id' => $casillero->id,
                'tipo_evento'   => 'DESBLOQUEO',
                'descripcion'   => 'Desbloqueo',
                'fecha_hora'    => now(),
            ]);
        });

        $casillero->load(['asignacionActiva.usuario', 'bloqueoActivo']);
        $this->sincronizarEstado($casillero);   // 👈 actualiza estado

        return response()->json([
            'message'   => 'Casillero desbloqueado correctamente.',
            'casillero' => $this->serializar($casillero, $this->uidsDe($casillero)),
        ]);
    }

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

        $candidatos = Usuario::query()
            ->where('estado', 'A')
            ->where('roles_id', Rol::USUARIO)
            ->where('organizaciones_id', $casillero->organizaciones_id)
            ->where('id', '!=', $user->id)
            ->orderBy('id')
            ->get(['id', 'nombre', 'apellido_pa', 'apellido_ma', 'email']);

        return response()->json($candidatos);
    }

    public function asignar(Request $request, $id)
    {
        $user = $request->user();

        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'usuarios_id' => ['required', 'integer'],
            'uid_rfid'    => ['nullable', 'string', 'max:50'],
        ]);
        

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $casillero = $this->buscarCasilleroEnAmbito($user, $id);
        if (!$casillero) {
            return response()->json(['message' => 'Casillero no encontrado.'], 404);
        }

        if ($casillero->estaBloqueado()) {
            return response()->json([
                'message' => 'No se puede asignar un casillero bloqueado.',
            ], 422);
        }

        $usuarioId = (int) $request->input('usuarios_id');

        $objetivo = Usuario::query()
            ->where('id', $usuarioId)
            ->where('estado', 'A')
            ->where('roles_id', Rol::USUARIO)
            ->where('organizaciones_id', $casillero->organizaciones_id)
            ->first();

        if (!$objetivo || $objetivo->id === $user->id) {
            return response()->json([
                'message' => 'El usuario seleccionado no es válido para este casillero.',
            ], 422);
        }

        $uid = null;
        if ($request->filled('uid_rfid')) {
            $uid = strtoupper(str_replace([':', ' ', '-'], '', $request->uid_rfid));

            $existente = CredencialRfid::where('codigo', $uid)
                ->where('usuarios_id', '!=', $objetivo->id)
                ->first();

            if ($existente) {
                return response()->json([
                    'message' => 'Esa tarjeta RFID ya está registrada en otro usuario.',
                ], 409);
            }
        }

        try {
            DB::transaction(function () use ($casillero, $objetivo, $uid) {
                $casilleroBloqueado = Casillero::query()
                    ->whereKey($casillero->id)
                    ->lockForUpdate()
                    ->first();

                $yaAsignado = Asignacion::query()
                    ->where('casilleros_id', $casilleroBloqueado->id)
                    ->where('estado', 'A')
                    ->lockForUpdate()
                    ->exists();

                if ($yaAsignado) {
                    throw new \RuntimeException('CASILLERO_OCUPADO');
                }

                Asignacion::query()->create([
                    'usuarios_id'      => $objetivo->id,
                    'casilleros_id'    => $casilleroBloqueado->id,
                    'fecha_asignacion' => now(),
                    'fecha_liberacion' => null,
                    'estado'           => 'A',
                ]);

                if ($uid) {
                    CredencialRfid::updateOrCreate(
                        ['usuarios_id' => $objetivo->id],
                        [
                            'codigo'         => $uid,
                            'tipo'           => 'MIFARE',
                            'estado'         => 'A',
                            'fecha_registro' => now(),
                        ]
                    );
                }

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

        $casillero->load(['asignacionActiva.usuario', 'bloqueoActivo']);
        $this->sincronizarEstado($casillero);   // 👈 actualiza estado

        return response()->json([
            'message'   => 'Casillero asignado correctamente.',
            'casillero' => $this->serializar($casillero, $this->uidsDe($casillero)),
        ], 201);
    }

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

        $casillero->load(['asignacionActiva.usuario', 'bloqueoActivo']);

        if (!$casillero->asignacionActiva) {
            return response()->json([
                'message' => 'El casillero no tiene una asignación activa.',
            ], 404);
        }

        return response()->json($this->serializar($casillero, $this->uidsDe($casillero)));
    }

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
                    ->where('estado', 'A')
                    ->lockForUpdate()
                    ->first();

                if (!$activa) {
                    throw new \RuntimeException('SIN_ASIGNACION_ACTIVA');
                }

                $activa->estado = 'I';
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

        $casillero->load(['asignacionActiva.usuario', 'bloqueoActivo']);
        $this->sincronizarEstado($casillero);   // 👈 actualiza estado

        return response()->json([
            'message'   => 'Casillero liberado correctamente.',
            'casillero' => $this->serializar($casillero, $this->uidsDe($casillero)),
        ]);
    }

    private function uidsDe(Casillero $casillero): array
    {
        $usuarioId = optional($casillero->asignacionActiva)->usuarios_id;
        if (!$usuarioId) {
            return [];
        }

        return CredencialRfid::query()
            ->where('usuarios_id', $usuarioId)
            ->where('estado', 'A')
            ->get(['usuarios_id', 'codigo'])
            ->groupBy('usuarios_id')
            ->map(fn ($grupo) => $grupo->first()->codigo)
            ->all();
    }
}