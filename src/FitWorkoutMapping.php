<?php

declare(strict_types=1);

namespace Youmad\Endurance\WorkoutFit;

use Youmad\Endurance\Workout\Workout;

final readonly class FitWorkoutMapping
{
    public const string INTERPRETATION = 'fit-workout.v1';
    public const string SOURCE_LOCATOR = 'fit-source.v1/source[1]';

    public function __construct(public Workout $workout, public FitWorkoutReport $report)
    {
    }
}
