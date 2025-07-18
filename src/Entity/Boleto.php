<?php

namespace App\Entity;

use App\Repository\BoletoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BoletoRepository::class)]
class Boleto
{
    public const STATE_DRAFT = 0;
    public const STATE_RESERVED = 1;
    public const STATE_RESERVED_TAKEN = 2;
    public const STATE_RESERVED_WAIT = 3;
    public const STATE_CANCELED = 4;

    public static $estados = [
        self::STATE_DRAFT => 'Nuevo',
        self::STATE_RESERVED => 'Reservado',
        self::STATE_RESERVED_TAKEN => 'Reserva Tomada',
        self::STATE_RESERVED_WAIT => 'Reserva en espera',
        self::STATE_CANCELED => 'Cancelado',

    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Parada $origen = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Parada $destino = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $viaje_fecha = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $viaje_hora = null;

    #[ORM\ManyToOne(inversedBy: 'boletos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Servicio $servicio = null;

    #[ORM\ManyToOne(targetEntity: Pasajero::class, cascade: ['persist'])]
    private ?Pasajero $pasajero = null;

    #[ORM\ManyToOne(inversedBy: 'boletos')]
    private ?Reserva $reserva = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?TransporteAsiento $asiento = null;

    #[ORM\Column(nullable: true)]
    private ?int $estado = null;

    #[ORM\Column(nullable: true)]
    private ?int $costo = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $update_at = null;

    public function __toString()
    {
        return 'B'.$this->id;
    }

    public static function getEstadoChoices() {
        return array_flip(self::$estados);
    }

    public function getEstadoTexto() {
        return self::$estados[$this->estado] ?? 'Desconocido';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrigen(): ?Parada
    {
        return $this->origen;
    }

    public function setOrigen(?Parada $origen): static
    {
        $this->origen = $origen;

        return $this;
    }

    public function getDestino(): ?Parada
    {
        return $this->destino;
    }

    public function setDestino(?Parada $destino): static
    {
        $this->destino = $destino;

        return $this;
    }

    public function getViajeFecha(): ?\DateTimeInterface
    {
        return $this->viaje_fecha;
    }

    public function setViajeFecha(\DateTimeInterface $viaje_fecha): static
    {
        $this->viaje_fecha = $viaje_fecha;

        return $this;
    }

    public function getViajeHora(): ?\DateTimeInterface
    {
        return $this->viaje_hora;
    }

    public function setViajeHora(\DateTimeInterface $viaje_hora): static
    {
        $this->viaje_hora = $viaje_hora;

        return $this;
    }

    public function getServicio(): ?Servicio
    {
        return $this->servicio;
    }

    public function setServicio(?Servicio $servicio): static
    {
        $this->servicio = $servicio;

        return $this;
    }

    public function getPasajero(): ?Pasajero
    {
        return $this->pasajero;
    }

    public function setPasajero(?Pasajero $pasajero): static
    {
        $this->pasajero = $pasajero;

        return $this;
    }

    public function getReserva(): ?Reserva
    {
        return $this->reserva;
    }

    public function setReserva(?Reserva $reserva): static
    {
        $this->reserva = $reserva;

        return $this;
    }

    public function getAsiento(): ?TransporteAsiento
    {
        return $this->asiento;
    }

    public function setAsiento(?TransporteAsiento $asiento): static
    {
        $this->asiento = $asiento;

        return $this;
    }

    public function getEstado(): ?int
    {
        return $this->estado;
    }

    public function setEstado(?int $estado): static
    {
        $this->estado = $estado;

        return $this;
    }

    public function getCosto(): ?int
    {
        return $this->costo;
    }

    public function setCosto(?int $costo): static
    {
        $this->costo = $costo;

        return $this;
    }

    public function getUpdateAt(): ?\DateTimeImmutable
    {
        return $this->update_at;
    }

    public function setUpdateAt(?\DateTimeImmutable $update_at): static
    {
        $this->update_at = $update_at;

        return $this;
    }
}
