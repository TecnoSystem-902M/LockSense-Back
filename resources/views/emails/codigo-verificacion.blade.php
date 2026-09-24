<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Código de verificación - LockSense</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">

    <!-- Preheader oculto -->
    <div style="display: none; max-height: 0; overflow: hidden; font-size: 1px; color: #f4f6f8; line-height: 1px;">
        Tu código de verificación de LockSense es: {{ $codigo }}
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f4f6f8; padding: 40px 16px;">
        <tr>
            <td align="center">

                <!-- Contenedor Principal -->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 580px; width: 100%; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08); border: 1px solid #e5e7eb;">

                    <!-- Banner de Marca Superior -->
                    <tr>
                        <td align="center" style="background-color: #0f2922; padding: 36px 24px;">
                            
                            <!-- Ícono / Logo -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-bottom: 12px;">
                                <tr>
                                    <td align="center" style="width: 52px; height: 52px; background-color: #10b981; border-radius: 12px; text-align: center; vertical-align: middle;">
                                        <span style="color: #0f2922; font-size: 26px; font-weight: 800; font-family: Arial, sans-serif; line-height: 52px; display: block;">
                                            L
                                        </span>
                                    </td>
                                </tr>
                            </table>

                            <h1 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 700; letter-spacing: 1px;">
                                LockSense
                            </h1>
                            <p style="margin: 4px 0 0 0; color: #10b981; font-size: 11px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase;">
                                Sistema de casilleros inteligentes
                            </p>
                        </td>
                    </tr>

                    <!-- Cuerpo del Mensaje -->
                    <tr>
                        <td style="padding: 36px 32px 32px 32px;">

                            <h2 style="margin: 0 0 12px 0; color: #0f2922; font-size: 22px; font-weight: 700; line-height: 1.3;">
                                Hola, {{ $nombreUsuario }}
                            </h2>

                            <p style="margin: 0 0 24px 0; color: #4b5563; font-size: 15px; line-height: 1.6;">
                                Gracias por registrarte en <strong>LockSense</strong>. Para completar la verificación de tu cuenta y activar tu acceso, ingresa el siguiente código:
                            </p>

                            <!-- Tarjeta del Código de Verificación -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td align="center" style="padding: 24px 16px; background-color: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px;">
                                        <span style="display: block; color: #64748b; font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 8px;">
                                            Código de verificación
                                        </span>
                                        <span style="color: #0f2922; font-size: 38px; font-weight: 800; letter-spacing: 10px; font-family: 'Courier New', Consolas, monospace; display: block; line-height: 1;">
                                            {{ $codigo }}
                                        </span>
                                    </td>
                                </tr>
                            </table>

                            <!-- Alerta de Tiempo / Expiración -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom: 28px;">
                                <tr>
                                    <td style="padding: 14px 18px; background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;">
                                        <p style="margin: 0; color: #166534; font-size: 13px; line-height: 1.5;">
                                            <strong>⏱ Validez:</strong> Este código expira en <strong>15 minutos</strong> y solo se puede utilizar una vez.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Caja de Seguridad (Tip) -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="padding: 18px 20px; background-color: #0f2922; border-radius: 10px;">
                                        <p style="margin: 0 0 6px 0; color: #10b981; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;">
                                            💡 Tip de seguridad
                                        </p>
                                        <p style="margin: 0; color: #e5e7eb; font-size: 13px; line-height: 1.5;">
                                            Nunca compartas este código con nadie. El equipo de LockSense jamás te pedirá tu código por correo, llamadas o mensajes.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>

                    <!-- Footer Integrado -->
                    <tr>
                        <td align="center" style="padding: 24px 32px; background-color: #f9fafb; border-top: 1px solid #f3f4f6;">
                            <p style="margin: 0 0 6px 0; color: #374151; font-size: 12px; font-weight: 600;">
                                LockSense
                            </p>
                            <p style="margin: 0; color: #9ca3af; font-size: 11px; line-height: 1.5;">
                                Este es un correo automático, por favor no respondas a este mensaje.<br>
                                © {{ date('Y') }} LockSense. Todos los derechos reservados.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>