<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface TripBookingActivityInterface
{
    #[AsActivityMethod('samples_bookFlight')]
    public function bookFlight(): string;

    #[AsActivityMethod('samples_bookHotel')]
    public function bookHotel(bool $fail = false): string;

    #[AsActivityMethod('samples_cancelFlight')]
    public function cancelFlight(string $flightId): void;
}
