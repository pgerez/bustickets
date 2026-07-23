<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Boleto;
use App\Entity\Pasajero;
use Doctrine\ORM\EntityManagerInterface;
use Sonata\AdminBundle\Controller\CRUDController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PasajeroAdminController extends CRUDController
{

    public function searchForDniAction(Request $request, EntityManagerInterface $entityManager): Response
    {
        $data1      = $request->getContent();
        $data       = json_decode($data1, false);
        $dni        = $data->dni ?? null;
        $idservicio = $data->idservicio ?? null;

        $pasajero = $dni ? $entityManager->getRepository(Pasajero::class)->findOneBy(['dni' => $dni]) : null;
        $responseData = null;

        if ($pasajero) {
            $responseData = [
                'apellido' => $pasajero->getApellido(),
                'nombre'   => $pasajero->getNombre(),
                'sexo'     => $pasajero->getSexo(),
                'yaTieneAsiento' => false,
            ];
        } else {
            $responseData = [
                'yaTieneAsiento' => false,
            ];
        }

        if ($dni && $idservicio) {
            $existBoleto = $entityManager->getRepository(Boleto::class)->createQueryBuilder('b')
                ->join('b.pasajero', 'p')
                ->where('b.servicio = :servicioId')
                ->andWhere('p.dni = :dni')
                ->andWhere('b.estado != :estadoCancelado')
                ->setParameter('servicioId', $idservicio)
                ->setParameter('dni', (int)$dni)
                ->setParameter('estadoCancelado', Boleto::STATE_CANCELED)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existBoleto && $existBoleto->getAsiento()) {
                $responseData['yaTieneAsiento'] = true;
                $responseData['asientoNumero'] = $existBoleto->getAsiento()->getNumero();
            }
        }

        return new JsonResponse($responseData);
    }
}
