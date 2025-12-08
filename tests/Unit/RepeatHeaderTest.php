<?php

declare(strict_types=1);

use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

beforeEach(function () {
    $this->file = __DIR__.'/../fixtures/repeat-header.csv';

    $this->csv = new CommaSeparatedValues($this->file);
});

describe('CommaSeparatedValues with repeated header', function () {

    test('readRow returns correct first row with repeated header file', function () {
        $headers = $this->csv->getHeaders();

        expect($headers)->toBe(['id', 'name', 'city', 'score', 'remark', 'score (2)', 'remark (2)', 'score (3)']);
    });

});
