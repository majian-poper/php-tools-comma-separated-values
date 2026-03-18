<?php

declare(strict_types=1);

use PHPTools\CommaSeparatedValues\CommaSeparatedValues;

describe('CommaSeparatedValues position management', function () {

    describe('withBom() and getEncoding()', function () {

        test('restores cursor position on BOM file (called from position 0)', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8-bom.csv');

            $positionBefore = $csv->ftell();
            $csv->withBom();
            expect($csv->ftell())->toBe($positionBefore);

            $positionBefore = $csv->ftell();
            $csv->getEncoding();
            expect($csv->ftell())->toBe($positionBefore);
        });

        test('restores cursor position on non-BOM file (called from position 0)', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8.csv');

            $positionBefore = $csv->ftell();
            $csv->withBom();
            expect($csv->ftell())->toBe($positionBefore);

            $positionBefore = $csv->ftell();
            $csv->getEncoding();
            expect($csv->ftell())->toBe($positionBefore);
        });

        test('restores cursor position when called from a non-zero position', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8-bom.csv');

            // advance cursor before calling for the first time
            $csv->fgets();
            $positionBefore = $csv->ftell();

            $csv->withBom();
            expect($csv->ftell())->toBe($positionBefore);

            $csv->fgets();
            $positionBefore = $csv->ftell();

            $csv->getEncoding();
            expect($csv->ftell())->toBe($positionBefore);
        });

        test('restores cursor position on empty file (early return via finally)', function () {
            $tmpFile = \tempnam(\sys_get_temp_dir(), 'csv-test-');

            try {
                $csv = new CommaSeparatedValues($tmpFile);

                $positionBefore = $csv->ftell();
                $result = $csv->withBom();
                expect($result)->toBeFalse();
                expect($csv->ftell())->toBe($positionBefore);
            } finally {
                @\unlink($tmpFile);
            }
        });

        test('cached call does not change cursor position', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8-bom.csv');

            $csv->withBom();    // warm withBom cache
            $csv->getEncoding(); // warm getEncoding cache

            // advance cursor after caches are warmed
            $csv->fgets();
            $positionBefore = $csv->ftell();

            $csv->withBom();    // hits cache, no file I/O
            expect($csv->ftell())->toBe($positionBefore);

            $csv->getEncoding(); // hits cache, no file I/O
            expect($csv->ftell())->toBe($positionBefore);
        });

        test('does not interfere with subsequent readRow()', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8-bom.csv');

            $csv->withBom();
            $csv->getEncoding();

            $firstRow = $csv->readRow()->current();

            expect($firstRow)->toBe(['id' => '1', 'name' => 'Alice', 'city' => 'Tokyo', 'score' => '88', 'remark' => 'Good']);
        });

        test('multiple calls before readRow() do not affect row count', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8-bom.csv');

            $csv->withBom();
            $csv->withBom();
            $csv->getEncoding();
            $csv->getEncoding();

            expect(iterator_to_array($csv->readRow()))->toHaveCount(5);
        });

    });

    // readRow() uses $converted which equals $this for UTF-8 non-BOM files,
    // so this describe verifies position management on the original file cursor.
    describe('readRow()', function () {

        test('cursor position is unchanged between yields during iteration and full iteration', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8.csv');

            $positionBefore = $csv->ftell();

            foreach ($csv->readRow() as $row) {
                // cursor should be restored to pre-readRow position after each yield
                expect($csv->ftell())->toBe($positionBefore);
            }

            expect($csv->ftell())->toBe($positionBefore);
        });

        test('cursor position is restored after early break', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8.csv');

            $positionBefore = $csv->ftell();

            foreach ($csv->readRow() as $row) {
                break; // abandon generator after first row
            }

            expect($csv->ftell())->toBe($positionBefore);
        });

        test('two concurrent generators do not interfere with each other', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8.csv');

            $gen1 = $csv->readRow([CommaSeparatedValues::OPTION_WITH_HEADER => false]);
            $gen2 = $csv->readRow([CommaSeparatedValues::OPTION_WITH_HEADER => false]);

            $rows1 = [];
            $rows2 = [];

            // interleave: advance gen1 and gen2 alternately
            while ($gen1->valid() || $gen2->valid()) {
                if ($gen1->valid()) {
                    $rows1[] = $gen1->current();
                    $gen1->next();
                }
                if ($gen2->valid()) {
                    $rows2[] = $gen2->current();
                    $gen2->next();
                }
            }

            // both generators should have read all 6 rows (header + 5 data rows)
            expect($rows1)->toHaveCount(6);
            expect($rows2)->toHaveCount(6);
            expect($rows1)->toBe($rows2);
        });

        test('multiple sequential readRow() calls each return all rows', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8.csv');

            $first  = iterator_to_array($csv->readRow());
            $second = iterator_to_array($csv->readRow());

            expect($first)->toHaveCount(5);
            expect($second)->toHaveCount(5);
            expect($first)->toBe($second);
        });

        test('finally clears recorded positions when generator is abandoned via unset()', function () {
            $csv = new CommaSeparatedValues(__DIR__.'/../fixtures/utf8.csv');

            $positionBefore = $csv->ftell();

            $gen = $csv->readRow([CommaSeparatedValues::OPTION_WITH_HEADER => false]);
            $gen->current(); // suspend at first yield
            $gen->next();    // suspend at second yield

            unset($gen); // triggers generator __destruct → finally block runs

            // finally must have called restorePosition($methodTag) → cursor restored
            expect($csv->ftell())->toBe($positionBefore);

            // subsequent readRow must still return all rows (no stale position tags)
            expect(iterator_to_array($csv->readRow([CommaSeparatedValues::OPTION_WITH_HEADER => false])))->toHaveCount(6);
        });
    });
});
