<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Boleto;
use App\Entity\Pago;
use App\Entity\Pasajero;
use App\Entity\Reserva;
use App\Entity\TransporteAsiento;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sonata\AdminBundle\Controller\CRUDController;
use App\Entity\User;
use App\Notifier\TicketConfirmationNotification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

final class ReservaAdminController extends CRUDController
{

    protected function redirectTo(Request $request, object $object): RedirectResponse
    {
        if (null !== $request->get('btn_finalize')) {
            $route = 'show';
            $url = $this->admin->generateObjectUrl(
                $route,
                $object,
                $this->getSelectedTab($request)
            );

            return new RedirectResponse($url);
        }

        return parent::redirectTo($request, $object);
    }

    public function pagarAction(Request $request, EntityManagerInterface $entityManager): Response
    {
        $reserva_id = $request->get('id');
        $reserva = $entityManager->getRepository(Reserva::class)->find($reserva_id);


        foreach ($reserva->getBoletos() as $boleto):
            if($boleto->getEstado() != Boleto::STATE_RESERVED_WAIT):
                $boletoWait = $entityManager->getRepository(Boleto::class)->findOneBy(
                    ['servicio' => $boleto->getServicio(), 'asiento' => $boleto->getAsiento(), 'estado' => boleto::STATE_RESERVED_WAIT]
                );

                if ($boletoWait) {
                    $reserva->setEstado(Reserva::STATE_DRAFT);
                    $reserva->removeBoleto($boleto);
                    $entityManager->persist($reserva);
                    $entityManager->flush();
                    $this->addFlash('danger', 'El asiento: '.$boleto->getAsiento()->getNumero().' ya no esta disponible');
                    return $this->redirectToRoute('admin_app_reserva_edit',['id' => $reserva->getId()]);
                }

                // Cambiar estado a reservado
                $boleto->setEstado(boleto::STATE_RESERVED_WAIT);
                $boleto->setUpdateAt(new \DateTimeImmutable());
                $entityManager->persist($boleto);
                $entityManager->flush();
            endif;
        endforeach;
        $mercadopagourl =
            $this->admin->getSubject()->getUrlpago();

        return $this->redirect($mercadopagourl);
    }

    public function modalFormAction(Request $request): Response
    {
        $data1      = $request->getContent();
        $data       = json_decode($data1);
        $idasiento = $data->idasiento;
        $idreserva = $data->idreserva;
        $numeroasiento = $data->asientonumero;
        $search = $this->generateUrl(
            'admin_app_pasajero_searchForDni',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $url = $this->generateUrl(
            'admin_app_reserva_addBoleto',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $html = '<form action="'.$url.'" method="post" enctype="multipart/form-data">
                    <div class="panel box box-primary" style="margin-bottom: 0px;">
                                                <div class="box-header">
                                                    <h4 class="box-title">
                                                        <strong>Datos del Pasajero para el asiento '.$numeroasiento.'</strong>
                                                    </h4>
                                                </div>
                                                 <div class="box-body">
                                                        <div class="form-group" style="text-align: left">
                                                            <label style="text-align: left" for="dni" class="control-label required">DNI</label>
                                                            <div class="sonata-ba-field-container">
                                                                <input placeholder="Dni" type="number" id="dni" name="dni" required="required" class="form-control" onblur="searchPasajero(this.value)"/>
                                                                <input type="hidden" id="idasiento" name="idasiento"  value="'.$idasiento.'" class="form-control" />
                                                                <input type="hidden" id="idreserva" name="idreserva"  value="'.$idreserva.'" class="form-control" />
                                                            </div>
                                                        </div>
                                                        <div class="form-group" style="text-align: left">
                                                            <label style="text-align: left" for="apellido" class="control-label required">Apellido</label>
                                                            <div class="sonata-ba-field-container">
                                                                <input placeholder="Apellido" type="text" id="apellido" name="apellido" required="required" class="form-control" />
                                                            </div>
                                                        </div>
                                                        <div class="form-group" style="text-align: left">
                                                            <label style="text-align: left" for="nombre" class="control-label required">Nombre</label>
                                                            <div class="sonata-ba-field-container">
                                                                <input placeholder="Nombre" type="text" id="nombre" name="nombre" required="required" class="form-control" />
                                                            </div>
                                                        </div>

                                                        <div class="form-group" style="text-align: left">
                                                            <label style="text-align: left" for="sexo" class="control-label required">Sexo</label>
                                                            <div  class="custom-select">
                                                                <select class="form-control" id="sexo" name="sexo" required="required">
                                                                    <option value="">Seleccione...</option>
                                                                    <option value="M">Masculino</option>
                                                                    <option value="F">Femenino</option>
                                                                    <option value="O">Otro</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
                                                <button type="submit" class="btn btn-primary">Guardar Datos</button>
                                            </div>
                    </form>';

        $html .= <<<EOF
                    <script type="text/javascript">
                            $('#sexo').select2();
                            function searchPasajero(dni) {
                                $.ajax({
                                    url: '{$search}',
                                    method: 'POST',
                                    processData: false,
                                    contentType: "application/json; charset=utf-8",
                                    data: JSON.stringify({ "dni": dni}),
                                    dataType: "json",
                                    success: function (data) {
                                        console.log('AJAX Success:', data);
                                        $('#apellido').val(data.apellido);
                                        $('#nombre').val(data.nombre);
                                        $('#sexo').val(data.sexo).select2();
                                        
                                    }
                                });
                            }
                        </script>
                     
EOF;


        return new JsonResponse($html);
    }
    public function addBoletoAction(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $apellido = $request->get('apellido');
        $nombre = $request->get('nombre');
        $dni = $request->get('dni');
        $sexo = $request->get('sexo');
        $idasiento = $request->get('idasiento');
        $idreserva = $request->get('idreserva');
        ######guardar boleto
        // Esto se ejecuta ante de guardar el formulario
        $asientoRepo = $entityManager->getRepository(TransporteAsiento::class);
        $reservaRepo = $entityManager->getRepository(Reserva::class);

        $reserva = $reservaRepo->find($idreserva);
        $servicio = $reserva->getServicio();
        #$transporte = $servicio->getTransporte();
        $trayecto = $servicio->getTrayecto();
        $asiento = $asientoRepo->find($idasiento);

        $pasajero_exist = $entityManager->getRepository(Pasajero::class)->findOneBy(['dni' => $dni]);
        if($pasajero_exist):
            $pasajero = $pasajero_exist;
            $pasajero->setDni((int)$dni)
                    ->setApellido($apellido)
                    ->setNombre($nombre)
                    ->setSexo($sexo);
        else:
            $pasajero = new Pasajero();
            $pasajero->setDni((int)$dni)
                    ->setApellido($apellido)
                    ->setNombre($nombre)
                    ->setSexo($sexo);
        endif;
        $entityManager->persist($pasajero);
        # agregar boleto
        $boleto = new Boleto();
        $boleto->setServicio($servicio)
                ->setAsiento($asiento)
                ->setOrigen($trayecto->getOrigen())
                ->setDestino($trayecto->getDestino())
                ->setReserva($reserva)
                ->setPasajero($pasajero)
                ->setEstado(Boleto::STATE_DRAFT)
        ;
        $entityManager->persist($boleto);
        $reserva->addBoleto($boleto);
        # agregar pago
        $has_boletos = $reserva->getBoletos()->count() > 0;
        $has_pagos = $reserva->getPagos()->count() > 0;
        if($has_boletos && !$has_pagos) {
            $pago = new Pago();
            $pago->setTipo(Pago::PAYMENT_TYPE_MERCADOPAGO);
            $total = $reserva->calcularMontoTotal();
            $porcentaje = $total*0.1;
            $pago->setMonto($total);
            $pago->setUser($user);
            $pago->setImporteRecibido((int)$porcentaje);
            $reserva->addPago($pago);
            $entityManager->persist($pago);
        }
        $entityManager->flush();

        $this->addFlash('success', 'Pasajero Registado con exito en el asiento '.$asiento->getNumero().'!');
        return $this->redirectToRoute('admin_app_reserva_edit',['id' => $idreserva]);
    }

    public function aprobarAction(
        Request $request,
        EntityManagerInterface $entityManager,
        NotifierInterface $notifier,
        LoggerInterface $logger
    ): Response {
        $id = $request->get($this->admin->getIdParameter());
        $reserva = $this->admin->getObject($id);

        if (!$reserva instanceof Reserva) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        if ($request->isMethod('POST')) {
            $paymentId = $request->request->get('payment_id');
            if (empty($paymentId)) {
                $this->addFlash('sonata_flash_error', 'Debe ingresar el ID de pago de Mercado Pago.');
                return $this->renderWithExtraParams('ReservaAdmin/aprobar.html.twig', [
                    'action' => 'aprobar',
                    'object' => $reserva,
                ]);
            }

            $entityManager->beginTransaction();
            try {
                // Update associated Pago
                $pago = $reserva->getPagos()->last();
                $friendlyPaymentMethod = 'Aprobado manualmente en Backend';
                if ($pago) {
                    $pago->setNumeroComprobante($paymentId);
                    $pago->setObservacion($friendlyPaymentMethod);
                    $entityManager->persist($pago);
                }

                // Update Reserva state & payment_id
                $reserva->setPaymentId($paymentId);
                $reserva->setEstado(Reserva::STATE_COMPLETED);
                $entityManager->persist($reserva);

                // Update Boleto statuses & build ticketData
                $ticketData = [];
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
                $entityManager->commit();

                // Send Confirmation Email
                $usuario = $reserva->getUser();
                if ($usuario) {
                    $buyerEmail = $usuario->getEmail();
                    $buyerName = $buyerEmail;

                    if ($usuario->getDni()) {
                        $pasajeroPrincipal = $entityManager->getRepository(Pasajero::class)->findOneBy(['dni' => $usuario->getDni()]);
                        if ($pasajeroPrincipal) {
                            $buyerName = $pasajeroPrincipal->getNombre() . ' ' . $pasajeroPrincipal->getApellido();
                        } else {
                            $buyerName = $usuario->getUsername();
                        }
                    } else {
                        $buyerName = $usuario->getUsername();
                    }

                    $tripData = [
                        'origin' => $reserva->getOrigen(),
                        'destination' => $reserva->getDestino(),
                        'departure_date' => $reserva->getServicio()->getPartida()->format('d-m-Y H:i'),
                        'departure_time' => $reserva->getServicio()->getLlegada()->format('d-m-Y H:i'),
                        'company' => 'SantiagueñoBus',
                        'service_type' => 'Servicio Comun',
                    ];

                    $transactionAmount = $pago ? $pago->getImporteRecibido() : ($reserva->calcularMontoTotal() * 0.1);

                    $paymentInfo = [
                        'amount' => $transactionAmount,
                        'method' => $friendlyPaymentMethod,
                        'transaction_id' => $paymentId,
                    ];

                    $notification = new TicketConfirmationNotification(
                        $buyerName,
                        $paymentId,
                        $tripData,
                        $ticketData,
                        $paymentInfo,
                        $buyerEmail
                    );

                    $notifier->send($notification, new Recipient($buyerEmail));
                    $logger->info("Manual approval: confirmation email sent to {$buyerName} (ID MP: {$paymentId})");
                }

                $this->addFlash('sonata_flash_success', sprintf('Reserva %s aprobada con éxito. Los asientos han sido reservados y se envió el email de confirmación.', $reserva->getId()));
                return $this->redirectToList();
            } catch (\Exception $e) {
                $entityManager->rollback();
                $logger->error('Error en aprobación manual de reserva: ' . $e->getMessage());
                $this->addFlash('sonata_flash_error', 'Ocurrió un error al procesar la aprobación: ' . $e->getMessage());
            }
        }

        return $this->renderWithExtraParams('ReservaAdmin/aprobar.html.twig', [
            'action' => 'aprobar',
            'object' => $reserva,
        ]);
    }

}
