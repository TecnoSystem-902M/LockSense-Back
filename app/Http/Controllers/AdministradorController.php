<?php

namespace App\Http\Controllers;

use App\Models\Organizacion;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * LockSense - CRUD de administradores (solo SUPERADMIN).
 *
 *   GET    /api/superadmin/administradores                listar (+ organizaciones para el select)
 *   POST   /api/superadmin/administradores                crear
 *   PUT    /api/superadmin/administradores/{id}           modificar
 *   PATCH  /api/superadmin/administradores/{id}/estado    dar de baja / reactivar
 *
 * Reglas:
 *   - Una organización solo puede tener UN administrador activo.
 *   - La contraseña se guarda con bcrypt (Hash::make).
 *   - No se borran administradores: se dan de baja (estado = 'I') y se
 *     cierran sus sesiones.
 */
class AdministradorController extends Controller
{
    private const ROL_SUPERADMIN    = 1;
    private const ROL_ADMINISTRADOR = 2;

    private const TIPO_SISTEMA = 'Sistema';

    public function index(Request $request): JsonResponse
    {
        $this->soloSuperadmin($request);

        $administradores = Usuario::query()
            ->where('roles_id', self::ROL_ADMINISTRADOR)
            ->with('organizacion')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Usuario $admin) => $this->formatear($admin));

        // Para el select: organizaciones activas, indicando si ya tienen admin activo.
        $conAdmin = Usuario::query()
            ->where('roles_id', self::ROL_ADMINISTRADOR)
            ->where('estado', 'A')
            ->pluck('id', 'organizaciones_id');   // [organizaciones_id => admin_id]

        $organizaciones = $this->organizacionesReales()
            ->where('estado', 'A')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Organizacion $org) => [
                'id'       => $org->id,
                'nombre'   => $org->nombre,
                'admin_id' => $conAdmin->get($org->id),   // null = disponible
            ]);

        return response()->json([
            'administradores' => $administradores,
            'organizaciones'  => $organizaciones,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->soloSuperadmin($request);

        $datos = $this->validar($request);

        $admin = Usuario::create([
            'nombre'            => $datos['nombre'],
            'apellido_pa'       => $datos['apellido_pa'],
            'apellido_ma'       => $datos['apellido_ma'] ?? null,
            'email'             => $datos['email'],
            'password'          => Hash::make($datos['password']),   // bcrypt
            // La columna es obligatoria en la base; los admins entran con su
            // correo, así que se llena con un UUID interno (no se muestra).
            'identificador'     => Str::uuid()->toString(),
            'estado'            => 'A',   // lo crea el superadmin: ya está verificado
            'organizaciones_id' => $datos['organizaciones_id'],
            'roles_id'          => self::ROL_ADMINISTRADOR,
        ]);

        return response()->json([
            'message'       => 'Administrador registrado correctamente.',
            'administrador' => $this->formatear($admin->load('organizacion')),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->soloSuperadmin($request);

        $admin = $this->buscar($id);
        $datos = $this->validar($request, $admin);

        $cambios = [
            'nombre'            => $datos['nombre'],
            'apellido_pa'       => $datos['apellido_pa'],
            'apellido_ma'       => $datos['apellido_ma'] ?? null,
            'email'             => $datos['email'],
            'organizaciones_id' => $datos['organizaciones_id'],
        ];

        // La contraseña solo cambia si se escribió una nueva.
        if (! empty($datos['password'])) {
            $cambios['password'] = Hash::make($datos['password']);
        }

        DB::transaction(function () use ($admin, $cambios) {
            $admin->update($cambios);

            // Si cambió su contraseña, se cierran sus sesiones abiertas.
            if (isset($cambios['password'])) {
                $admin->tokens()->delete();
            }
        });

        return response()->json([
            'message'       => 'Administrador actualizado correctamente.',
            'administrador' => $this->formatear($admin->fresh('organizacion')),
        ]);
    }

    public function cambiarEstado(Request $request, int $id): JsonResponse
    {
        $this->soloSuperadmin($request);

        $datos = $request->validate([
            'estado' => ['required', Rule::in(['A', 'I'])],
        ]);

        $admin = $this->buscar($id);

        // Al reactivar, su organización no debe tener ya otro admin activo.
        if ($datos['estado'] === 'A' && $this->organizacionOcupada($admin->organizaciones_id, $admin->id)) {
            return response()->json([
                'message' => 'Su organización ya tiene otro administrador activo. Cámbiale la organización antes de reactivarlo.',
            ], 422);
        }

        DB::transaction(function () use ($admin, $datos) {
            $admin->update(['estado' => $datos['estado']]);

            // Al darlo de baja, se cierran todas sus sesiones.
            if ($datos['estado'] === 'I') {
                $admin->tokens()->delete();
            }
        });

        return response()->json([
            'message'       => $datos['estado'] === 'A'
                ? 'Administrador reactivado.'
                : 'Administrador dado de baja.',
            'administrador' => $this->formatear($admin->fresh('organizacion')),
        ]);
    }

    // ==================================================
    // Auxiliares
    // ==================================================

    private function soloSuperadmin(Request $request): void
    {
        abort_unless((int) $request->user()->roles_id === self::ROL_SUPERADMIN, 403,
            'Solo el superadministrador puede gestionar administradores.');
    }

    private function buscar(int $id): Usuario
    {
        return Usuario::query()
            ->where('roles_id', self::ROL_ADMINISTRADOR)
            ->findOrFail($id);
    }

    private function organizacionesReales()
    {
        return Organizacion::query()
            ->where(fn ($q) => $q->whereNull('tipo')->orWhere('tipo', '!=', self::TIPO_SISTEMA));
    }

    /** ¿La organización ya tiene un admin activo (distinto de $ignorarId)? */
    private function organizacionOcupada(int $organizacionId, ?int $ignorarId = null): bool
    {
        return Usuario::query()
            ->where('roles_id', self::ROL_ADMINISTRADOR)
            ->where('estado', 'A')
            ->where('organizaciones_id', $organizacionId)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();
    }

    private function validar(Request $request, ?Usuario $admin = null): array
    {
        $esNuevo = $admin === null;

        // Limpieza antes de validar.
        $request->merge([
            'nombre'      => trim((string) $request->input('nombre')),
            'apellido_pa' => trim((string) $request->input('apellido_pa')),
            'apellido_ma' => trim((string) $request->input('apellido_ma')) ?: null,
            'email'       => strtolower(trim((string) $request->input('email'))),
        ]);

        $soloLetras = 'regex:/^[\pL\s\'.-]+$/u';   // letras (con acentos), espacios, ' . -

        $validator = Validator::make($request->all(), [
            'nombre'            => ['required', 'string', 'min:2', 'max:100', $soloLetras],
            'apellido_pa'       => ['required', 'string', 'min:2', 'max:100', $soloLetras],
            'apellido_ma'       => ['nullable', 'string', 'max:100', $soloLetras],
            'email'             => [
                'required', 'email', 'max:150',
                Rule::unique('usuarios', 'email')->ignore($admin?->id),
            ],
            'password'          => $esNuevo
                ? ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/\d/', 'confirmed']
                : ['nullable', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/\d/', 'confirmed'],
            'organizaciones_id' => ['required', 'integer', 'exists:organizaciones,id'],
        ], [
            'nombre.required'            => 'El nombre es obligatorio.',
            'nombre.min'                 => 'El nombre debe tener al menos 2 letras.',
            'nombre.max'                 => 'El nombre no puede pasar de 100 caracteres.',
            'nombre.regex'               => 'El nombre solo puede tener letras.',
            'apellido_pa.required'       => 'El apellido paterno es obligatorio.',
            'apellido_pa.min'            => 'El apellido paterno debe tener al menos 2 letras.',
            'apellido_pa.max'            => 'El apellido paterno no puede pasar de 100 caracteres.',
            'apellido_pa.regex'          => 'El apellido paterno solo puede tener letras.',
            'apellido_ma.max'            => 'El apellido materno no puede pasar de 100 caracteres.',
            'apellido_ma.regex'          => 'El apellido materno solo puede tener letras.',
            'email.required'             => 'El correo es obligatorio.',
            'email.email'                => 'Escribe un correo válido.',
            'email.max'                  => 'El correo no puede pasar de 150 caracteres.',
            'email.unique'               => 'Ese correo ya está registrado.',
            'password.required'          => 'La contraseña es obligatoria.',
            'password.min'               => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max'               => 'La contraseña no puede pasar de 72 caracteres.',
            'password.regex'             => 'La contraseña debe tener al menos una letra y un número.',
            'password.confirmed'         => 'Las contraseñas no coinciden.',
            'organizaciones_id.required' => 'Selecciona la organización.',
            'organizaciones_id.exists'   => 'La organización seleccionada no existe.',
        ]);

        // Reglas de la organización: real, activa y sin otro admin activo.
        $validator->after(function ($validator) use ($request, $admin) {
            if ($validator->errors()->has('organizaciones_id')) {
                return;
            }

            $org = Organizacion::find($request->input('organizaciones_id'));

            if (! $org || $org->tipo === self::TIPO_SISTEMA) {
                $validator->errors()->add('organizaciones_id', 'La organización seleccionada no es válida.');
                return;
            }

            // Al modificar, se permite dejarlo en su misma organización.
            $esSuOrganizacion = $admin && (int) $admin->organizaciones_id === (int) $org->id;

            if ($org->estado !== 'A' && ! $esSuOrganizacion) {
                $validator->errors()->add('organizaciones_id', 'La organización está dada de baja.');
                return;
            }

            if ($this->organizacionOcupada($org->id, $admin?->id)) {
                $validator->errors()->add('organizaciones_id', 'Esa organización ya tiene un administrador activo.');
            }
        });

        return $validator->validate();
    }

    private function formatear(Usuario $admin): array
    {
        return [
            'id'              => $admin->id,
            'nombre'          => $admin->nombre,
            'apellido_pa'     => $admin->apellido_pa,
            'apellido_ma'     => $admin->apellido_ma,
            'nombre_completo' => trim("{$admin->nombre} {$admin->apellido_pa} {$admin->apellido_ma}"),
            'email'           => $admin->email,
            'estado'          => $admin->estado,
            'organizacion'    => $admin->organizacion ? [
                'id'     => $admin->organizacion->id,
                'nombre' => $admin->organizacion->nombre,
            ] : null,
        ];
    }
}