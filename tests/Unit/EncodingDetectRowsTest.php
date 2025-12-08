<?php

declare(strict_types=1);

use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

beforeEach(function () {
    $this->file = __DIR__.'/../fixtures/shiftjis-detect-rows.csv';

    $this->csv = new CommaSeparatedValues($this->file);
});

describe('CommaSeparatedValues for Shift_JIS csv', function () {

    test('getEncoding returns Shift_JIS', function () {
        expect($this->csv->getEncoding())->toBe('SJIS-win');
    });

    test('getEncoding returns incorrect UTF-8 encoding', function () {
        expect($this->csv->setDetectEncodingRows(1)->getEncoding())->toBe('UTF-8');
    });

});
