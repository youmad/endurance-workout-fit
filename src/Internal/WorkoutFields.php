<?php

declare(strict_types=1);

namespace Endurance\WorkoutFit\Internal;

use Endurance\Fit\Decoded\DecodedFieldElements;
use Endurance\Fit\Decoded\InvalidFieldElement;
use Endurance\Fit\Decoded\ValidFieldElement;
use Endurance\Fit\Profiled\ProfileNormalizationState;
use Endurance\Fit\Unified\UnifiedDataMessage;
use Endurance\WorkoutFit\FitWorkoutProfileUnavailable;
use Endurance\WorkoutFit\InvalidFitWorkout;

/** @internal */
final readonly class WorkoutFields
{
    public function assertUnique(UnifiedDataMessage $message): void
    {
        $seen = [];
        foreach ($message->physicalSource->source->source->source->definition()->standardFields() as $field) {
            if (isset($seen[$field->fieldNumber])) {
                throw new InvalidFitWorkout('Duplicate Workout wire field.');
            }
            $seen[$field->fieldNumber] = true;
        }
    }

    public function scalar(UnifiedDataMessage $message, int $number, string $name, string $type, ?string $units = null): int|float|string|null
    {
        $field = $message->standardField($number);
        if (null === $field) {
            return null;
        }
        $physical = $field->physical();
        if (null === $physical) {
            throw new InvalidFitWorkout('Workout requires a physical scalar: '.$name);
        }
        if ($name !== $physical->name() || $type !== $physical->typeName() || $units !== $physical->units()
            || ProfileNormalizationState::Unavailable === $physical->source->source->normalizationState) {
            throw new FitWorkoutProfileUnavailable('Workout requires compatible selected Profile semantics: '.$name);
        }
        $value = $physical->source->source->value;
        if (!$value instanceof DecodedFieldElements || 1 !== count($value->elements())) {
            throw new InvalidFitWorkout('Workout field must be scalar: '.$name);
        }
        $baseType = match ($type) {
            'uint16', 'message_index' => 4, 'uint32', 'workout_hr', 'workout_power' => 6,
            'string' => 7, default => 0,
        };
        if ($baseType !== $value->baseType()->number()) {
            throw new InvalidFitWorkout('Workout field has an incompatible wire base type: '.$name);
        }
        $element = $value->elements()[0];
        if ($element instanceof InvalidFieldElement) {
            return null;
        }
        if (!$element instanceof ValidFieldElement) {
            throw new InvalidFitWorkout('Workout field is unusable: '.$name);
        }

        return $element->value;
    }

    public function integer(UnifiedDataMessage $message, int $number, string $name, string $type = 'uint32', bool $required = true): ?int
    {
        $value = $this->scalar($message, $number, $name, $type);
        if (null === $value && !$required) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new InvalidFitWorkout('Workout requires an unsigned integer: '.$name);
        }

        return $value;
    }

    public function number(UnifiedDataMessage $message, int $number, string $name, string $type, string $units): float
    {
        $value = $this->scalar($message, $number, $name, $type, $units);
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new InvalidFitWorkout('Workout requires a finite number: '.$name);
        }

        return (float) $value;
    }

    public function text(UnifiedDataMessage $message, int $number, string $name): ?string
    {
        $value = $this->scalar($message, $number, $name, 'string');
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidFitWorkout('Workout text field is not a string: '.$name);
        }

        return '' === trim($value) ? null : $value;
    }

    public function hasValue(UnifiedDataMessage $message, int $number): bool
    {
        $field = $message->standardField($number);
        if (null === $field) {
            return false;
        }
        foreach ($field->values() as $value) {
            if ($value->hasUsableValue()) {
                return true;
            }
        }

        return false;
    }
}
