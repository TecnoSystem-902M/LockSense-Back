<?php

namespace App\Http\Controllers;

use App\Models\Casillero;
use App\Models\Usuario;
use App\Models\Organizacion;
use App\Models\VerificacionCodigo;
use App\Models\RecuperarContrasena;
use App\Models\Rol;
use App\Services\CodigoOrganizacion;
use App\Mail\CodigoVerificacionMail;
use App\Mail\RecuperarContrasenaMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    // Minutos que dura un registro pendiente de verificar.
    private const MINUTOS_REGISTRO_PENDIENTE = 15;

    // Intentos de codigo permitidos antes de invalidar el registro pendiente.
    private const MAX_INTENTOS_CODIGO = 5;

    // Duración del token de sesión.
    private const DIAS_SESION_RECORDADA = 30;   // "Recordar mi sesión" marcado
    private const HORAS_SESION_NORMAL   = 12;   // sin marcar

    // Compra simulada: cada organizacion nueva recibe un mueble con 6 casilleros.
    private const CASILLEROS_POR_ORGANIZACION = 6;

    // Estado inicial de los casilleros. Ajustar al valor que usa su plataforma.
    // Revisar con: SELECT DISTINCT estado FROM casilleros;
    private const ESTADO_CASILLERO_INICIAL = 'Libre';

    // El superadmin nunca se bloquea por el estado de su organización.
    private const ROL_SUPERADMIN = 1;

    /**
     * Registro de usuario + organización.
     *
     * NO guarda nada en la base de datos. Valida los datos, los guarda
     * temporalmente en caché y envía el código. La organización y el
     * usuario se crean hasta que el código se verifica (método verify).
     */
    public function register(Request $request)
    {
        // Limpieza antes de validar (igual que en los CRUD del superadmin).
        $request->merge([
            'nombre'                     => trim((string) $request->input('nombre')),
            'apellido_pa'                => trim((string) $request->input('apellido_pa')),
            'apellido_ma'                => trim((string) $request->input('apellido_ma')) ?: null,
            'email'                      => strtolower(trim((string) $request->input('email'))),
            'organizacion_nombre'        => trim((string) $request->input('organizacion_nombre')),
            'organizacion_telefono'      => preg_replace('/\D/', '', (string) $request->input('organizacion_telefono')),
            'organizacion_direccion'     => trim((string) $request->input('organizacion_direccion')),
            'organizacion_colonia'       => trim((string) $request->input('organizacion_colonia')),
            'organizacion_ciudad'        => trim((string) $request->input('organizacion_ciudad')),
            'organizacion_codigo_postal' => trim((string) $request->input('organizacion_codigo_postal')),
        ]);

        $soloLetras = 'regex:/^[\pL\s\'.-]+$/u';   // letras (con acentos), espacios, ' . -

        $validator = Validator::make($request->all(), [
            // Administrador (mismas reglas que AdministradorController)
            'nombre'        => ['required', 'string', 'min:2', 'max:100', $soloLetras],
            'apellido_pa'   => ['required', 'string', 'min:2', 'max:100', $soloLetras],
            'apellido_ma'   => ['nullable', 'string', 'max:100', $soloLetras],
            'email'         => ['required', 'email', 'max:150', 'unique:usuarios,email'],
            'password'      => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/\d/', 'confirmed'],

            // Organización (mismas reglas que OrganizacionController)
            'organizacion_nombre'        => ['required', 'string', 'min:3', 'max:150'],
            'organizacion_tipo'          => ['required', Rule::in(OrganizacionController::TIPOS)],
            'organizacion_telefono'      => ['required', 'digits:10'],
            'organizacion_direccion'     => ['required', 'string', 'max:225'],
            'organizacion_colonia'       => ['required', 'string', 'max:100'],
            'organizacion_ciudad'        => ['required', 'string', 'max:100'],
            'organizacion_codigo_postal' => ['required', 'digits:5'],
        ], [
            'nombre.required'      => 'El nombre es obligatorio.',
            'nombre.min'           => 'El nombre debe tener al menos 2 letras.',
            'nombre.max'           => 'El nombre no puede pasar de 100 caracteres.',
            'nombre.regex'         => 'El nombre solo puede tener letras.',
            'apellido_pa.required' => 'El apellido paterno es obligatorio.',
            'apellido_pa.min'      => 'El apellido paterno debe tener al menos 2 letras.',
            'apellido_pa.max'      => 'El apellido paterno no puede pasar de 100 caracteres.',
            'apellido_pa.regex'    => 'El apellido paterno solo puede tener letras.',
            'apellido_ma.max'      => 'El apellido materno no puede pasar de 100 caracteres.',
            'apellido_ma.regex'    => 'El apellido materno solo puede tener letras.',
            'email.required'       => 'El correo es obligatorio.',
            'email.email'          => 'Escribe un correo válido.',
            'email.max'            => 'El correo no puede pasar de 150 caracteres.',
            'email.unique'         => 'Ese correo ya está registrado.',
            'password.required'    => 'La contraseña es obligatoria.',
            'password.min'         => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max'         => 'La contraseña no puede pasar de 72 caracteres.',
            'password.regex'       => 'La contraseña debe tener al menos una letra y un número.',
            'password.confirmed'   => 'Las contraseñas no coinciden.',

            'organizacion_nombre.required'      => 'El nombre de la organización es obligatorio.',
            'organizacion_nombre.min'           => 'El nombre debe tener al menos 3 caracteres.',
            'organizacion_nombre.max'           => 'El nombre no puede pasar de 150 caracteres.',
            'organizacion_tipo.required'        => 'Selecciona el tipo de organización.',
            'organizacion_tipo.in'              => 'El tipo seleccionado no es válido.',
            'organizacion_telefono.required'    => 'El teléfono es obligatorio.',
            'organizacion_telefono.digits'      => 'El teléfono debe tener 10 dígitos (lada + número).',
            'organizacion_direccion.required'   => 'La dirección es obligatoria.',
            'organizacion_direccion.max'        => 'La dirección no puede pasar de 225 caracteres.',
            'organizacion_colonia.required'     => 'La colonia es obligatoria.',
            'organizacion_colonia.max'          => 'La colonia no puede pasar de 100 caracteres.',
            'organizacion_ciudad.required'      => 'La ciudad es obligatoria.',
            'organizacion_ciudad.max'           => 'La ciudad no puede pasar de 100 caracteres.',
            'organizacion_codigo_postal.required' => 'El código postal es obligatorio.',
            'organizacion_codigo_postal.digits'   => 'El código postal debe tener 5 dígitos.',
        ]);

        // Nombre de organización único, sin importar mayúsculas/minúsculas.
        $validator->after(function ($validator) use ($request) {
            if ($validator->errors()->has('organizacion_nombre')) {
                return;
            }

            $nombre = addcslashes($request->input('organizacion_nombre'), '%_\\');

            if (Organizacion::where('nombre', 'ilike', $nombre)->exists()) {
                $validator->errors()->add('organizacion_nombre', 'Ya existe una organización con ese nombre.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $email = $this->normalizarEmail($request->email);
        $codigo = $this->generarCodigo();

        // La contraseña se guarda ya cifrada; nunca en texto plano.
        $pendiente = [
            'organizacion' => [
                'nombre'        => $request->organizacion_nombre,
                'tipo'          => $request->organizacion_tipo,
                'direccion'     => $request->organizacion_direccion,
                'colonia'       => $request->organizacion_colonia,
                'ciudad'        => $request->organizacion_ciudad,
                'codigo_postal' => $request->organizacion_codigo_postal,
                'telefono'      => $request->organizacion_telefono,
            ],
            'usuario' => [
                'nombre'      => $request->nombre,
                'apellido_pa' => $request->apellido_pa,
                'apellido_ma' => $request->apellido_ma,
                'email'       => $email,
                'password'    => Hash::make($request->password),
            ],
            'codigo_hash' => hash('sha256', $codigo),
            'intentos'    => 0,
            'expira_en'   => Carbon::now()->addMinutes(self::MINUTOS_REGISTRO_PENDIENTE)->timestamp,
        ];

        // Si ya habia un registro pendiente con este correo, se reemplaza.
        $this->guardarPendiente($email, $pendiente);

        try {
            Mail::to($email)->send(new CodigoVerificacionMail($codigo, $request->nombre));
        } catch (\Exception $e) {
            \Log::error('Error al enviar correo de verificación: ' . $e->getMessage());
            Cache::forget($this->clavePendiente($email));

            return response()->json([
                'message' => 'No se pudo enviar el correo de verificación. Intenta de nuevo.',
            ], 500);
        }

        return response()->json([
            'message' => 'Te enviamos un código de verificación. Ingrésalo para completar el registro.',
            'email'   => $email,
        ], 200);
    }

    /**
     * Verificar código.
     *
     * Si el código es correcto, AHORA SÍ se crean la organización
     * y el usuario (ya activo).
     */
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'  => 'required|email',
            'codigo' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $email = $this->normalizarEmail($request->email);
        $pendiente = Cache::get($this->clavePendiente($email));

        // Compatibilidad: cuentas creadas con el flujo anterior (estado 'P').
        if (! $pendiente) {
            return $this->verificarCuentaExistente($email, $request->codigo);
        }

        if ($pendiente['intentos'] >= self::MAX_INTENTOS_CODIGO) {
            Cache::forget($this->clavePendiente($email));

            return response()->json([
                'message' => 'Demasiados intentos. Vuelve a registrarte para recibir un código nuevo.',
                'code'    => 'REGISTRO_EXPIRADO',
            ], 429);
        }

        if (! hash_equals($pendiente['codigo_hash'], hash('sha256', $request->codigo))) {
            $pendiente['intentos']++;
            $this->guardarPendiente($email, $pendiente);

            $restantes = self::MAX_INTENTOS_CODIGO - $pendiente['intentos'];

            return response()->json([
                'message' => "Código incorrecto. Te quedan {$restantes} intento(s).",
            ], 400);
        }

        // Por si alguien registró el mismo correo mientras tanto.
        if (Usuario::where('email', $email)->exists()) {
            Cache::forget($this->clavePendiente($email));

            return response()->json(['message' => 'Este correo ya está registrado.'], 409);
        }

        DB::beginTransaction();

        try {
            $organizacion = Organizacion::create(array_merge(
                $pendiente['organizacion'],
                ['estado' => 'A']
            ));

            $usuario = Usuario::create(array_merge($pendiente['usuario'], [
                'identificador'     => Str::uuid()->toString(),
                'estado'            => 'A',
                'organizaciones_id' => $organizacion->id,
                'roles_id'          => Rol::ADMINISTRADOR,
            ]));

            // Código corto de la organización (prefijo de matrículas, ej. "UTN")
            CodigoOrganizacion::asegurar($organizacion);

            // Compra simulada: crear los 6 casilleros de la organización.
            $this->crearCasilleros($organizacion->id);

            // Historial: el código ya se usó.
            VerificacionCodigo::create([
                'email'       => $usuario->email,
                'codigo'      => '------',
                'tipo'        => 'email_verification',
                'expira_en'   => Carbon::now(),
                'usado'       => 1,
                'usuarios_id' => $usuario->id,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error al completar registro: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al completar el registro. Intenta de nuevo.',
            ], 500);
        }

        Cache::forget($this->clavePendiente($email));

        return response()->json([
            'message'         => 'Cuenta verificada y registrada exitosamente.',
            'user_id'         => $usuario->id,
            'organizacion_id' => $organizacion->id,
        ], 201);
    }

    /**
     * Reenviar código.
     */
    public function resendCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $email = $this->normalizarEmail($request->email);
        $pendiente = Cache::get($this->clavePendiente($email));

        // Compatibilidad: cuentas creadas con el flujo anterior (estado 'P').
        if (! $pendiente) {
            return $this->reenviarCuentaExistente($email);
        }

        $codigo = $this->generarCodigo();

        $pendiente['codigo_hash'] = hash('sha256', $codigo);
        $pendiente['intentos'] = 0;
        $pendiente['expira_en'] = Carbon::now()->addMinutes(self::MINUTOS_REGISTRO_PENDIENTE)->timestamp;

        $this->guardarPendiente($email, $pendiente);

        try {
            Mail::to($email)->send(
                new CodigoVerificacionMail($codigo, $pendiente['usuario']['nombre'])
            );
        } catch (\Exception $e) {
            \Log::error('Error al reenviar correo: ' . $e->getMessage());

            return response()->json(['message' => 'No se pudo enviar el correo.'], 500);
        }

        return response()->json(['message' => 'Código reenviado.'], 200);
    }

    // ==================================================
    // Auxiliares del registro
    // ==================================================

    /**
     * Crea los casilleros A-01 ... A-06 de una organización.
     * Si la organización ya tiene casilleros, no hace nada (evita duplicados).
     */
    private function crearCasilleros(int $organizacionId): void
    {
        if (Casillero::where('organizaciones_id', $organizacionId)->exists()) {
            return;
        }

        for ($i = 1; $i <= self::CASILLEROS_POR_ORGANIZACION; $i++) {
            Casillero::create([
                'organizaciones_id' => $organizacionId,
                'numero'            => 'A-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'estado'            => self::ESTADO_CASILLERO_INICIAL,
            ]);
        }
    }

    private function generarCodigo(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function normalizarEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function clavePendiente(string $email): string
    {
        return 'registro_pendiente:' . $email;
    }

    /** Guarda el registro pendiente hasta su hora de expiración. */
    private function guardarPendiente(string $email, array $pendiente): void
    {
        Cache::put(
            $this->clavePendiente($email),
            $pendiente,
            Carbon::createFromTimestamp($pendiente['expira_en'])
        );
    }

    /**
     * Flujo anterior: usuario ya creado con estado 'P' y código
     * en verificacion_codigos. Se conserva para no dejar atoradas
     * las cuentas que se registraron antes de este cambio.
     */
    private function verificarCuentaExistente(string $email, string $codigo)
    {
        $usuario = Usuario::where('email', $email)->where('estado', 'P')->first();

        if (! $usuario) {
            return response()->json([
                'message' => 'El código expiró o no existe. Vuelve a registrarte.',
                'code'    => 'REGISTRO_EXPIRADO',
            ], 400);
        }

        $registro = VerificacionCodigo::where('usuarios_id', $usuario->id)
            ->where('codigo', $codigo)
            ->where('usado', 0)
            ->where('expira_en', '>', Carbon::now())
            ->latest('id')
            ->first();

        if (! $registro) {
            return response()->json(['message' => 'Código inválido o expirado.'], 400);
        }

        $registro->update(['usado' => 1]);
        $usuario->update(['estado' => 'A']);

        // Por si su organización se creó sin casilleros.
        $this->crearCasilleros($usuario->organizaciones_id);

        return response()->json(['message' => 'Cuenta verificada exitosamente.'], 200);
    }

    private function reenviarCuentaExistente(string $email)
    {
        $usuario = Usuario::where('email', $email)->where('estado', 'P')->first();

        if (! $usuario) {
            return response()->json([
                'message' => 'No hay un registro pendiente con este correo. Vuelve a registrarte.',
                'code'    => 'REGISTRO_EXPIRADO',
            ], 404);
        }

        VerificacionCodigo::where('usuarios_id', $usuario->id)
            ->where('usado', 0)
            ->update(['usado' => 1]);

        $codigo = $this->generarCodigo();

        VerificacionCodigo::create([
            'email'       => $usuario->email,
            'codigo'      => $codigo,
            'tipo'        => 'email_verification',
            'expira_en'   => Carbon::now()->addMinutes(self::MINUTOS_REGISTRO_PENDIENTE),
            'usado'       => 0,
            'usuarios_id' => $usuario->id,
        ]);

        try {
            Mail::to($usuario->email)->send(new CodigoVerificacionMail($codigo, $usuario->nombre));
        } catch (\Exception $e) {
            \Log::error('Error al reenviar correo: ' . $e->getMessage());

            return response()->json(['message' => 'No se pudo enviar el correo.'], 500);
        }

        return response()->json(['message' => 'Código reenviado.'], 200);
    }

    /**
     * Login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'         => 'required_without:identificador|email',
            'identificador' => 'required_without:email|string',
            'password'      => 'required|string',
            'recordar'      => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->filled('identificador')) {
            $usuario = Usuario::where('identificador', $request->identificador)->first();
        } else {
            $usuario = Usuario::where('email', $request->email)->first();
        }

        if (!$usuario || !Hash::check($request->password, $usuario->password)) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        if ($usuario->estado !== 'A') {
            return response()->json([
                'message' => 'Tu cuenta no está verificada. Revisa tu correo.',
                'code'    => 'NOT_VERIFIED',
                'email'   => $usuario->email,
            ], 403);
        }

        // Organización dada de baja por el superadministrador.
        $usuario->loadMissing('organizacion');

        if ($usuario->organizacion
            && $usuario->organizacion->estado !== 'A'
            && (int) $usuario->roles_id !== self::ROL_SUPERADMIN) {
            return response()->json([
                'message' => 'Tu organización está dada de baja. Contacta al administrador de LockSense.',
                'code'    => 'ORGANIZACION_INACTIVA',
            ], 403);
        }

        // "Recordar mi sesión": el token dura más tiempo en el servidor.
        $expiraEn = $request->boolean('recordar')
            ? Carbon::now()->addDays(self::DIAS_SESION_RECORDADA)
            : Carbon::now()->addHours(self::HORAS_SESION_NORMAL);

        $token = $usuario->createToken('auth_token', ['*'], $expiraEn)->plainTextToken;

        $usuario->load('rol', 'organizacion');

        return response()->json([
            'message' => 'Login exitoso.',
            'token'   => $token,
            'user'    => [
                'id'            => $usuario->id,
                'nombre'        => $usuario->nombre,
                'apellido_pa'   => $usuario->apellido_pa,
                'apellido_ma'   => $usuario->apellido_ma,
                'email'         => $usuario->email,
                'identificador' => $usuario->identificador,
                'estado'        => $usuario->estado,
                'rol'           => $usuario->rol->nombre,
                'rol_id'        => $usuario->rol->id,
                'tiene_rostro'  => $usuario->rostroActivo()->exists(),
                'organizacion'  => [
                    'id'     => $usuario->organizacion->id,
                    'nombre' => $usuario->organizacion->nombre,
                ],
            ],
        ], 200);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * Usuario actual
     */
    public function me(Request $request)
    {
        $usuario = $request->user()->load('rol', 'organizacion');

        return response()->json([
            'user' => [
                'id'            => $usuario->id,
                'nombre'        => $usuario->nombre,
                'apellido_pa'   => $usuario->apellido_pa,
                'apellido_ma'   => $usuario->apellido_ma,
                'email'         => $usuario->email,
                'identificador' => $usuario->identificador,
                'rol'           => $usuario->rol->nombre,
                'rol_id'        => $usuario->rol->id,
                'tiene_rostro'  => $usuario->rostroActivo()->exists(),
                'organizacion'  => $usuario->organizacion->nombre,
            ],
        ]);
    }

    /**
     * Solicitar enlace de recuperación
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:usuarios,email',
        ], [
            'email.exists' => 'No encontramos una cuenta con ese correo.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $usuario = Usuario::where('email', $request->email)->first();

        RecuperarContrasena::where('email', $request->email)
            ->where('usado', 0)
            ->update(['usado' => 1]);

        $token = Str::random(64);

        RecuperarContrasena::create([
            'email'       => $usuario->email,
            'codigo'      => $token,
            'expira_en'   => Carbon::now()->addMinutes(30),
            'usado'       => 0,
            'usuarios_id' => $usuario->id,
        ]);

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $enlace = $frontendUrl . '/recuperar?' . http_build_query([
            'token' => $token,
            'email' => $usuario->email,
        ]);

        try {
            Mail::to($usuario->email)->send(
                new RecuperarContrasenaMail($enlace, $usuario->nombre)
            );
        } catch (\Exception $e) {
            \Log::error('Error al enviar correo de recuperación: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo enviar el correo.'], 500);
        }

        return response()->json(['message' => 'Te enviamos un enlace de recuperación.'], 200);
    }

    /**
     * Restablecer contraseña
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email|exists:usuarios,email',
            'token'    => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $registro = RecuperarContrasena::where('email', $request->email)
            ->where('codigo', $request->token)
            ->where('usado', 0)
            ->where('expira_en', '>', Carbon::now())
            ->latest('id')
            ->first();

        if (!$registro) {
            return response()->json([
                'message' => 'El enlace es inválido o ha expirado.',
                'code'    => 'INVALID_TOKEN',
            ], 400);
        }

        Usuario::where('email', $request->email)->update([
            'password' => Hash::make($request->password),
        ]);

        $registro->update(['usado' => 1]);

        RecuperarContrasena::where('email', $request->email)
            ->where('usado', 0)
            ->update(['usado' => 1]);

        return response()->json(['message' => 'Contraseña restablecida exitosamente.'], 200);
    }
}