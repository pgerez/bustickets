<?php
namespace App\Twig;

use App\Repository\PasajeroRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class EspDateExtension extends AbstractExtension
{
    private PasajeroRepository $pasajeroRepository;

    public function __construct(PasajeroRepository $pasajeroRepository)
    {
        $this->pasajeroRepository = $pasajeroRepository;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('esp_date', [$this, 'formatEspDate']),
            new TwigFilter('comprador_nombre', [$this, 'getCompradorNombre']),
        ];
    }

    public function formatEspDate(\DateTimeInterface $date, string $format = '%a %d %b'): string
    {
        setlocale(LC_TIME, 'es_AR.utf8');
        // Usa strftime con el formato en estilo PHP:
        return strftime($format, $date->getTimestamp());
    }

    public function getCompradorNombre(?\App\Entity\User $user): string
    {
        if (!$user) {
            return 'Sin comprador';
        }
        if (!$user->getDni()) {
            return $user->getUsername() ?: $user->getEmail() ?: '';
        }
        $pasajero = $this->pasajeroRepository->findOneByDni($user->getDni());
        if ($pasajero) {
            return $pasajero->getNombre() . ' ' . $pasajero->getApellido();
        }
        return $user->getUsername() ?: $user->getEmail() ?: '';
    }
}