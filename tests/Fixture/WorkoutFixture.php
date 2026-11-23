<?php

declare(strict_types=1);

namespace Youmad\Endurance\WorkoutFit\Tests\Fixture;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Profile\FieldProfile as F;
use Youmad\Endurance\Fit\Profile\FieldTransform as T;
use Youmad\Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Youmad\Endurance\Fit\Profile\InMemoryFitTypeRegistry;
use Youmad\Endurance\Fit\Profile\MessageProfile as M;
use Youmad\Endurance\Fit\Profile\SubfieldCondition as C;
use Youmad\Endurance\Fit\Profile\SubfieldProfile as S;

/** Hand-written test Profile subset, not generated production data or a fallback. */
final class WorkoutFixture
{
    public static function decoder(string $timeUnits = 's', int $timeScale = 1000, bool $subfields = true): FitDecoder
    {
        $duration = [S::create('duration_time', 'uint32', T::scaleAndOffset($timeScale), $timeUnits, [C::create(1, [0])]),
            S::create('duration_distance', 'uint32', T::scaleAndOffset(100), 'm', [C::create(1, [1])]),
            S::create('duration_step', 'uint32', conditions: [C::create(1, [6])])];
        $zones = [S::create('repeat_steps', 'uint32', conditions: [C::create(1, [6])])];
        $low = $high = [];
        foreach ([0 => ['speed', 'speed', 'uint32', 'm/s', 1000],
            1 => ['hr', 'heart_rate', 'workout_hr', '% or bpm', 1],
            3 => ['cadence', 'cadence', 'uint32', 'rpm', 1],
            4 => ['power', 'power', 'workout_power', '% or watts', 1]] as $target => [$zone, $name, $type, $units, $scale]) {
            $zones[] = S::create('target_'.$zone.'_zone', 'uint32', conditions: [C::create(3, [$target])]);
            $low[] = S::create('custom_target_'.$name.'_low', $type, T::scaleAndOffset($scale), $units, [C::create(3, [$target])]);
            $high[] = S::create('custom_target_'.$name.'_high', $type, T::scaleAndOffset($scale), $units, [C::create(3, [$target])]);
        }

        return new FitDecoder(new InMemoryFitProfileRegistry(
            M::create(0, 'file_id', [F::create(0, 'type', 'file')]),
            M::create(26, 'workout', [F::create(6, 'num_valid_steps', 'uint16'), F::create(8, 'wkt_name', 'string')]),
            M::create(27, 'workout_step', [F::create(254, 'message_index', 'message_index'), F::create(0, 'wkt_step_name', 'string'),
                F::create(1, 'duration_type', 'wkt_step_duration'), F::create(2, 'duration_value', 'uint32', subfields: $subfields ? $duration : []),
                F::create(3, 'target_type', 'wkt_step_target'), F::create(4, 'target_value', 'uint32', subfields: $subfields ? $zones : []),
                F::create(5, 'custom_target_value_low', 'uint32', subfields: $subfields ? $low : []),
                F::create(6, 'custom_target_value_high', 'uint32', subfields: $subfields ? $high : []),
                F::create(7, 'intensity', 'intensity'), F::create(8, 'notes', 'string')]),
        ), new InMemoryFitTypeRegistry([]));
    }

    /** @param list<array{int,int,string}> $fields */
    public static function message(int $number, array $fields): string
    {
        $definition = $data = '';
        foreach ($fields as [$index, $type, $value]) {
            $definition .= chr($index).chr(strlen($value)).chr($type);
            $data .= $value;
        }

        return "\x40\0\0".pack('v', $number).chr(count($fields)).$definition."\0".$data;
    }

    public static function id(int $type = 5): string
    {
        return self::message(0, [[0, 0, chr($type)]]);
    }

    public static function summary(int $count, string $name = 'Intervals'): string
    {
        return self::message(26, [[6, 0x84, pack('v', $count)], [8, 7, $name."\0"]]);
    }

    /** @param array<int,int|string|null> $changes Field number => replacement (null removes field). */
    public static function step(int $index, array $changes = []): string
    {
        $values = array_replace([254 => $index, 1 => 0, 2 => 30000, 3 => 2], $changes);
        $fields = [];
        foreach ($values as $number => $value) {
            if (null === $value) {
                continue;
            }
            $type = match ($number) {
                254 => 0x84, 0, 8 => 7, 1, 3, 7, 9, 19 => 0, default => 0x86,
            };
            $bytes = match ($type) {
                0x84 => pack('v', (int) $value), 7 => $value."\0", 0 => chr((int) $value), default => pack('V', (int) $value),
            };
            $fields[] = [$number, $type, $bytes];
        }

        return self::message(27, $fields);
    }

    public static function repeat(int $index, int $from, int $count): string
    {
        return self::step($index, [1 => 6, 2 => $from, 4 => $count]);
    }

    public static function member(string $data): string
    {
        $header = "\x0e\x20".pack('vV', 2300, strlen($data)).'.FIT';
        $header .= pack('v', FitCrc16::calculate($header));
        $body = $header.$data;

        return $body.pack('v', FitCrc16::calculate($body));
    }

    public static function intervals(): string
    {
        return self::member(self::id().self::summary(3)
            .self::step(0, [0 => 'Work', 3 => 4, 4 => 0, 5 => 105, 6 => 120, 7 => 0, 8 => 'Keep smooth'])
            .self::step(1, [0 => 'Rest', 1 => 5, 2 => 0, 7 => 1]).self::repeat(2, 0, 3));
    }
}
