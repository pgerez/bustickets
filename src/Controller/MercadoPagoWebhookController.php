<?php

namespace App\Controller;

use App\Entity\Boleto;
use App\Entity\Reserva;
use App\Entity\User;
use App\Entity\Pasajero;
use App\Notifier\CustomLoginLinkNotification;
use App\Notifier\TicketConfirmationNotification;
use Doctrine\ORM\EntityManagerInterface;
use MercadoPago\Client\MercadoPagoClient;
use MercadoPago\Client\Preference\PreferenceClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Routing\Attribute\Route;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use Symfony\Component\Webhook\Client\RequestParser;
use Symfony\Component\HttpFoundation\Request;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

class MercadoPagoWebhookController extends AbstractController
{

    #[Route('/webhook/mercadopago', name: 'mercadopago_webhook', methods: ['POST'])]
    public function webhook(Request $request, NotifierInterface $notifier, EntityManagerInterface $entityManager, LoggerInterface $logger): Response
    {
        // 1. Obtener los valores de los headers X-Signature y X-Request-Id
        $xSignature = $request->headers->get('X-Signature');
        $xRequestId = $request->headers->get('X-Request-Id');

        // Validar que los headers existen
        if (null === $xSignature || null === $xRequestId) {
            $logger->warning('Webhook de Mercado Pago recibido sin X-Signature o X-Request-Id.', [
                'xSignature' => $xSignature,
                'xRequestId' => $xRequestId,
            ]);
            return new Response('Headers faltantes.', Response::HTTP_BAD_REQUEST);
        }

        // Obtener data_id y type desde los query parameters o el cuerpo JSON POST
        $dataId = $request->query->get('data_id', '');
        $notificationType = $request->query->get('type', '');

        if (empty($dataId)) {
            $content = json_decode($request->getContent(), true);
            if (is_array($content)) {
                $dataId = $content['data']['id'] ?? '';
                $notificationType = $content['type'] ?? '';
            }
        }

        $dataId = (string)$dataId;
        $notificationType = (string)$notificationType;

        // 3. Separar la x-signature en partes
        $parts = explode(',', $xSignature);

        // Inicializando variables para almacenar ts y hash
        $ts = null;
        $hash = null;

        // Iterar sobre los valores para obtener ts y v1
        foreach ($parts as $part) {
            $keyValue = explode('=', $part, 2);
            if (count($keyValue) == 2) {
                $key = trim($keyValue[0]);
                $value = trim($keyValue[1]);
                if ($key === "ts") {
                    $ts = $value;
                } elseif ($key === "v1") {
                    $hash = $value;
                }
            }
        }

        // Validar que ts y hash fueron extraídos
        if (null === $ts || null === $hash) {
            $logger->warning('X-Signature de Mercado Pago malformado.', [
                'xSignature' => $xSignature,
                'parts' => $parts,
            ]);
            return new Response('X-Signature malformado.', Response::HTTP_BAD_REQUEST);
        }

        // 4. Obtener la clave secreta
        $secret = $_ENV['WEBHOOK_SECRET'];

        if (null === $secret || empty($secret)) {
            $logger->error('La clave secreta de Mercado Pago no está configurada.');
            return new Response('Error interno del servidor: clave secreta no configurada.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 5. Generar la cadena manifest
        $manifest = "id:" . strtolower($dataId) . ";request-id:$xRequestId;ts:$ts;";

        // 6. Crear una firma HMAC
        $sha = hash_hmac('sha256', $manifest, $secret);

        if (hash_equals($sha, $hash)) {
            // Verificación HMAC aprobada
            $logger->info('Verificación HMAC de Mercado exitosa para data.id: ' . $dataId);
            $accesst = $_ENV['ENV_ACCESS_TOKEN'];
            MercadoPagoConfig::setAccessToken($accesst);

            switch($notificationType) {
                case "payment":
                    $payment = new PaymentClient();
                    
                    // LLAMADA ÚNICA A LA API DE MERCADO PAGO
                    $paymentData = $payment->get($dataId);
                    $status = $paymentData->status;
                    $externalReference = $paymentData->external_reference;
                    $paymentMethodId = $paymentData->payment_method_id;
                    $transactionAmount = $paymentData->transaction_amount;

                    $logger->info('STATUS: ' . $status . ' external_refe: ' . $externalReference);
                    
                    $parts = explode('_', (string)$externalReference);
                    if (count($parts) >= 4) {
                        $idReserva = (int)$parts[1];
                        $idUsuario = (int)$parts[3];
                    } else {
                        $logger->error('Formato de external_reference inválido: ' . $externalReference);
                        return new Response('Referencia externa inválida.', Response::HTTP_BAD_REQUEST);
                    }

                    $ticketData = [];
                    if ($status === 'approved' || $status === 'authorized') {
                        $reserva = $entityManager->getRepository(Reserva::class)->find($idReserva);
                        if (!$reserva) {
                            $logger->error('Reserva no encontrada con ID: ' . $idReserva);
                            return new Response('Reserva no encontrada.', Response::HTTP_NOT_FOUND);
                        }

                        // Si la reserva ya fue aprobada (ej: manualmente en backend o por webhook previo)
                        if ($reserva->getEstado() === Reserva::STATE_COMPLETED) {
                            $logger->info("Webhook MP: Reserva {$idReserva} ya se encuentra aprobada/completada. No se requiere acción.");
                            return new Response('Webhook recibido. La reserva ya estaba aprobada.', Response::HTTP_OK);
                        }

                        // Método de pago
                        switch ($paymentMethodId) {
                            case 'visa':
                                $friendlyPaymentMethod = 'Visa';
                                break;
                            case 'master':
                                $friendlyPaymentMethod = 'Mastercard';
                                break;
                            case 'amex':
                                $friendlyPaymentMethod = 'American Express';
                                break;
                            case 'naranja':
                                $friendlyPaymentMethod = 'Naranja X';
                                break;
                            case 'account_money':
                                $friendlyPaymentMethod = 'Dinero en cuenta de Mercado Pago';
                                break;
                            case 'rapipago':
                                $friendlyPaymentMethod = 'Efectivo (Rapipago)';
                                break;
                            case 'pagofacil':
                                $friendlyPaymentMethod = 'Efectivo (Pago Fácil)';
                                break;
                            default:
                                $friendlyPaymentMethod = ucfirst(str_replace('_', ' ', (string)$paymentMethodId));
                                break;
                        }

                        // Actualizar información del Pago asociado
                        $pago = $reserva->getPagos()->last();
                        if ($pago) {
                            $pago->setNumeroComprobante($dataId);
                            $pago->setObservacion($friendlyPaymentMethod);
                            $entityManager->persist($pago);
                        }

                        // Cambiar estado a reserva y agregar payment_id
                        $reserva->setPaymentId($dataId);
                        $reserva->setEstado(Reserva::STATE_COMPLETED);
                        $entityManager->persist($reserva);

                        // Cambiar estado a boletos
                        foreach ($reserva->getBoletos() as $boleto) {
                            $boleto->setEstado(Boleto::STATE_RESERVED);
                            $entityManager->persist($boleto);
                            $ticketData[] = [
                                'seat_number'    => $boleto->getAsiento(),
                                'seat_passenger' => $boleto->getPasajero()->getApellido().', '.$boleto->getPasajero()->getNombre(),
                                'seat_dni'       => $boleto->getPasajero()->getDni(),
                                'download_link'  => 'https://tuempresa.com/pasajes/descargar/ABCDE12345.pdf', // **GENERAR LINK SEGURO**
                            ];
                        }
                        $entityManager->flush();

                        // Email notificación
                        $usuario = $entityManager->getRepository(User::class)->find($idUsuario);
                        $buyerEmail = $usuario ? $usuario->getEmail() : '';
                        $buyerName = $buyerEmail;

                        if ($usuario && $usuario->getDni()) {
                            $pasajeroPrincipal = $entityManager->getRepository(Pasajero::class)->findOneBy(['dni' => $usuario->getDni()]);
                            if ($pasajeroPrincipal) {
                                $buyerName = $pasajeroPrincipal->getNombre() . ' ' . $pasajeroPrincipal->getApellido();
                            } else {
                                $buyerName = $usuario->getUsername();
                            }
                        } else if ($usuario) {
                            $buyerName = $usuario->getUsername();
                        }

                        $buyerIdNumber = $dataId;

                        $tripData = [
                            'origin' => $reserva->getOrigen(),
                            'destination' => $reserva->getDestino(),
                            'departure_date' => $reserva->getServicio()->getPartida()->format('d-m-Y H:i'),
                            'departure_time' => $reserva->getServicio()->getLlegada()->format('d-m-Y H:i'),
                            'company' => 'SantiagueñoBus',
                            'service_type' => 'Servicio Comun',
                            'total_cost' => $reserva->getMontoTotal(),
                        ];

                        $paymentInfo = [
                            'amount' => $transactionAmount,
                            'method' => $friendlyPaymentMethod,
                            'transaction_id' => $dataId,
                        ];

                        $notification = new TicketConfirmationNotification(
                            $buyerName,
                            $buyerIdNumber,
                            $tripData,
                            $ticketData,
                            $paymentInfo,
                            $buyerEmail
                        );

                        $notifier->send($notification, new Recipient($buyerEmail));
                        $logger->info("Correo de confirmación enviado para el pasaje de {$buyerName} (ID MP: {$dataId})");
                        return new Response('Webhook procesado con éxito.', Response::HTTP_OK);
                    }
                    break;
            }
            return new Response('Webhook recibido pero no requirió acción.', Response::HTTP_OK);
        } else {
            // Verificación HMAC fallida
            $logger->warning('Fallo en la verificación HMAC de Mercado Pago.', [
                'data_id' => $dataId,
                'x_request_id' => $xRequestId,
                'ts' => $ts,
                'expected_hash' => $sha,
                'received_hash' => $hash,
                'manifest' => $manifest,
            ]);
            return new Response('Fallo en la verificación de firma.', Response::HTTP_UNAUTHORIZED);
        }
    }

    #[Route('/mercadopago/backurl', name: 'mercadopago_backurl', methods: ['GET'])]
    public function backUrl(Request $request, EntityManagerInterface $entityManager, LoggerInterface $logger): Response
    {
        // Loggear todos los parámetros recibidos para depuración
        $queryParams = $request->query->all();
        $logger->info('Mercado Pago Return URL recibida.', $queryParams);

        // Obtener los parámetros relevantes
        $collectionId = $request->query->get('collection_id');
        $collectionStatus = $request->query->get('collection_status');
        $paymentId = $request->query->get('payment_id'); // A menudo es lo mismo que collection_id
        $status = $request->query->get('status'); // Estado general
        $externalReference = $request->query->get('external_reference'); // Tu referencia externa si la enviaste
        $preferenceId = $request->query->get('preference_id');
        $paymentType = $request->query->get('payment_type');

        $effectiveStatus = $collectionStatus ?: $status;
        $effectivePaymentId = $paymentId ?: $collectionId;

        $reservaRepo = $entityManager->getRepository(Reserva::class);
        $reserva = null;
        if ($preferenceId) {
            $reserva = $reservaRepo->findOneBy(['preference_id' => $preferenceId]);
        }
        if (!$reserva && $externalReference) {
            $parts = explode('_', (string)$externalReference);
            if (count($parts) >= 2 && $parts[0] === 'reserva') {
                $idReserva = (int)$parts[1];
                $reserva = $reservaRepo->find($idReserva);
            }
        }

        $b = '';
        if ($reserva) {
            foreach ($reserva->getBoletos() as $boleto) {
                $asientoObj = $boleto->getAsiento();
                $asientoNum = $asientoObj ? $asientoObj->getNumero() : '';
                $b .= $asientoNum . '<br>';
            }

            if ($effectiveStatus === 'approved' || $effectiveStatus === 'authorized') {
                if ($reserva->getEstado() !== Reserva::STATE_COMPLETED) {
                    $reserva->setEstado(Reserva::STATE_COMPLETED);
                    if ($effectivePaymentId) {
                        $reserva->setPaymentId((string)$effectivePaymentId);
                    }

                    $pago = $reserva->getPagos()->last();
                    if ($pago) {
                        if ($effectivePaymentId) {
                            $pago->setNumeroComprobante((string)$effectivePaymentId);
                        }
                        if ($paymentType) {
                            $pago->setObservacion((string)$paymentType);
                        }
                        $entityManager->persist($pago);
                    }

                    foreach ($reserva->getBoletos() as $boleto) {
                        $boleto->setEstado(Boleto::STATE_RESERVED);
                        $entityManager->persist($boleto);
                    }

                    $entityManager->persist($reserva);
                    $entityManager->flush();
                    $logger->info("MercadoPago backUrl: Reserva {$reserva->getId()} confirmada y boletos actualizados a STATE_RESERVED.");
                }
            }
        }

        // Lógica de tu aplicación basada en el estado del pago
        $message = '';
        switch ($effectiveStatus) {
            case 'approved':
            case 'authorized':
                $message = '¡Tu pago ha sido aprobado! ID de la transacción: ' . ($effectivePaymentId ?? 'N/A');
                break;
            case 'rejected':
                $message = 'Tu pago fue rechazado. Intenta de nuevo o prueba con otro medio de pago.';
                break;
            case 'pending':
            case 'in_process':
                $message = 'Tu pago está pendiente. Esperando confirmación.';
                break;
            default:
                $message = 'Estado de pago desconocido o no especificado.';
                break;
        }

        $logger->info('Lógica de retorno de Mercado Pago ejecutada.', [
            'collection_status' => $effectiveStatus,
            'external_reference' => $externalReference,
            'message_to_user' => $message,
            'boletos' => $b,
        ]);

        return $this->render('ReservaAdmin/pasajero_summary.html.twig', [
            'mensaje' => $message,
            'collectionId' => $effectivePaymentId,
            'externalReference' => $externalReference,
            'boletos' => $b,
            'reserva' => $reserva
        ]);
    }

    #[Route('/mercadopago/return', name: 'mercadopago_return', methods: ['GET'])]
    public function returnUrl(Request $request, EntityManagerInterface $entityManager, LoggerInterface $logger): Response
    {
        // Loggear todos los parámetros recibidos para depuración
        $queryParams = $request->query->all();
        $logger->info('Mercado Pago Return URL recibida.', $queryParams);

        // Obtener los parámetros relevantes
        $collectionId = $request->query->get('collection_id');
        $collectionStatus = $request->query->get('collection_status');
        $paymentId = $request->query->get('payment_id');
        $status = $request->query->get('status');
        $externalReference = $request->query->get('external_reference');
        $preferenceId = $request->query->get('preference_id');
        $paymentType = $request->query->get('payment_type');

        $effectiveStatus = $collectionStatus ?: $status;
        $effectivePaymentId = $paymentId ?: $collectionId;

        $reservaRepo = $entityManager->getRepository(Reserva::class);
        $reserva = null;
        if ($preferenceId) {
            $reserva = $reservaRepo->findOneBy(['preference_id' => $preferenceId]);
        }
        if (!$reserva && $externalReference) {
            $parts = explode('_', (string)$externalReference);
            if (count($parts) >= 2 && $parts[0] === 'reserva') {
                $idReserva = (int)$parts[1];
                $reserva = $reservaRepo->find($idReserva);
            }
        }

        if ($reserva && ($effectiveStatus === 'approved' || $effectiveStatus === 'authorized')) {
            if ($reserva->getEstado() !== Reserva::STATE_COMPLETED) {
                $reserva->setEstado(Reserva::STATE_COMPLETED);
                if ($effectivePaymentId) {
                    $reserva->setPaymentId((string)$effectivePaymentId);
                }

                $pago = $reserva->getPagos()->last();
                if ($pago) {
                    if ($effectivePaymentId) {
                        $pago->setNumeroComprobante((string)$effectivePaymentId);
                    }
                    if ($paymentType) {
                        $pago->setObservacion((string)$paymentType);
                    }
                    $entityManager->persist($pago);
                }

                foreach ($reserva->getBoletos() as $boleto) {
                    $boleto->setEstado(Boleto::STATE_RESERVED);
                    $entityManager->persist($boleto);
                }

                $entityManager->persist($reserva);
                $entityManager->flush();
                $logger->info("MercadoPago returnUrl: Reserva {$reserva->getId()} confirmada y boletos actualizados a STATE_RESERVED.");
            }
        }

        $message = '';
        switch ($effectiveStatus) {
            case 'approved':
            case 'authorized':
                $message = '¡Tu pago ha sido aprobado! ID de la transacción: ' . ($effectivePaymentId ?? 'N/A');
                break;
            case 'rejected':
                $message = 'Tu pago fue rechazado. Intenta de nuevo o prueba with otro medio de pago.';
                break;
            case 'pending':
            case 'in_process':
                $message = 'Tu pago está pendiente. Esperando confirmación.';
                break;
            default:
                $message = 'Estado de pago desconocido o no especificado.';
                break;
        }

        $logger->info('Lógica de retorno de Mercado Pago ejecutada.', [
            'collection_status' => $effectiveStatus,
            'external_reference' => $externalReference,
            'message_to_user' => $message,
        ]);

        return new Response(
            sprintf(
                '<html><body><h1>Estado de tu pago</h1><p>%s</p><p>ID de la colección: %s</p><p>Referencia Externa: %s</p><p>Puedes regresar a la página principal haciendo clic <a href="/">aquí</a>.</p></body></html>',
                $message,
                $effectivePaymentId ?? 'N/A',
                $externalReference ?? 'N/A'
            )
        );
    }
}
