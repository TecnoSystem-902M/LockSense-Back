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
    // Validación (mismas reglas que el registro público)
    // ----------------------------------------------------------------

    // Solo letras (con acentos y ñ) y espacios.
    private const REGEX_NOMBRE = '/^[\pL\s]+$/u';

    // Al menos una letra y un número.
    private const REGEX_PASSWORD = '/^(?=.*\pL)(?=.*\d).+$/u';

    private function mensajesValidacion(): array
    {
        return [
            'nombre.required'      => 'El nombre es obligatorio.',
            'nombre.regex'         => 'El nombre solo puede tener letras y espacios.',
            'nombre.min'           => 'El nombre debe tener al menos 2 caracteres.',
            'nombre.max'           => 'El nombre admite máximo 100 caracteres.',
            'apellido_pa.required' => 'El apellido paterno es obligatorio.',
            'apellido_pa.regex'    => 'El apellido paterno solo puede tener letras y espacios.',
            'apellido_pa.min'      => 'El apellido paterno debe tener al menos 2 caracteres.',
            'apellido_pa.max'      => 'El apellido paterno admite máximo 100 caracteres.',
            'apellido_ma.required' => 'El apellido materno es obligatorio.',
            'apellido_ma.regex'    => 'El apellido materno solo puede tener letras y espacios.',
            'apellido_ma.min'      => 'El apellido materno debe tener al menos 2 caracteres.',
            'apellido_ma.max'      => 'El apellido materno admite máximo 100 caracteres.',
            'identificador.required' => 'La matrícula es obligatoria.',
            'identificador.min'    => 'La matrícula debe tener al menos 3 caracteres.',
            'identificador.max'    => 'La matrícula admite máximo 90 caracteres.',
            'email.email'          => 'El correo no es válido.',
            'email.unique'         => 'Ese correo ya está registrado.',
            'password.required'    => 'La contraseña es obligatoria.',
            'password.min'         => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max'         => 'La contraseña admite máximo 72 caracteres.',
            'password.regex'       => 'La contraseña debe tener al menos una letra y un número.',
            'telefono.required'    => 'El teléfono es obligatorio.',
            'telefono.digits'      => 'El teléfono debe tener 10 dígitos.',
        ];
    }

    /**
     * Limpia los datos antes de validar:
     * - quita espacios al inicio y final de los textos
     * - teléfono: solo dígitos; si viene con 52 adelante (12 dígitos) se quita
     */
    private function limpiarEntrada(Request $request): void
    {
        $limpio = [];

        foreach (['nombre', 'apellido_pa', 'apellido_ma', 'identificador', 'email'] as $campo) {
            if ($request->has($campo) && is_string($request->input($campo))) {
                $limpio[$campo] = trim($request->input($campo));
            }
        }

        if ($request->has('telefono')) {
            $limpio['telefono'] = $this->telefonoNacional((string) $request->input('telefono'));
        }

        $request->merge($limpio);
    }

    /** "+52 55 1234 5678" -> "5512345678" */
    private function telefonoNacional(string $telefono): string
    {
        $digitos = preg_replace('/\D/', '', $telefono);

        if (strlen($digitos) === 12 && str_starts_with($digitos, '52')) {
            $digitos = substr($digitos, 2);
        }

        return $digitos;
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
        $this->limpiarEntrada($request);

        $reglas = [
            'nombre'        => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::REGEX_NOMBRE],
            'apellido_pa'   => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::REGEX_NOMBRE],
            'apellido_ma'   => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::REGEX_NOMBRE],
            'identificador' => ['required', 'string', 'min:3', 'max:90'],
            'email'         => ['nullable', 'email', 'max:150', 'unique:usuarios,email'],
            'password'      => ['required', 'string', 'min:8', 'max:72', 'regex:' . self::REGEX_PASSWORD],
            'telefono'      => ['required', 'digits:10'],
            'estado'        => ['nullable', 'in:A,P'],
        ];

        if ($this->esSuperadmin($user)) {
            $reglas['organizaciones_id'] = 'nullable|integer|exists:organizaciones,id';
            $reglas['roles_id']          = 'nullable|integer|exists:roles,id';
        }

        $validator = Validator::make($request->all(), $reglas, $this->mensajesValidacion());

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
        $usuario->telefono          = '+52' . $request->telefono;
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

        $this->limpiarEntrada($request);

        $reglas = [
            'nombre'        => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::REGEX_NOMBRE],
            'apellido_pa'   => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::REGEX_NOMBRE],
            'apellido_ma'   => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::REGEX_NOMBRE],
            'identificador' => ['required', 'string', 'min:3', 'max:90'],
            'email' => [
                'nullable', 'email', 'max:150',
                Rule::unique('usuarios', 'email')->ignore($usuario->id),
            ],
            // Al editar, la contraseña es opcional (vacía = se conserva)
            'password'      => ['nullable', 'string', 'min:8', 'max:72', 'regex:' . self::REGEX_PASSWORD],
            'telefono'      => ['required', 'digits:10'],
            'estado'        => ['nullable', 'in:A,P'],
        ];

        if ($this->esSuperadmin($user)) {
            $reglas['organizaciones_id'] = 'nullable|integer|exists:organizaciones,id';
            $reglas['roles_id']          = 'nullable|integer|exists:roles,id';
        }

        $validator = Validator::make($request->all(), $reglas, $this->mensajesValidacion());

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

        $usuario->telefono = '+52' . $request->telefono;

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