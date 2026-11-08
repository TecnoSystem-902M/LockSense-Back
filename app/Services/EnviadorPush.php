<?php

namespace App\Services;

use App\Models\PushSuscripcion;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envía notificaciones push (Web Push) a los dispositivos de un usuario.
 *
 * Requiere:  composer require minishlink/web-push
 * y en el .env: VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT
 *
 * Si algo falla (servicio de push caído, claves sin configurar...), solo se
 * escribe en el log: la anomalía ya quedó guardada de todos modos.
 */
class EnviadorPush
{
    // Segundos que el servicio de push guarda el mensaje si el
    // dispositivo está apagado o sin internet.
    private const TTL = 6 * 60 * 60;   // 6 horas

    /**
     * @param array $mensaje ['titulo' => ..., 'cuerpo' => ..., 'url' => ..., 'etiqueta' => ...]
     */
    public function enviarAUsuario(int $usuarioId, array $mensaje): void
    {
        $suscripciones = PushSuscripcion::query()
            ->where('usuarios_id', $usuarioId)
            ->get();

        if ($suscripciones->isEmpty()) {
            return;
        }

        $config = config('services.webpush');

        if (empty($config['public_key']) || empty($config['private_key'])) {
            Log::warning('Push: faltan VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY en el .env.');
            return;
        }

        try {
            $webPush = new WebPush(
                [
                    'VAPID' => [
                        'subject'    => $config['subject'],
                        'publicKey'  => $config['public_key'],
                        'privateKey' => $config['private_key'],
                    ],
                ],
                ['TTL' => self::TTL, 'urgency' => 'high'],
                15   // timeout en segundos
            );

            $contenido = json_encode($mensaje, JSON_UNESCAPED_UNICODE);

            foreach ($suscripciones as $suscripcion) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint'        => $suscripcion->endpoint,
                        'publicKey'       => $suscripcion->p256dh,
                        'authToken'       => $suscripcion->auth,
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    $contenido
                );
            }

            foreach ($webPush->flush() as $reporte) {
                // El usuario desinstaló la app o quitó el permiso: se borra.
                if ($reporte->isSubscriptionExpired()) {
                    PushSuscripcion::where('endpoint', $reporte->getEndpoint())->delete();
                    continue;
                }

                if (! $reporte->isSuccess()) {
                    Log::warning('Push: no se pudo enviar. ' . $reporte->getReason());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Push: error al enviar. ' . $e->getMessage());
        }
    }
}