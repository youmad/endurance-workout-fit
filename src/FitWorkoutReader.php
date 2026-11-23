<?php

declare(strict_types=1);

namespace Endurance\WorkoutFit;

use Endurance\Fit\Decoder\FitDecoder;
use Endurance\Fit\IO\FitInput;
use Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;
use Endurance\Workout\Repeat;
use Endurance\Workout\Sequence;
use Endurance\Workout\Step;
use Endurance\Workout\Workout;
use Endurance\WorkoutFit\Internal\WorkoutFields;
use Endurance\WorkoutFit\Internal\WorkoutSteps;

/** Return only after every CRC and final EOF validate; caller owns input. */
final readonly class FitWorkoutReader
{
    private const array FIELDS = [0 => [0], 26 => [6, 8], 27 => [254, 0, 1, 2, 3, 4, 5, 6, 7, 8]];
    private const array NAMES = [0 => 'file_id', 26 => 'workout', 27 => 'workout_step'];

    public function __construct(private FitDecoder $decoder, private ?string $profileSourceSha256 = null,
        private int $maxSourceSteps = 4096, private int $maxMessages = 65536)
    {
        if ($maxSourceSteps < 1 || $maxSourceSteps > 65534 || $maxMessages < 3) {
            throw new \InvalidArgumentException('Workout limits require 1..65534 source steps and at least three messages.');
        }
    }

    public static function standard(GeneratedFitProfileSet $profile, int $maxSourceSteps = 4096, int $maxMessages = 65536): self
    {
        return new self(FitDecoder::standard($profile), $profile->sourceSha256(), $maxSourceSteps, $maxMessages);
    }

    /** @param callable():void|null $progress */
    public function read(FitInput $input, ?callable $progress = null): FitWorkoutMapping
    {
        $fields = new WorkoutFields();
        $steps = new WorkoutSteps($fields);
        $count = $sourceSteps = $repeats = $omittedMessages = $omittedFields = $developers = 0;
        $idSeen = false;
        $declared = $name = null;
        /** @var list<array{start:int,node:Step|Repeat}> $nodes */
        $nodes = [];
        if (null !== $progress) {
            $progress();
        }
        $file = $this->decoder->open($input);
        foreach ($file->messages() as $message) {
            if (null !== $progress) {
                $progress();
            }
            if (++$count > $this->maxMessages) {
                throw new UnsupportedFitWorkout('Workout source message limit exceeded.');
            }
            $number = $message->globalMessageNumber();
            if (1 === $count && 0 !== $number) {
                throw new InvalidFitWorkout('Workout File Id must be the first data message.');
            }
            $developers += count($message->developerFields());
            if (!isset(self::FIELDS[$number])) {
                if (!in_array($number, [23, 49, 206, 207], true)) {
                    throw new UnsupportedFitWorkout('Unsupported message in Workout source: '.$number);
                }
                ++$omittedMessages;
                $omittedFields += count($message->standardFields());
                continue;
            }
            if (self::NAMES[$number] !== $message->name()) {
                throw new FitWorkoutProfileUnavailable('Workout requires compatible message Profile: '.self::NAMES[$number]);
            }
            $fields->assertUnique($message);
            foreach ($message->standardFields() as $field) {
                if (!in_array($field->fieldNumber, self::FIELDS[$number], true)) {
                    ++$omittedFields;
                }
            }
            if (0 === $number) {
                if ($idSeen || 5 !== $fields->integer($message, 0, 'type', 'file')) {
                    throw new InvalidFitWorkout('Exactly one Workout/type 5 File Id is required.');
                }
                $idSeen = true;
                continue;
            }
            if (26 === $number) {
                if (null !== $declared || $sourceSteps > 0) {
                    throw new InvalidFitWorkout('One Workout summary must precede its steps.');
                }
                $declared = $fields->integer($message, 6, 'num_valid_steps', 'uint16');
                if (null === $declared || $declared < 1 || $declared > 65534) {
                    throw new InvalidFitWorkout('Workout must declare 1..65534 source steps.');
                }
                if ($declared > $this->maxSourceSteps) {
                    throw new UnsupportedFitWorkout('Workout source step limit exceeded.');
                }
                $name = $fields->text($message, 8, 'wkt_name');
                continue;
            }
            if (null === $declared || $sourceSteps >= $declared
                || $sourceSteps !== $fields->integer($message, 254, 'message_index', 'message_index')) {
                throw new InvalidFitWorkout('Workout steps require a summary and contiguous zero-based source indices.');
            }
            foreach ([9, 10, 11, 12, 13, 19, 20, 21, 22] as $unsupported) {
                if ($fields->hasValue($message, $unsupported)) {
                    throw new UnsupportedFitWorkout('Workout equipment, exercises and secondary targets are unsupported.');
                }
            }
            $duration = $fields->integer($message, 1, 'duration_type', 'wkt_step_duration');
            try {
                if (6 === $duration) {
                    if (2 !== $fields->integer($message, 3, 'target_type', 'wkt_step_target')) {
                        throw new UnsupportedFitWorkout('Fixed repeat requires an explicit open target type.');
                    }
                    $steps->assertNeutralBounds($message);
                    if (null !== $fields->text($message, 0, 'wkt_step_name') || null !== $fields->text($message, 8, 'notes')
                        || $fields->hasValue($message, 7)) {
                        throw new UnsupportedFitWorkout('Repeat metadata cannot be represented as an executable Step.');
                    }
                    $from = $fields->integer($message, 2, 'duration_step');
                    $times = $fields->integer($message, 4, 'repeat_steps');
                    $boundary = null;
                    foreach ($nodes as $index => $entry) {
                        if ($entry['start'] === $from) {
                            $boundary = $index;
                            break;
                        }
                    }
                    if (null === $boundary || null === $times || $times < 1) {
                        throw new InvalidFitWorkout('Repeat must refer backwards to a complete block and have a positive count.');
                    }
                    $block = array_splice($nodes, $boundary);
                    $nodes[] = ['start' => $from, 'node' => new Repeat($times, new Sequence(...array_column($block, 'node')))];
                    ++$repeats;
                } else {
                    if (null === $duration) {
                        throw new InvalidFitWorkout('Workout duration type is required.');
                    }
                    $nodes[] = ['start' => $sourceSteps, 'node' => $steps->step($message, $duration)];
                }
            } catch (\InvalidArgumentException $failure) {
                throw new InvalidFitWorkout('Unrepresentable Workout prescription: '.$failure->getMessage(), previous: $failure);
            }
            ++$sourceSteps;
        }
        $file->trailer();
        if (null !== $progress) {
            $progress();
        }
        if (!$idSeen || null === $declared || $declared !== $sourceSteps || [] === $nodes) {
            throw new InvalidFitWorkout('Workout summary/step count is incomplete.');
        }
        try {
            $workout = new Workout(new Sequence(...array_column($nodes, 'node')), $name);
        } catch (\InvalidArgumentException $failure) {
            throw new InvalidFitWorkout('Unrepresentable Workout prescription.', previous: $failure);
        }

        return new FitWorkoutMapping($workout, new FitWorkoutReport($sourceSteps, $repeats, $omittedMessages, $omittedFields, $developers, $this->profileSourceSha256));
    }
}
