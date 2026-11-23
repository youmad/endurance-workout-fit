<?php

declare(strict_types=1);

namespace Endurance\WorkoutFit\Tests;

use Endurance\Fit\Decoder\FitDecoder;
use Endurance\Fit\Exception\FitDecodeException;
use Endurance\Fit\IO\FitInput;
use Endurance\Fit\IO\ResourceFitInput;
use Endurance\Workout\Duration\DistanceDuration;
use Endurance\Workout\Duration\OpenDuration;
use Endurance\Workout\Duration\TimeDuration;
use Endurance\Workout\Repeat;
use Endurance\Workout\StepPurpose;
use Endurance\Workout\Target\FreeTarget;
use Endurance\Workout\Target\TargetMetric;
use Endurance\Workout\Target\TargetRange;
use Endurance\WorkoutFit\FitWorkoutMapping;
use Endurance\WorkoutFit\FitWorkoutProfileUnavailable;
use Endurance\WorkoutFit\FitWorkoutReader;
use Endurance\WorkoutFit\InvalidFitWorkout;
use Endurance\WorkoutFit\Tests\Fixture\WorkoutFixture as F;
use Endurance\WorkoutFit\UnsupportedFitWorkout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Fixture/WorkoutFixture.php';

final class FitWorkoutReaderTest extends TestCase
{
    private function read(string $bytes, ?FitDecoder $decoder = null, int $maxSteps = 4096, int $maxMessages = 65536): FitWorkoutMapping
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $result = new FitWorkoutReader($decoder ?? F::decoder(), maxSourceSteps: $maxSteps, maxMessages: $maxMessages)->read(new ResourceFitInput($stream));
            self::assertTrue(is_resource($stream));
            self::assertSame(strlen($bytes), ftell($stream));

            return $result;
        } finally {
            fclose($stream);
        }
    }

    public function testFixedRepeatRetainsOrderedInstructionsAndRelativeTargetsWithoutExpansion(): void
    {
        $mapped = $this->read(F::intervals());
        self::assertSame('Intervals', $mapped->workout->name);
        self::assertSame(6, $mapped->workout->steps->occurrenceCount);
        self::assertInstanceOf(Repeat::class, $mapped->workout->steps->nodes[0]);
        $work = $mapped->workout->steps->stepAt(1);
        self::assertSame($work, $mapped->workout->steps->stepAt(5));
        self::assertInstanceOf(TimeDuration::class, $work->duration);
        self::assertSame(30000000, $work->duration->microseconds);
        self::assertSame('Keep smooth', $work->instructions);
        self::assertSame(StepPurpose::Active, $work->purpose);
        self::assertInstanceOf(TargetRange::class, $work->target);
        self::assertSame(TargetMetric::PowerPercentFtp, $work->target->metric);
        self::assertSame(105.0, $work->target->minimum);
        $rest = $mapped->workout->steps->stepAt(2);
        self::assertInstanceOf(OpenDuration::class, $rest->duration);
        self::assertInstanceOf(FreeTarget::class, $rest->target);
        self::assertSame(StepPurpose::Rest, $rest->purpose);
        self::assertSame(3, $mapped->report->sourceSteps);
        self::assertSame(1, $mapped->report->repeatBlocks);
        self::assertNull($mapped->report->profileSourceSha256);
        self::assertSame('fit-workout.v1', $mapped->report->toArray()['interpretation']);
    }

    #[DataProvider('ranges')]
    public function testSelectedPhysicalUnitsAndAbsoluteRelativeEncodingsArePreserved(int $target, int $low, int $high, TargetMetric $metric, float $min, float $max): void
    {
        $mapped = $this->read(F::member(F::id().F::summary(1).F::step(0, [3 => $target, 4 => 0, 5 => $low, 6 => $high])));
        $range = $mapped->workout->steps->stepAt(1)->target;
        self::assertInstanceOf(TargetRange::class, $range);
        self::assertSame($metric, $range->metric);
        self::assertSame($min, $range->minimum);
        self::assertSame($max, $range->maximum);
    }

    /** @return iterable<string,array{int,int,int,TargetMetric,float,float}> */
    public static function ranges(): iterable
    {
        yield 'speed normalization' => [0, 1234, 5678, TargetMetric::Speed, 1.234, 5.678];
        yield 'cadence zero bound' => [3, 0, 100, TargetMetric::Cadence, 0.0, 100.0];
        yield 'heart rate relative inclusive limit' => [1, 85, 100, TargetMetric::HeartRatePercentMaximum, 85.0, 100.0];
        yield 'heart rate absolute' => [1, 225, 260, TargetMetric::HeartRate, 125.0, 160.0];
        yield 'power relative inclusive limit' => [4, 105, 1000, TargetMetric::PowerPercentFtp, 105.0, 1000.0];
        yield 'power absolute' => [4, 1325, 1400, TargetMetric::Power, 325.0, 400.0];
        yield 'equal zero bounds' => [4, 0, 0, TargetMetric::PowerPercentFtp, 0.0, 0.0];
    }

    public function testDistanceAndMaximumTimeNormalizeUsingTheSelectedSubfields(): void
    {
        $mapped = $this->read(F::member(F::id().F::summary(2).F::step(0, [1 => 1, 2 => 12345]).F::step(1, [2 => 0xFFFFFFFE])));
        $distance = $mapped->workout->steps->stepAt(1)->duration;
        self::assertInstanceOf(DistanceDuration::class, $distance);
        self::assertSame(123.45, $distance->metres);
        $time = $mapped->workout->steps->stepAt(2)->duration;
        self::assertInstanceOf(TimeDuration::class, $time);
        self::assertSame(4294967294000, $time->microseconds);
    }

    public function testNestedBlocksAndHugeCountsRemainACompactTreeWithSeekableOccurrences(): void
    {
        $data = F::id().F::summary(5).F::step(0, [0 => 'A']).F::step(1, [0 => 'B'])
            .F::repeat(2, 1, 2).F::step(3, [0 => 'C']).F::repeat(4, 0, 1000000000);
        $mapped = $this->read(F::member($data));
        self::assertSame(4000000000, $mapped->workout->steps->occurrenceCount);
        self::assertSame(1, count($mapped->workout->steps->nodes));
        self::assertSame('C', $mapped->workout->steps->stepAt(4000000000)->name);
        self::assertSame(['B', 'C'], array_map(static fn ($s) => $s->name,
            array_values(iterator_to_array($mapped->workout->steps->occurrences(3999999998, 10)))));
    }

    #[DataProvider('badSources')]
    public function testMalformedSourcesNeverProduceAPartialWorkout(string $data): void
    {
        $this->expectException(InvalidFitWorkout::class);
        $this->read(F::member($data));
    }

    /** @return iterable<string,array{string}> */
    public static function badSources(): iterable
    {
        yield 'summary first' => [F::summary(1).F::id().F::step(0)];
        yield 'Activity file' => [F::id(4).F::summary(1).F::step(0)];
        yield 'Course file' => [F::id(6).F::summary(1).F::step(0)];
        yield 'second File Id' => [F::id().F::id().F::summary(1).F::step(0)];
        yield 'missing summary' => [F::id().F::step(0)];
        yield 'duplicate summary' => [F::id().F::summary(1).F::summary(1).F::step(0)];
        yield 'zero steps' => [F::id().F::summary(0)];
        yield 'missing step' => [F::id().F::summary(2).F::step(0)];
        yield 'extra step' => [F::id().F::summary(1).F::step(0).F::step(1)];
        yield 'index gap' => [F::id().F::summary(1).F::step(1)];
        yield 'selected index flag' => [F::id().F::summary(1).F::step(0, [254 => 0x8000])];
        yield 'duplicate index' => [F::id().F::summary(2).F::step(0).F::step(0)];
        yield 'missing duration' => [F::id().F::summary(1).F::step(0, [1 => null])];
        yield 'invalid duration sentinel' => [F::id().F::summary(1).F::step(0, [2 => 0xFFFFFFFF])];
        yield 'zero time' => [F::id().F::summary(1).F::step(0, [2 => 0])];
        yield 'zero distance' => [F::id().F::summary(1).F::step(0, [1 => 1, 2 => 0])];
        yield 'open duration with value' => [F::id().F::summary(1).F::step(0, [1 => 5, 2 => 10])];
        yield 'missing target type' => [F::id().F::summary(1).F::step(0, [3 => null])];
        yield 'missing custom high' => [F::id().F::summary(1).F::step(0, [3 => 4, 4 => 0, 5 => 90])];
        yield 'reversed range' => [F::id().F::summary(1).F::step(0, [3 => 4, 4 => 0, 5 => 120, 6 => 90])];
        yield 'repeat forward' => [F::id().F::summary(2).F::step(0).F::repeat(1, 2, 3)];
        yield 'repeat self' => [F::id().F::summary(2).F::step(0).F::repeat(1, 1, 3)];
        yield 'repeat zero count' => [F::id().F::summary(2).F::step(0).F::repeat(1, 0, 0)];
        yield 'crossing repeat' => [F::id().F::summary(4).F::step(0).F::step(1).F::repeat(2, 0, 2).F::repeat(3, 1, 2)];
        yield 'repeat overflow' => [F::id().F::summary(3).F::step(0).F::repeat(1, 0, 0xFFFFFFFE).F::repeat(2, 0, 0xFFFFFFFE)];
        yield 'duplicate wire field' => [F::id().F::summary(1).F::message(27, [[254, 0x84, pack('v', 0)], [1, 0, "\0"], [1, 0, "\0"], [2, 0x86, pack('V', 1000)], [3, 0, "\2"]])];
        yield 'duration float wire type' => [F::id().F::summary(1).F::message(27, [[254, 0x84, pack('v', 0)], [1, 0, "\0"], [2, 0x88, pack('g', 1000.0)], [3, 0, "\2"]])];
        yield 'duration array' => [F::id().F::summary(1).F::message(27, [[254, 0x84, pack('v', 0)], [1, 0, "\0"], [2, 0x86, pack('V2', 1000, 2000)], [3, 0, "\2"]])];
    }

    /** @param array<int,int|string|null> $changes */
    #[DataProvider('unsupportedSteps')]
    public function testUnsupportedInstructionsAreNeverInventedOrSilentlyDropped(array $changes): void
    {
        $this->expectException(UnsupportedFitWorkout::class);
        $this->read(F::member(F::id().F::summary(1).F::step(0, $changes)));
    }

    /** @return iterable<string,array{array<int,int|string|null>}> */
    public static function unsupportedSteps(): iterable
    {
        yield 'repeat until time' => [[1 => 7]];
        yield 'threshold ending' => [[1 => 2]];
        yield 'repetitions' => [[1 => 29]];
        yield 'unknown ending' => [[1 => 254]];
        yield 'athlete zone' => [[3 => 4, 4 => 2]];
        yield 'mixed heart rate bounds' => [[3 => 1, 4 => 0, 5 => 90, 6 => 225]];
        yield 'mixed power bounds' => [[3 => 4, 4 => 0, 5 => 1000, 6 => 1325]];
        yield 'averaged power' => [[3 => 7]];
        yield 'interval purpose is not active' => [[7 => 5]];
        yield 'other purpose is not unspecified' => [[7 => 6]];
        yield 'equipment' => [[9 => 0]];
        yield 'exercise' => [[10 => 1]];
        yield 'secondary target' => [[19 => 2]];
        yield 'open with custom target' => [[5 => 123]];
    }

    public function testRepeatCountOneIsRetainedAndRepeatMetadataIsRejected(): void
    {
        $mapped = $this->read(F::member(F::id().F::summary(2).F::step(0).F::repeat(1, 0, 1)));
        self::assertInstanceOf(Repeat::class, $mapped->workout->steps->nodes[0]);
        self::assertSame(1, $mapped->workout->steps->occurrenceCount);
        $this->expectException(UnsupportedFitWorkout::class);
        $this->read(F::member(F::id().F::summary(2).F::step(0).F::step(1, [1 => 6, 2 => 0, 4 => 2, 8 => 'Do not discard this'])));
    }

    public function testProfileUnitsAndSelectedSubfieldsMustBeAvailable(): void
    {
        $this->expectException(FitWorkoutProfileUnavailable::class);
        $this->read(F::intervals(), F::decoder(timeUnits: 'ms'));
    }

    public function testMissingSubfieldProfileIsNotInterpretedUsingHardCodedWireScales(): void
    {
        $this->expectException(FitWorkoutProfileUnavailable::class);
        $this->read(F::intervals(), F::decoder(subfields: false));
    }

    public function testSubmicrosecondTimesCannotBeRoundedIntoAnotherPrescription(): void
    {
        $this->expectException(InvalidFitWorkout::class);
        $this->read(F::member(F::id().F::summary(1).F::step(0, [2 => 1])), F::decoder(timeScale: 10000000));
    }

    #[DataProvider('corruptSources')]
    public function testCrcTruncationAndTrailingBytesAreValidatedBeforeReturning(string $bytes): void
    {
        $this->expectException(FitDecodeException::class);
        $this->read($bytes);
    }

    /** @return iterable<string,array{string}> */
    public static function corruptSources(): iterable
    {
        yield 'empty data section' => [F::member('')];
        $valid = F::intervals();
        yield 'truncated trailer' => [substr($valid, 0, -1)];
        yield 'corrupt CRC' => [substr($valid, 0, -1).chr(ord($valid[-1]) ^ 255)];
        yield 'trailing garbage' => [$valid.'garbage'];
        $member = F::member(F::message(49, []));
        yield 'corrupt later member' => [$valid.substr($member, 0, -1).chr(ord($member[-1]) ^ 255)];
    }

    public function testAncillaryOmissionsHaveABoundedReportAndDoNotLeakSourceText(): void
    {
        $data = F::id().F::message(49, [[0, 0x84, pack('v', 1)]])
            .F::message(26, [[6, 0x84, pack('v', 1)], [8, 7, "Private name\0"], [4, 0, "\2"]])
            .F::step(0, [99 => 123]);
        $report = $this->read(F::member($data))->report->toArray();
        self::assertSame(1, $report['omittedMessages']);
        self::assertSame(3, $report['omittedFields']);
        self::assertSame(['source_facts_not_represented'], $report['notices']);
        self::assertStringNotContainsString('Private name', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testUnknownMessagesAreNotMistakenForAncillaryWorkoutMetadata(): void
    {
        $this->expectException(UnsupportedFitWorkout::class);
        $this->read(F::member(F::id().F::summary(1).F::step(0).F::message(20, [])));
    }

    public function testDeclaredSourceCountAndTotalMessageLimitsBoundProcessing(): void
    {
        $this->expectException(UnsupportedFitWorkout::class);
        $this->read(F::intervals(), maxSteps: 2);
    }

    public function testMessageLimitIsIndependentOfTheStepLimit(): void
    {
        $this->expectException(UnsupportedFitWorkout::class);
        $this->read(F::intervals(), maxMessages: 3);
    }

    public function testAForwardOnlyInputAndRepeatedReaderCallsHaveIndependentState(): void
    {
        $bytes = F::intervals();
        $input = new class($bytes) implements FitInput {
            private int $offset = 0;

            public function __construct(private readonly string $bytes)
            {
            }

            public function readExact(int $length): string
            {
                if ($length > strlen($this->bytes) - $this->offset) {
                    throw new FitDecodeException('Unexpected test EOF.');
                }
                $value = substr($this->bytes, $this->offset, $length);
                $this->offset += $length;

                return $value;
            }

            public function isAtEnd(): bool
            {
                return $this->offset === strlen($this->bytes);
            }

            public function position(): int
            {
                return $this->offset;
            }
        };
        $reader = new FitWorkoutReader(F::decoder(), str_repeat('a', 64));
        $ticks = 0;
        $result = $reader->read($input, static function () use (&$ticks): void { ++$ticks; });
        self::assertSame(strlen($bytes), $input->position());
        self::assertSame(7, $ticks);
        self::assertSame(str_repeat('a', 64), $result->report->profileSourceSha256);
        self::assertSame(6, $result->workout->steps->occurrenceCount);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            self::assertSame(6, $reader->read(new ResourceFitInput($stream))->workout->steps->occurrenceCount);
        } finally {
            fclose($stream);
        }
    }

    public function testDeveloperPayloadIsCountedWithoutBecomingAPrescription(): void
    {
        $definition = "\x60\0\0".pack('v', 27)."\4"
            ."\xfe\2\x84\1\1\0\2\4\x86\3\1\0"
            ."\1\0\1\0";
        $data = "\0".pack('v', 0)."\0".pack('V', 1000)."\2\x2a";
        $mapped = $this->read(F::member(F::id().F::summary(1).$definition.$data));
        self::assertSame(1, $mapped->report->developerFields);
        self::assertSame(1, $mapped->workout->steps->occurrenceCount);
    }

    public function testProgressCancellationPropagatesAndLeavesCallerInputOpen(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        try {
            fwrite($stream, F::intervals());
            rewind($stream);
            $cancel = new \RuntimeException('cancelled');
            try {
                new FitWorkoutReader(F::decoder())->read(new ResourceFitInput($stream), static fn () => throw $cancel);
                self::fail('Cancellation was swallowed.');
            } catch (\RuntimeException $failure) {
                self::assertSame($cancel, $failure);
                self::assertSame(0, ftell($stream));
            }
        } finally {
            fclose($stream);
        }
    }
}
