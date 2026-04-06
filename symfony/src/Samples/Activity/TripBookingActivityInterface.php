<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface TripBookingActivityInterface
{
    #[ActivityMethod('samples_bookFlight')]
    public function bookFlight(): string;

    #[ActivityMethod('samples_bookHotel')]
    public function bookHotel(bool $fail = false): string;

    #[ActivityMethod('samples_cancelFlight')]
    public function cancelFlight(string $flightId): void;
}
