<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;
use RuntimeException;

#[AsActivityHandler(contract: TripBookingActivityInterface::class)]
final class TripBookingActivityHandler implements TripBookingActivityInterface
{
    public function bookFlight(): string
    {
        return 'flight-demo';
    }

    public function bookHotel(bool $fail = false): string
    {
        if ($fail) {
            throw new RuntimeException('Hotel unavailable (samples-php BookingSaga).');
        }

        return 'hotel-confirmed';
    }

    public function cancelFlight(string $flightId): void
    {
    }
}
