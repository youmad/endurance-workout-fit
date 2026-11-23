<?php

declare(strict_types=1);

/** @param Closure(bool): void $ensure */
return static function (Closure $ensure, string $autoload, string $package): void {
    require dirname(__DIR__).'/tests/Fixture/WorkoutFixture.php';
    $stream = fopen('php://temp', 'w+b');
    try {
        fwrite($stream, Endurance\WorkoutFit\Tests\Fixture\WorkoutFixture::intervals());
        rewind($stream);
        $mapping = new Endurance\WorkoutFit\FitWorkoutReader(Endurance\WorkoutFit\Tests\Fixture\WorkoutFixture::decoder())->read(new Endurance\Fit\IO\ResourceFitInput($stream));
        $ensure(6 === $mapping->workout->steps->occurrenceCount && 3 === $mapping->report->sourceSteps);
    } finally {
        fclose($stream);
    }
};
