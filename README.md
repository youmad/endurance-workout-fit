# Endurance Workout FIT

Interpret a FIT Workout as an Endurance Workout prescription for PHP 8.5.

```bash
composer require youmad/endurance-workout-fit
```

```php
use Youmad\Endurance\WorkoutFit\FitWorkoutReader;

$mapping = FitWorkoutReader::standard($profileSet)->read($input, $progress);
$prescription = $mapping->workout;
```

- Supply a compatible generated FIT Profile; none is bundled or inferred.
  The reader retains input ownership with the caller and returns only after all
  member CRCs and final EOF validate. Progress callbacks support cancellation.
- Supported instructions include time, distance, manual completion, fixed nested
  repeats and compatible absolute/relative targets. Open-ended/conditional repeats
  and unsupported targets are rejected rather than approximated.
- Default bounds are 4096 physical steps and 65536 data messages. Repeat traversal
  stays bounded without expanding repetitions. The result contains a prescription
  value and bounded diagnostics.
- Profile incompatibility raises `FitWorkoutProfileUnavailable`; malformed Workout
  structure raises `InvalidFitWorkout`; unsupported instructions/limits raise
  `UnsupportedFitWorkout`. Protocol/CRC failures retain their FIT exceptions.

Types in `Internal` and elements marked `@internal` are not public API.

License: [MPL-2.0](LICENSE).
