<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Organizacion;
use App\Models\VerificacionCodigo;
use App\Models\RecuperarContrasena;
use App\Models\Rol;
use App\Mail\CodigoVerificacionMail;
use App\Mail\RecuperarContrasenaMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Registro de usuario + organización
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre'        => 'required|string|max:100',
            'apellido_pa'   => 'required|string|max:100',
            'apellido_ma'   => 'nullable|string|max:100',
            'email'         => 'required|string|email|max:150|unique:usuarios,email',
            'password'      => 'required|string|min:8|confirmed',

            'organizacion_nombre'     => 'required|string|max:150',
            'organizacion_tipo'       => 'required|string|max:50',
            'organizacion_direccion'  => 'nullable|string|max:225',
            'organizacion_colonia'    => 'nullable|string|max:100',
            'organizacion_ciudad'     => 'nullable|string|max:100',
            'organizacion_codigo_postal' => 'nullable|string|max:10',
            'organizacion_telefono'   => 'nullable|string|max:20',
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
                'identificador'     => Str::uuid()->toString(),
                'estado'            => 'P', // Pendiente hasta verificar correo
                'organizaciones_id' => $organizacion->id,
                'roles_id'          => Rol::ADMINISTRADOR,
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
     * Verificar código
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
            return response()->json(['message' => 'Código inválido o expirado.'], 400);
        }

        $registro->update(['usado' => 1]);

        Usuario::where('email', $request->email)->update(['estado' => 'A']);

        return response()->json(['message' => 'Cuenta verificada exitosamente.'], 200);
    }

    /**
     * Reenviar código
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

        VerificacionCodigo::where('email', $request->email)
            ->where('usado', 0)
            ->update(['usado' => 1]);

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

        $token = $usuario->createToken('auth_token')->plainTextToken;

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