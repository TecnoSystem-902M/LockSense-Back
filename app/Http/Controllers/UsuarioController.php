<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Organizacion;
use App\Models\Rol;
use App\Services\CodigoOrganizacion;
use Illuminate\Support\Collection;
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

    // ----------------------------------------------------------------
    // Helpers de matrícula / identificador de acceso
    // ----------------------------------------------------------------

    /**
     * Arma el identificador de acceso (CODIGO-MATRICULA) y valida que sea único.
     * Devuelve [identificador, error|null].
     */
    private function resolverIdentificador(string $matricula, int $organizacionId, ?int $ignorarUsuarioId = null): array
    {
        $organizacion = Organizacion::query()->find($organizacionId);
        if (!$organizacion) {
            return [null, 'La organización no existe.'];
        }

        if (CodigoOrganizacion::normalizarMatricula($matricula) === '') {
            return [null, 'La matrícula solo puede tener letras, números, guiones, puntos o guion bajo.'];
        }

        $codigo = CodigoOrganizacion::asegurar($organizacion);
        $identificador = CodigoOrganizacion::componer($codigo, $matricula);

        $enUso = Usuario::query()
            ->whereRaw('UPPER(identificador) = ?', [strtoupper($identificador)])
            ->when($ignorarUsuarioId, fn ($q) => $q->where('id', '!=', $ignorarUsuarioId))
            ->exists();

        if ($enUso) {
            return [null, 'Ya existe un usuario con esa matrícula en la organización.'];
        }

        return [$identificador, null];
    }

    /** Agrega `matricula` (identificador sin prefijo) a cada usuario. */
    private function conMatricula(Collection $usuarios): Collection
    {
        $codigos = Organizacion::query()
            ->whereIn('id', $usuarios->pluck('organizaciones_id')->unique())
            ->pluck('codigo', 'id');

        return $usuarios->map(function ($u) use ($codigos) {
            $data = $u->toArray();
            $data['matricula'] = CodigoOrganizacion::matricula(
                $codigos[$u->organizaciones_id] ?? null,
                $u->identificador
            );
            return $data;
        });
    }

    private function usuarioPublico(int $id): array
    {
        $usuario = Usuario::query()->whereKey($id)->get($this->camposPublicos);

        return $this->conMatricula($usuario)->first();
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

        return response()->json($this->conMatricula($usuarios)->values());
    }

    /**
     * Alta de usuario.
     * El campo `identificador` que llega es la MATRÍCULA (ej. A001).
     * Se guarda con el prefijo de la organización (ej. UTN-A001), que es
     * con lo que el usuario inicia sesión.
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
            'identificador' => 'required|string|max:90',
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

        // 3. Matrícula -> identificador de acceso con prefijo
        [$identificador, $error] = $this->resolverIdentificador($request->identificador, $organizacionId);
        if ($error) {
            return response()->json(['errors' => ['identificador' => [$error]]], 422);
        }

        // 4. Crear el usuario
        $usuario = new Usuario();
        $usuario->nombre            = $request->nombre;
        $usuario->apellido_pa       = $request->apellido_pa;
        $usuario->apellido_ma       = $request->apellido_ma;
        $usuario->identificador     = $identificador;
        $usuario->email             = $request->filled('email') ? $request->email : null;
        $usuario->password          = Hash::make($request->password);
        $usuario->telefono          = $request->filled('telefono') ? $request->telefono : null;
        $usuario->organizaciones_id = $organizacionId;
        $usuario->roles_id          = $rolId;
        $usuario->estado            = $request->filled('estado') ? $request->estado : 'A';
        $usuario->save();

        return response()->json($this->usuarioPublico($usuario->id), 201);
    }

    /**
     * Edición de usuario.
     * `identificador` llega como MATRÍCULA; se vuelve a armar con el prefijo.
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
            'identificador' => 'required|string|max:90',
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

        // La organización final (el superadmin puede moverlo de organización)
        $organizacionDestino = ($this->esSuperadmin($user) && $request->filled('organizaciones_id'))
            ? (int) $request->organizaciones_id
            : (int) $usuario->organizaciones_id;

        [$identificador, $error] = $this->resolverIdentificador(
            $request->identificador,
            $organizacionDestino,
            $usuario->id
        );
        if ($error) {
            return response()->json(['errors' => ['identificador' => [$error]]], 422);
        }

        $usuario->nombre        = $request->nombre;
        $usuario->apellido_pa   = $request->apellido_pa;
        $usuario->apellido_ma   = $request->apellido_ma;
        $usuario->identificador = $identificador;

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

        return response()->json($this->usuarioPublico($usuario->id));
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