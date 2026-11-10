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
     * @return array ['dispositivos' => n, 'enviadas' => n, 'errores' => [...]]
     */
    public function enviarAUsuario(int $usuarioId, array $mensaje): array
    {
        $resultado = ['dispositivos' => 0, 'enviadas' => 0, 'errores' => []];

        $suscripciones = PushSuscripcion::query()
            ->where('usuarios_id', $usuarioId)
            ->get();

        $resultado['dispositivos'] = $suscripciones->count();

        if ($suscripciones->isEmpty()) {
            return $resultado;
        }

        $config = config('services.webpush');

        if (empty($config['public_key']) || empty($config['private_key'])) {
            Log::warning('Push: faltan VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY en el .env.');
            $resultado['errores'][] = 'Faltan VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY.';
            return $resultado;
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
                ['TTL' => self::TTL, 'urgency' => 'high']
                // (en la versión 10 de la librería el 3er parámetro ya no es el
                //  timeout, sino un cliente HTTP; se deja el de por defecto)
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
                if ($reporte->isSuccess()) {
                    $resultado['enviadas']++;
                    continue;
                }

                if ($reporte->isSubscriptionExpired()) {
                    PushSuscripcion::where('endpoint', $reporte->getEndpoint())->delete();
                    $resultado['errores'][] = 'Suscripción vencida (se borró).';
                    continue;
                }

                Log::warning('Push: no se pudo enviar. ' . $reporte->getReason());
                $resultado['errores'][] = $reporte->getReason();
            }
        } catch (\Throwable $e) {
            Log::warning('Push: error al enviar. ' . $e->getMessage());
            $resultado['errores'][] = $e->getMessage();
        }

        return $resultado;
    }
}
