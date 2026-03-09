<?php

use Johind\Collate\Facades\Collate;

it('can fake and assert a save', function () {
    Collate::fake();

    Collate::open('invoice.pdf')
        ->encrypt('secret')
        ->linearize()
        ->save('output/invoice.pdf');

    Collate::assertSaved('output/invoice.pdf');
});

it('can fake and assert nothing saved', function () {
    Collate::fake();

    Collate::open('invoice.pdf')->linearize();

    Collate::assertNothingSaved();
});

it('can fake and assert a download', function () {
    Collate::fake();

    Collate::open('report.pdf')->download('monthly-report.pdf');

    Collate::assertDownloaded('monthly-report.pdf');
});

it('can fake and assert a stream', function () {
    Collate::fake();

    Collate::open('report.pdf')->stream('preview.pdf');

    Collate::assertStreamed('preview.pdf');
});

it('can inspect recorded operations via callback', function () {
    Collate::fake();

    Collate::open('doc.pdf')
        ->rotate(90)
        ->password('secret')
        ->save('rotated.pdf');

    Collate::assertSaved(callback: fn ($pending) => $pending->isEncrypted());
});

it('can fake a merge', function () {
    Collate::fake();

    Collate::merge('a.pdf', 'b.pdf', 'c.pdf')->save('combined.pdf');

    Collate::assertSaved('combined.pdf');
});
