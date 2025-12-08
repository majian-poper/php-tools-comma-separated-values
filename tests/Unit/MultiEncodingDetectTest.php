<?php

declare(strict_types=1);

use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

beforeEach(function () {
    $this->file = __DIR__.'/../fixtures/multi-encoding.csv';

    $this->csv = new CommaSeparatedValues($this->file);
});

describe('CommaSeparatedValues for multi-encoding csv', function () {

    test('getEncoding throws exception', function () {
        $this->csv->getEncoding();
    })->throws(RuntimeException::class);

});
