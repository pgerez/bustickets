<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sonata\AdminBundle\Controller\CRUDController;
use Doctrine\ORM\EntityManagerInterface;


final class BoletoAdminController extends CRUDController
{
    public function asignarliberarasientoAction(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response
    {
        $boleto = $this->admin->getSubject();
        if ($boleto->getEstado() == 2) {
            $boleto->setEstado(1);
        } elseif ($boleto->getEstado() == 1) {
            $boleto->setEstado(2);
        }

        $entityManager->persist($boleto);
        $entityManager->flush();

        if ($request->isXmlHttpRequest() || $request->query->get('ajax')) {
            return $this->json([
                'success' => true,
                'nuevoEstado' => $boleto->getEstado(),
                'estadoTexto' => $boleto->getEstadoTexto(),
                'asiento' => $boleto->getAsiento() ? $boleto->getAsiento()->getNumero() : '-'
            ]);
        }

        return $this->redirectToRoute('admin_app_servicio_boleto_list', ['id' => $boleto->getServicio()->getId()]);
    }
}
