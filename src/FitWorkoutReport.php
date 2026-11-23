<?php

declare(strict_types=1);

namespace Endurance\WorkoutFit;

final readonly class FitWorkoutReport
{
    public function __construct(
        public int $sourceSteps,
        public int $repeatBlocks,
        public int $omittedMessages,
        public int $omittedFields,
        public int $developerFields,
        public ?string $profileSourceSha256,
    ) {
    }

    /** @return array<string,int|string|list<string>|null> */
    public function toArray(): array
    {
        return ['interpretation' => FitWorkoutMapping::INTERPRETATION, 'sourceLocator' => FitWorkoutMapping::SOURCE_LOCATOR,
            'sourceSteps' => $this->sourceSteps, 'repeatBlocks' => $this->repeatBlocks,
            'omittedMessages' => $this->omittedMessages, 'omittedFields' => $this->omittedFields,
            'developerFields' => $this->developerFields, 'profileSourceSha256' => $this->profileSourceSha256,
            'notices' => ($this->omittedMessages + $this->omittedFields + $this->developerFields > 0) ? ['source_facts_not_represented'] : []];
    }
}
