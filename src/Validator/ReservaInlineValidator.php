<?php
declare(strict_types=1);

namespace App\Validator;

use Sonata\Form\Validator\ErrorElement;
use Doctrine\ORM\EntityManagerInterface;

use App\Entity\Reserva;
use App\Entity\Pago;


class ReservaInlineValidator
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function validate(ErrorElement $errorElement, Reserva $reserva)
    {
        # Validar pasajero seleccionado
        $idx = 0;
        foreach($reserva->getBoletos() as $boleto) {
            $pasajero = $boleto->getPasajero();

            if(null == $pasajero) {
                $msg = 'Seleccione pasajero';
                $errorElement->with('boletos['.$idx.'].pasajero')->addViolation($msg)->end();
            }
            $idx += 1;
        }

        # Validar que un pasajero solo ocupe un asiento en el servicio actual
        $servicio = $reserva->getServicio();
        if ($servicio) {
            $pasajerosDniMap = [];
            foreach ($reserva->getBoletos() as $i => $boleto) {
                $pasajero = $boleto->getPasajero();
                if (!$pasajero || !$pasajero->getDni()) {
                    continue;
                }
                $dni = $pasajero->getDni();

                // Verificar duplicados dentro de la misma reserva
                if (isset($pasajerosDniMap[$dni])) {
                    $msg = sprintf('El pasajero con DNI %s no puede ocupar más de un asiento en el mismo servicio.', $dni);
                    $errorElement->with('boletos[' . $i . '].pasajero')->addViolation($msg)->end();
                } else {
                    $pasajerosDniMap[$dni] = true;
                }

                // Verificar si el pasajero ya tiene otro boleto en este servicio (en otra reserva u otro boleto)
                $qb = $this->entityManager->getRepository(\App\Entity\Boleto::class)->createQueryBuilder('b')
                    ->join('b.pasajero', 'p')
                    ->where('b.servicio = :servicio')
                    ->andWhere('p.dni = :dni')
                    ->andWhere('b.estado != :estadoCancelado')
                    ->setParameter('servicio', $servicio)
                    ->setParameter('dni', $dni)
                    ->setParameter('estadoCancelado', \App\Entity\Boleto::STATE_CANCELED);

                if ($boleto->getId()) {
                    $qb->andWhere('b.id != :boletoId')
                       ->setParameter('boletoId', $boleto->getId());
                }

                $existingBoleto = $qb->getQuery()->getOneOrNullResult();
                if ($existingBoleto) {
                    $numAsiento = $existingBoleto->getAsiento() ? $existingBoleto->getAsiento()->getNumero() : '';
                    $msg = sprintf('El pasajero con DNI %s ya tiene asignado el Asiento %s en este servicio.', $dni, $numAsiento);
                    $errorElement->with('boletos[' . $i . '].pasajero')->addViolation($msg)->end();
                }
            }
        }

        # Validar Pago
        #if(Reserva::STATE_PENDING_PAYMENT == $reserva->getEstado()) {
        #    $idx = 0;
        #    foreach($reserva->getPagos() as $pago) {
        #        $pago_indefinido = $pago->getTipo() == Pago::PAYMENT_TYPE_UNSPECIFIED;
        #        if($pago_indefinido) {
        #            $msg = 'Seleccione Tipo de Pago';
        #            $errorElement->with('pagos['.$idx.'].tipo')->addViolation($msg)->end();
        #        }

         #       $total = $pago->getMonto();
         #       $recibido = $pago->getImporteRecibido();
         #       if($recibido < $total) {
         #           $msg = 'Importe recibido debe ser mayor o igual al total.';
         #           $errorElement->with('pagos['.$idx.'].importeRecibido')->addViolation($msg)->end();
         #       }

         #       $idx += 1;
         #   }

        #}
    }
}
