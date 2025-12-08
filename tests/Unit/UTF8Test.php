<?php

declare(strict_types=1);

use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

beforeEach(function () {
    $this->file = __DIR__.'/../fixtures/utf8.csv';

    $this->csv = new CommaSeparatedValues($this->file);
});

describe('CommaSeparatedValues for UTF-8 csv', function () {

    test('getBasename returns correct basename', function () {
        expect($this->csv->getBasename())->toBe(pathinfo($this->file, PATHINFO_BASENAME));
    });

    test('withBom returns false', function () {
        expect($this->csv->withBom())->toBeFalse();
    });

    test('getEncoding returns UTF-8', function () {
        expect($this->csv->getEncoding())->toBe('UTF-8');
    });

    test('getHeaders returns correct headers', function () {
        expect($this->csv->getHeaders())->toBe(['id', 'name', 'city', 'score', 'remark']);
    });

    test('readRow returns correct first row', function () {
        $firstRow = $this->csv->readRow()->current();

        expect($firstRow)->toBe(['id' => '1', 'name' => 'Alice', 'city' => 'Tokyo', 'score' => '88', 'remark' => 'Good']);
    });

    test('readRows returns correct rows', function () {
        $rows = $this->csv->readRows(3)->current();
        expect(count($rows))->toBe(3);

        $rows = $this->csv->readRows(10)->current();
        expect(count($rows))->toBe(5);
    });

    test('readRow returns correct row number', function () {
        $no = 1;

        foreach ($this->csv->readRow([CommaSeparatedValues::OPTION_WITH_HEADER => false]) as $rowNumber => $_) {
            expect($rowNumber)->toBe($no++);
        }
    });

    test('readRows returns correct row number', function () {
        $no = 1;

        foreach ($this->csv->readRows(5, [CommaSeparatedValues::OPTION_WITH_HEADER => false]) as $rows) {
            foreach ($rows as $rowNumber => $_) {
                expect($rowNumber)->toBe($no++);
            }
        }
    });

});
