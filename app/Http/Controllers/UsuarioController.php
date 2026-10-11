<?php

namespace App\Http\Controllers;

use App\Models\UsuarioCrud;
use App\Models\Organizacion;
use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    /**
     * Campos que se exponen al frontend (nunca `contrasena`).
     */
    private array $camposPublicos = [
        'id',
        'nombre',
        'apellido_paterno',
        'apellido_materno',
        'identificador',
        'correo',
        'telefono',
        'organizaciones_id',
        'roles_id',
        'estado',
    ];

    // ----------------------------------------------------------------
    // Helpers de autorización basados en la identidad autenticada.
    // No se confía en el rol/organización enviados por el cliente.
    // ----------------------------------------------------------------

    private function esSuperadmin($user): bool
    {
        return $user && (int) $user->roles_id === Rol::SUPERADMIN;
    }

    private function esAdministrador($user): bool
    {
        return $user && (int) $user->roles_id === Rol::ADMINISTRADOR;
    }

    /**
     * Solo SUPERADMIN o ADMINISTRADOR pueden operar el módulo de usuarios.
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
     * Verifica que un ADMINISTRADOR pueda operar sobre el usuario objetivo.
     * Orden de comprobaciones (para no filtrar información):
     *  1. Si el objetivo es de otra organización -> 404 (no se revela existencia).
     *  2. Si el objetivo es una cuenta privilegiada (ADMINISTRADOR/SUPERADMIN)
     *     dentro de la misma organización -> 403 (operación prohibida).
     * El SUPERADMIN no tiene estas restricciones.
     *
     * Devuelve una respuesta de error o null si la operación está permitida.
     */
    private function denegarObjetivoFueraDeAmbito($user, UsuarioCrud $objetivo)
    {
        if ($this->esSuperadmin($user)) {
            return null;
        }

        // 1. Aislamiento por organización (404 para no revelar existencia).
        if ((int) $objetivo->organizaciones_id !== (int) $user->organizaciones_id) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        // 2. No puede operar sobre cuentas privilegiadas de su misma organización.
        if ((int) $objetivo->roles_id !== Rol::USUARIO) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return null;
    }

    /**
     * Listado de usuarios activos (estado = true).
     * - SUPERADMIN: ve todas las organizaciones y todos los roles.
     * - ADMINISTRADOR: solo cuentas USUARIO de su propia organización.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $query = UsuarioCrud::query()->where('estado', true);

        if (!$this->esSuperadmin($user)) {
            // Administrador: su organización y solo cuentas USUARIO
            // (no gestiona ni ve cuentas privilegiadas ADMINISTRADOR/SUPERADMIN).
            $query->where('organizaciones_id', $user->organizaciones_id)
                  ->where('roles_id', Rol::USUARIO);
        }

        $usuarios = $query->orderBy('id')->get($this->camposPublicos);

        return response()->json($usuarios);
    }

    /**
     * Alta de usuario.
     * - El rol y la organización se asignan EN EL SERVIDOR, no desde el cliente.
     * - ADMINISTRADOR: siempre crea en su propia organización con rol USUARIO.
     * - SUPERADMIN: puede indicar organización y rol permitidos.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $reglas = [
            'nombre'           => 'required|string|max:100',
            'apellido_paterno' => 'required|string|max:100',
            'apellido_materno' => 'nullable|string|max:100',
            'identificador'    => 'required|uuid|max:100|unique:usuarios,identificador',
            // El correo es OPCIONAL para los usuarios de organización. Si se
            // envía, debe ser válido y único; si se omite se guarda como NULL.
            'correo'           => 'nullable|email|max:150|unique:usuarios,correo',
            'contrasena'       => 'required|string|min:8',
            'telefono'         => 'nullable|string|max:20',
            'estado'           => 'boolean',
        ];

        // El superadmin PUEDE (opcionalmente) especificar organización y rol.
        // Si no los envía (p. ej. desde el formulario de organización), se usa
        // un valor por defecto seguro resuelto en el servidor.
        if ($this->esSuperadmin($user)) {
            $reglas['organizaciones_id'] = 'nullable|integer|exists:organizaciones,id';
            $reglas['roles_id']          = 'nullable|integer|exists:roles,id';
        }

        $validator = Validator::make($request->all(), $reglas);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Resolver rol y organización SIEMPRE desde el servidor. Nunca se
        // confía en el frontend para un ADMINISTRADOR; el SUPERADMIN puede
        // indicarlos de forma opcional (con fallback a su propio ámbito).
        if ($this->esSuperadmin($user)) {
            $organizacionId = $request->filled('organizaciones_id')
                ? (int) $request->organizaciones_id
                : (int) $user->organizaciones_id;
            $rolId = $request->filled('roles_id')
                ? (int) $request->roles_id
                : Rol::USUARIO;
        } else {
            // Administrador: su propia organización y rol USUARIO forzado.
            $organizacionId = (int) $user->organizaciones_id;
            $rolId          = Rol::USUARIO;
        }

        $usuario = new UsuarioCrud();
        $usuario->nombre            = $request->nombre;
        $usuario->apellido_paterno  = $request->apellido_paterno;
        $usuario->apellido_materno  = $request->apellido_materno;
        $usuario->identificador     = $request->identificador;
        // Correo opcional: vacío o ausente se persiste como NULL.
        $usuario->correo            = $request->filled('correo') ? $request->correo : null;
        $usuario->contrasena        = Hash::make($request->contrasena);
        $usuario->telefono          = $request->telefono;
        $usuario->organizaciones_id = $organizacionId;
        $usuario->roles_id          = $rolId;
        $usuario->estado            = $request->has('estado') ? (bool) $request->estado : true;
        $usuario->save();

        return response()->json(
            UsuarioCrud::query()->find($usuario->id, $this->camposPublicos),
            201
        );
    }

    /**
     * Edición de usuario.
     * - ADMINISTRADOR: solo cuentas USUARIO de su organización; no cambia rol ni organización.
     * - SUPERADMIN: puede cambiar rol y organización, sobre cualquier usuario.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $usuario = UsuarioCrud::query()->find($id);

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        // Aislamiento por organización + protección de cuentas privilegiadas.
        if ($deny = $this->denegarObjetivoFueraDeAmbito($user, $usuario)) {
            return $deny;
        }

        $reglas = [
            'nombre'           => 'required|string|max:100',
            'apellido_paterno' => 'required|string|max:100',
            'apellido_materno' => 'nullable|string|max:100',
            'identificador'    => [
                'required', 'uuid', 'max:100',
                Rule::unique('usuarios', 'identificador')->ignore($usuario->id),
            ],
            'correo'           => [
                'nullable', 'email', 'max:150',
                Rule::unique('usuarios', 'correo')->ignore($usuario->id),
            ],
            'contrasena'       => 'nullable|string|min:8',
            'telefono'         => 'nullable|string|max:20',
            'estado'           => 'boolean',
        ];

        // El superadmin puede (opcionalmente) modificar rol y organización.
        if ($this->esSuperadmin($user)) {
            $reglas['organizaciones_id'] = 'nullable|integer|exists:organizaciones,id';
            $reglas['roles_id']          = 'nullable|integer|exists:roles,id';
        }

        $validator = Validator::make($request->all(), $reglas);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $usuario->nombre           = $request->nombre;
        $usuario->apellido_paterno = $request->apellido_paterno;
        $usuario->apellido_materno = $request->apellido_materno;
        $usuario->identificador    = $request->identificador;
        $usuario->telefono         = $request->telefono;

        // Correo opcional. Solo se modifica si el campo viene EN la petición
        // (aunque sea vacío -> NULL). Si el formulario no envía `correo`, se
        // CONSERVA el valor actual (no se borra el correo de quien ya lo tenía).
        if ($request->has('correo')) {
            $usuario->correo = $request->filled('correo') ? $request->correo : null;
        }

        // Rol y organización: solo el superadmin puede cambiarlos, y solo si los
        // envía. El administrador conserva los valores actuales del registro (no
        // eleva privilegios ni mueve de organización, aunque intente enviarlos).
        if ($this->esSuperadmin($user)) {
            if ($request->filled('organizaciones_id')) {
                $usuario->organizaciones_id = (int) $request->organizaciones_id;
            }
            if ($request->filled('roles_id')) {
                $usuario->roles_id = (int) $request->roles_id;
            }
        }

        if ($request->has('estado')) {
            $usuario->estado = (bool) $request->estado;
        }

        // La contraseña solo se actualiza si se envía una nueva.
        if ($request->filled('contrasena')) {
            $usuario->contrasena = Hash::make($request->contrasena);
        }

        $usuario->save();

        return response()->json(
            UsuarioCrud::query()->find($usuario->id, $this->camposPublicos)
        );
    }

    /**
     * Baja lógica: estado = false. No elimina físicamente.
     * - ADMINISTRADOR: solo cuentas USUARIO de su organización.
     * - SUPERADMIN: cualquiera.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $usuario = UsuarioCrud::query()->find($id);

        if (!$usuario) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        // Aislamiento por organización + protección de cuentas privilegiadas.
        if ($deny = $this->denegarObjetivoFueraDeAmbito($user, $usuario)) {
            return $deny;
        }

        $usuario->estado = false;
        $usuario->save();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    /**
     * Catálogo de roles.
     * - SUPERADMIN: todos los roles del sistema.
     * - ADMINISTRADOR: solo el rol que puede asignar (USUARIO).
     * - USUARIO: 403.
     */
    public function roles(Request $request)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $query = Rol::query()->orderBy('id');

        if (!$this->esSuperadmin($user)) {
            // Administrador: solo puede asignar el rol USUARIO.
            $query->where('id', Rol::USUARIO);
        }

        return response()->json($query->get(['id', 'nombre', 'descripcion']));
    }

    /**
     * Catálogo de organizaciones.
     * - SUPERADMIN: todas.
     * - ADMINISTRADOR: únicamente su propia organización.
     * - USUARIO: 403.
     */
    public function organizaciones(Request $request)
    {
        $user = $request->user();
        if ($deny = $this->denegarSiNoEsAdministrativo($user)) {
            return $deny;
        }

        $query = Organizacion::query()->orderBy('nombre');

        if (!$this->esSuperadmin($user)) {
            // Administrador: solo su organización.
            $query->where('id', $user->organizaciones_id);
        }

        return response()->json($query->get(['id', 'nombre']));
    }
}
