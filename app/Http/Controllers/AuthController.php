<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Organizacion;
use App\Models\VerificacionCodigo;
use App\Models\Rol;
use App\Mail\CodigoVerificacionMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Models\RecuperarContrasena;
use App\Mail\RecuperarContrasenaMail;
use Illuminate\Support\Str;


class AuthController extends Controller
{
    /**
     * Registro de usuario + organización (un solo formulario)
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Usuario
            'nombre'        => 'required|string|max:100',
            'apellido_pa'   => 'required|string|max:100',
            'apellido_ma'   => 'nullable|string|max:100',
            'email'         => 'required|string|email|max:150|unique:usuarios,email',
            'password'      => 'required|string|min:8|confirmed',
            'identificador' => 'nullable|string|max:100|unique:usuarios,identificador',

            // Organización
            'organizacion_nombre'        => 'required|string|max:150',
            'organizacion_tipo'          => 'required|string|max:50',
            'organizacion_direccion'     => 'nullable|string|max:225',
            'organizacion_colonia'       => 'nullable|string|max:100',
            'organizacion_ciudad'        => 'nullable|string|max:100',
            'organizacion_codigo_postal' => 'nullable|string|max:10',
            'organizacion_telefono'      => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            // 1. Crear organización
            $organizacion = Organizacion::create([
                'nombre'        => $request->organizacion_nombre,
                'tipo'          => $request->organizacion_tipo,
                'direccion'     => $request->organizacion_direccion,
                'colonia'       => $request->organizacion_colonia,
                'ciudad'        => $request->organizacion_ciudad,
                'codigo_postal' => $request->organizacion_codigo_postal,
                'telefono'      => $request->organizacion_telefono,
                'estado'        => 'A',
            ]);

            // 2. Crear usuario
            $usuario = Usuario::create([
                'nombre'            => $request->nombre,
                'apellido_pa'       => $request->apellido_pa,
                'apellido_ma'       => $request->apellido_ma,
                'email'             => $request->email,
                'password'          => Hash::make($request->password),
                'identificador'     => $request->identificador,
                'estado'            => 'P', // Pendiente
                'organizaciones_id' => $organizacion->id,
                'roles_id'          => Rol::ADMINISTRADOR, // = 2
            ]);

            // 3. Generar código
            $codigo = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            VerificacionCodigo::create([
                'email'       => $usuario->email,
                'codigo'      => $codigo,
                'tipo'        => 'email_verification',
                'expira_en'   => Carbon::now()->addMinutes(15),
                'usado'       => 0,
                'usuarios_id' => $usuario->id,
            ]);

            // 4. Enviar correo
            try {
                Mail::to($usuario->email)->send(
                    new CodigoVerificacionMail($codigo, $usuario->nombre)
                );
            } catch (\Exception $e) {
                \Log::error('Error al enviar correo: ' . $e->getMessage());
                // No revertimos el registro, solo lo logueamos
            }

            DB::commit();

            return response()->json([
                'message'         => 'Usuario registrado. Revisa tu correo.',
                'user_id'         => $usuario->id,
                'organizacion_id' => $organizacion->id,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error en registro: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al registrar.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verificar código de correo
     */
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'  => 'required|email|exists:usuarios,email',
            'codigo' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $registro = VerificacionCodigo::where('email', $request->email)
            ->where('codigo', $request->codigo)
            ->where('usado', 0)
            ->where('expira_en', '>', Carbon::now())
            ->latest('id')
            ->first();

        if (!$registro) {
            return response()->json([
                'message' => 'Código inválido o expirado.',
            ], 400);
        }

        // Marcar código como usado
        $registro->update(['usado' => 1]);

        // Activar usuario
        Usuario::where('email', $request->email)->update(['estado' => 'A']);

        return response()->json([
            'message' => 'Cuenta verificada exitosamente.',
        ], 200);
    }

    /**
     * Reenviar código de verificación
     */
    public function resendCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:usuarios,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $usuario = Usuario::where('email', $request->email)->first();

        // Invalidar códigos anteriores no usados
        VerificacionCodigo::where('email', $request->email)
            ->where('usado', 0)
            ->update(['usado' => 1]);

        // Generar nuevo código
        $codigo = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        VerificacionCodigo::create([
            'email'       => $usuario->email,
            'codigo'      => $codigo,
            'tipo'        => 'email_verification',
            'expira_en'   => Carbon::now()->addMinutes(15),
            'usado'       => 0,
            'usuarios_id' => $usuario->id,
        ]);

        try {
            Mail::to($usuario->email)->send(
                new CodigoVerificacionMail($codigo, $usuario->nombre)
            );
        } catch (\Exception $e) {
            \Log::error('Error al reenviar correo: ' . $e->getMessage());
            return response()->json([
                'message' => 'No se pudo enviar el correo. Intenta de nuevo.',
            ], 500);
        }

        return response()->json([
            'message' => 'Código reenviado. Revisa tu correo.',
        ], 200);
    }

    /**
 * Login de usuario
 */
public function login(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // Buscar usuario
    $usuario = Usuario::where('email', $request->email)->first();

    // Verificar credenciales
    if (!$usuario || !Hash::check($request->password, $usuario->password)) {
        return response()->json([
            'message' => 'Credenciales incorrectas.',
        ], 401);
    }

    // Verificar que esté activo (correo verificado)
    if ($usuario->estado !== 'A') {
        return response()->json([
            'message' => 'Tu cuenta no está verificada. Revisa tu correo.',
            'code'    => 'NOT_VERIFIED',
            'email'   => $usuario->email,
        ], 403);
    }

    // Crear token con Sanctum
    $token = $usuario->createToken('auth_token')->plainTextToken;

    // Cargar relación con rol y organización
    $usuario->load('rol', 'organizacion');

    return response()->json([
        'message' => 'Login exitoso.',
        'token'   => $token,
        'user'    => [
            'id'              => $usuario->id,
            'nombre'          => $usuario->nombre,
            'apellido_pa'     => $usuario->apellido_pa,
            'apellido_ma'     => $usuario->apellido_ma,
            'email'           => $usuario->email,
            'identificador'   => $usuario->identificador,
            'estado'          => $usuario->estado,
            'rol'             => $usuario->rol->nombre,        // "Superadmin", "Administrador", "Usuario"
            'rol_id'          => $usuario->rol->id,
            'organizacion'    => [
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
 * Usuario autenticado actual (útil para validar sesión)
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
            'organizacion'  => $usuario->organizacion->nombre,
        ],
    ]);
}

/**
 * Solicitar enlace de recuperación de contraseña
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

    // Invalidar tokens anteriores no usados
    RecuperarContrasena::where('email', $request->email)
        ->where('usado', 0)
        ->update(['usado' => 1]);

    // Generar token único (64 caracteres)
    $token = Str::random(64);

    RecuperarContrasena::create([
        'email'       => $usuario->email,
        'codigo'      => $token, // reutilizamos la columna 'codigo' para el token
        'expira_en'   => Carbon::now()->addMinutes(30), // 30 min para enlaces mágicos
        'usado'       => 0,
        'usuarios_id' => $usuario->id,
    ]);

    // Construir el enlace al frontend
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
        return response()->json([
            'message' => 'No se pudo enviar el correo. Intenta de nuevo.',
        ], 500);
    }

    return response()->json([
        'message' => 'Te enviamos un enlace de recuperación a tu correo.',
    ], 200);
}

/**
 * Restablecer contraseña con token
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

    // Buscar el token
    $registro = RecuperarContrasena::where('email', $request->email)
        ->where('codigo', $request->token)
        ->where('usado', 0)
        ->where('expira_en', '>', Carbon::now())
        ->latest('id')
        ->first();

    if (!$registro) {
        return response()->json([
            'message' => 'El enlace es inválido o ha expirado. Solicita uno nuevo.',
            'code'    => 'INVALID_TOKEN',
        ], 400);
    }

    // Actualizar contraseña
    $usuario = Usuario::where('email', $request->email)->first();
    $usuario->update([
        'password' => Hash::make($request->password),
    ]);

    // Marcar token como usado
    $registro->update(['usado' => 1]);

    // Invalidar cualquier otro token pendiente
    RecuperarContrasena::where('email', $request->email)
        ->where('usado', 0)
        ->update(['usado' => 1]);

    return response()->json([
        'message' => 'Contraseña restablecida exitosamente.',
    ], 200);
}
}