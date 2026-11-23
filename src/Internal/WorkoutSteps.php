<?php

declare(strict_types=1);

namespace Endurance\WorkoutFit\Internal;

use Endurance\Fit\Unified\UnifiedDataMessage;
use Endurance\Workout\Duration\DistanceDuration;
use Endurance\Workout\Duration\OpenDuration;
use Endurance\Workout\Duration\TimeDuration;
use Endurance\Workout\Step;
use Endurance\Workout\StepPurpose;
use Endurance\Workout\Target\FreeTarget;
use Endurance\Workout\Target\TargetMetric;
use Endurance\Workout\Target\TargetRange;
use Endurance\WorkoutFit\InvalidFitWorkout;
use Endurance\WorkoutFit\UnsupportedFitWorkout;

/** @internal */
final readonly class WorkoutSteps
{
    public function __construct(private WorkoutFields $fields = new WorkoutFields())
    {
    }

    public function step(UnifiedDataMessage $message, int $durationType): Step
    {
        $duration = match ($durationType) {
            0 => $this->time($message),
            1 => new DistanceDuration($this->fields->number($message, 2, 'duration_distance', 'uint32', 'm')),
            5 => $this->open($message),
            default => throw new UnsupportedFitWorkout('Unsupported Workout duration/control flow: '.$durationType),
        };
        $purpose = match ($this->fields->integer($message, 7, 'intensity', 'intensity', false)) {
            null => StepPurpose::Unspecified, 0 => StepPurpose::Active, 1 => StepPurpose::Rest,
            2 => StepPurpose::WarmUp, 3 => StepPurpose::CoolDown, 4 => StepPurpose::Recovery,
            default => throw new UnsupportedFitWorkout('Unsupported Workout intensity.'),
        };

        return new Step($duration, $this->target($message), $purpose,
            $this->fields->text($message, 0, 'wkt_step_name'), $this->fields->text($message, 8, 'notes'));
    }

    private function time(UnifiedDataMessage $message): TimeDuration
    {
        $seconds = $this->fields->number($message, 2, 'duration_time', 'uint32', 's');
        $micros = $seconds * 1_000_000;
        if ($micros <= 0 || $micros >= PHP_INT_MAX || abs($micros - round($micros)) > max(0.000001, abs($micros) * PHP_FLOAT_EPSILON * 8)) {
            throw new InvalidFitWorkout('Workout time must be a positive representable microsecond duration.');
        }

        return new TimeDuration((int) round($micros));
    }

    private function open(UnifiedDataMessage $message): OpenDuration
    {
        if (!in_array($this->fields->integer($message, 2, 'duration_value', required: false), [null, 0], true)) {
            throw new InvalidFitWorkout('Manual duration cannot carry another ending value.');
        }

        return new OpenDuration();
    }

    public function assertNeutralBounds(UnifiedDataMessage $message): void
    {
        foreach ([5 => 'custom_target_value_low', 6 => 'custom_target_value_high'] as $number => $name) {
            if (!in_array($this->fields->integer($message, $number, $name, required: false), [null, 0], true)) {
                throw new UnsupportedFitWorkout('Open/repeat control carries a custom target.');
            }
        }
    }

    private function target(UnifiedDataMessage $message): FreeTarget|TargetRange
    {
        $target = $this->fields->integer($message, 3, 'target_type', 'wkt_step_target');
        if (2 === $target) {
            if (!in_array($this->fields->integer($message, 4, 'target_value', required: false), [null, 0], true)) {
                throw new InvalidFitWorkout('Open target cannot carry a zone or repeat value.');
            }
            $this->assertNeutralBounds($message);

            return new FreeTarget();
        }
        [$zone, $prefix, $type, $units, $metric] = match ($target) {
            0 => ['target_speed_zone', 'custom_target_speed_', 'uint32', 'm/s', TargetMetric::Speed],
            1 => ['target_hr_zone', 'custom_target_heart_rate_', 'workout_hr', '% or bpm', TargetMetric::HeartRatePercentMaximum],
            3 => ['target_cadence_zone', 'custom_target_cadence_', 'uint32', 'rpm', TargetMetric::Cadence],
            4 => ['target_power_zone', 'custom_target_power_', 'workout_power', '% or watts', TargetMetric::PowerPercentFtp],
            default => throw new UnsupportedFitWorkout('Unsupported Workout target: '.$target),
        };
        if (0 !== $this->fields->integer($message, 4, $zone)) {
            throw new UnsupportedFitWorkout('Workout target zones require external athlete settings.');
        }
        $low = $this->fields->number($message, 5, $prefix.'low', $type, $units);
        $high = $this->fields->number($message, 6, $prefix.'high', $type, $units);
        if (1 === $target || 4 === $target) {
            $offset = 1 === $target ? 100 : 1000;
            if ($low !== floor($low) || $high !== floor($high) || ($low > $offset) !== ($high > $offset)) {
                throw new UnsupportedFitWorkout('Workout range mixes absolute and relative bounds or noninteger encodings.');
            }
            if ($low > $offset) {
                $low -= $offset;
                $high -= $offset;
                $metric = 1 === $target ? TargetMetric::HeartRate : TargetMetric::Power;
            }
        }

        return new TargetRange($metric, $low, $high);
    }
}
