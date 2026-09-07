<?php

declare(strict_types=1);

namespace App\Samples\Workflow\BookingSaga;

use App\Samples\Activity\TripBookingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Light port of samples-php BookingSaga: books a flight then a hotel; if the hotel fails, compensation on the flight.
 */
#[AsWorkflow('Samples_BookingSaga_Light')]
final class BookingSagaLightWorkflow
{
    private readonly ActivityStub $trip;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        // A single attempt: retries are unlimited by default, and the scenario's hotel failure
        // would never bubble up to the compensation.
        $this->trip = $environment->activityStub(
            TripBookingActivityInterface::class,
            new ActivityOptions(RetryLimit::once()),
        );
    }

    #[AsWorkflowMethod]
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
