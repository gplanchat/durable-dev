<?php

declare(strict_types=1);

namespace App\Samples\Workflow\BookingSaga;

use App\Samples\Activity\TripBookingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Port léger de samples-php BookingSaga : réserve vol puis hôtel ; en cas d’échec hôtel, compensation sur le vol.
 */
#[Workflow('Samples_BookingSaga_Light')]
final class BookingSagaLightWorkflow
{
    private readonly ActivityStub $trip;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->trip = $environment->activityStub(TripBookingActivityInterface::class);
    }

    #[WorkflowMethod]
    public function run(bool $failHotel = false): string
    {
        $flightId = $this->environment->await($this->trip->bookFlight());
        try {
            return $this->environment->await($this->trip->bookHotel($failHotel));
        } catch (DurableActivityFailedException $e) {
            $this->environment->await($this->trip->cancelFlight($flightId));

            return 'compensated: '.$e->getMessage();
        }
    }
}
