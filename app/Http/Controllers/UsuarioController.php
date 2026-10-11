<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Organizacion;
use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    /**
     * Campos que se exponen al frontend (nunca `password`).
     */
    private array $camposPublicos = [
        'id',
        'nombre',
        'apellido_pa',
        'apellido_ma',
        'identificador',
        'email',
        'telefono',
        'organizaciones_id',
        'roles_id',
        'estado',
    ];

    // ----------------------------------------------------------------
    // Helpers de autorización
    // ----------------------------------------------------------------

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

    private function denegarObjetivoFueraDeAmbito($user, Usuario $objetivo)
    {
        if ($this->esSuperadmin($user)) {
            return null;
        }

        if ((int) $objetivo->organizaciones_id !== (int) $user->organizaciones_id) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ((int) $objetivo->roles_id !== Rol::USUARIO) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return null;
    }

    /**
     * Listado de usuarios activos (estado = 'A')
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $query = Usuario::query()->where('estado', 'A');

        if (!$this->esSuperadmin($user)) {
            $query->where('organizaciones_id', $user->organizaciones_id)
                  ->where('roles_id', Rol::USUARIO);
        }

        $usuarios = $query->orderBy('id')->get($this->camposPublicos);

        return response()->json($usuarios);
    }

    /**
     * Alta de usuario.
     * El identificador es un string libre (matrícula, número de empleado, etc.)
     * único DENTRO de la organización.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        // 1. Resolver organización y rol ANTES de validar (para la regla unique)
        if ($this->esSuperadmin($user)) {
            $organizacionId = $request->filled('organizaciones_id')
                ? (int) $request->organizaciones_id
                : (int) $user->organizaciones_id;
            $rolId = $request->filled('roles_id')
                ? (int) $request->roles_id
                : Rol::USUARIO;
        } else {
            $organizacionId = (int) $user->organizaciones_id;
            $rolId          = Rol::USUARIO;
        }

        // 2. Reglas de validación
        $reglas = [
            'nombre'      => 'required|string|max:100',
            'apellido_pa' => 'required|string|max:100',
            'apellido_ma' => 'nullable|string|max:100',
            'identificador' => [
                'required', 'string', 'max:100',
                Rule::unique('usuarios', 'identificador')
                    ->where('organizaciones_id', $organizacionId),
            ],
            'email'    => 'nullable|email|max:150|unique:usuarios,email',
            'password' => 'required|string|min:8',
            'telefono' => 'nullable|string|max:20',
            'estado'   => 'nullable|in:A,P',
        ];

        if ($this->esSuperadmin($user)) {
            $reglas['organizaciones_id'] = 'nullable|integer|exists:organizaciones,id';
            $reglas['roles_id']          = 'nullable|integer|exists:roles,id';
        }

        $validator = Validator::make($request->all(), $reglas);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 3. Crear el usuario
        $usuario = new Usuario();
        $usuario->nombre            = $request->nombre;
        $usuario->apellido_pa       = $request->apellido_pa;
        $usuario->apellido_ma       = $request->apellido_ma;
        $usuario->identificador     = $request->identificador;
        $usuario->email             = $request->filled('email') ? $request->email : null;
        $usuario->password          = Hash::make($request->password);
        $usuario->telefono          = $request->filled('telefono') ? $request->telefono : null;
        $usuario->organizaciones_id = $organizacionId;
        $usuario->roles_id          = $rolId;
        $usuario->estado            = $request->filled('estado') ? $request->estado : 'A';
        $usuario->save();

        return response()->json(
            Usuario::query()->find($usuario->id, $this->camposPublicos),
            201
        );
    }

    /**
     * Edición de usuario.
     * El identificador sigue siendo único por organización (ignorando el propio usuario).
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $usuario = Usuario::query()->find($id);

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($deny = $this->denegarObjetivoFueraDeAmbito($user, $usuario)) {
            return $deny;
        }

        $reglas = [
            'nombre'      => 'required|string|max:100',
            'apellido_pa' => 'required|string|max:100',
            'apellido_ma' => 'nullable|string|max:100',
            'identificador' => [
                'required', 'string', 'max:100',
                Rule::unique('usuarios', 'identificador')
                    ->where('organizaciones_id', $usuario->organizaciones_id)
                    ->ignore($usuario->id),
            ],
            'email' => [
                'nullable', 'email', 'max:150',
                Rule::unique('usuarios', 'email')->ignore($usuario->id),
            ],
            'password' => 'nullable|string|min:8',
            'telefono' => 'nullable|string|max:20',
            'estado'   => 'nullable|in:A,P',
        ];

        if ($this->esSuperadmin($user)) {
            $reglas['organizaciones_id'] = 'nullable|integer|exists:organizaciones,id';
            $reglas['roles_id']          = 'nullable|integer|exists:roles,id';
        }

        $validator = Validator::make($request->all(), $reglas);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $usuario->nombre        = $request->nombre;
        $usuario->apellido_pa   = $request->apellido_pa;
        $usuario->apellido_ma   = $request->apellido_ma;
        $usuario->identificador = $request->identificador;

        if ($request->has('email')) {
            $usuario->email = $request->filled('email') ? $request->email : null;
        }

        if ($request->has('telefono')) {
            $usuario->telefono = $request->filled('telefono') ? $request->telefono : null;
        }

        if ($this->esSuperadmin($user)) {
            if ($request->filled('organizaciones_id')) {
                $usuario->organizaciones_id = (int) $request->organizaciones_id;
            }
            if ($request->filled('roles_id')) {
                $usuario->roles_id = (int) $request->roles_id;
            }
        }

        if ($request->filled('estado')) {
            $usuario->estado = $request->estado;
        }

        if ($request->filled('password')) {
            $usuario->password = Hash::make($request->password);
        }

        $usuario->save();

        return response()->json(
            Usuario::query()->find($usuario->id, $this->camposPublicos)
        );
    }

    /**
     * Baja lógica: estado = 'P'
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $usuario = Usuario::query()->find($id);

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($deny = $this->denegarObjetivoFueraDeAmbito($user, $usuario)) {
            return $deny;
        }

        $usuario->estado = 'P';
        $usuario->save();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    /**
     * Catálogo de roles.
     */
    public function roles(Request $request)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $query = Rol::query()->orderBy('id');

        if (!$this->esSuperadmin($user)) {
            $query->where('id', Rol::USUARIO);
        }

        return response()->json($query->get(['id', 'nombre', 'descripcion']));
    }

    /**
     * Catálogo de organizaciones.
     */
    public function organizaciones(Request $request)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $query = Organizacion::query()->orderBy('nombre');

        if (!$this->esSuperadmin($user)) {
            $query->where('id', $user->organizaciones_id);
        }

        return response()->json($query->get(['id', 'nombre']));
    }
}