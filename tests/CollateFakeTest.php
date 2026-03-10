<?php

use Johind\Collate\Facades\Collate;

it('can fake and assert a save', function () {
    Collate::fake();

    Collate::open('invoice.pdf')
        ->encrypt('secret')
        ->save('output/invoice.pdf');

    Collate::assertSaved('output/invoice.pdf');
});

it('can fake and assert a download', function () {
    Collate::fake();

    Collate::open('report.pdf')->download('monthly-report.pdf');

    Collate::assertDownloaded('monthly-report.pdf');
});
