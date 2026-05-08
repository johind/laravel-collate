<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        test()->skip('qpdf binary not available');
    }

    Storage::fake('local');
});

it('preserves named destinations from a secondary merge input', function (): void {
    Storage::put('cover.pdf', namedDestinationMergeCoverPdf());
    Storage::put('contents.pdf', namedDestinationMergeContentsPdf());

    makeCollate()->merge('cover.pdf', 'contents.pdf')->save('report.pdf');

    $json = storageQpdfJson('report.pdf');
    $objects = $json['qpdf'][1];
    $catalog = namedDestinationMergeCatalog($json);

    expect($catalog)->toHaveKey('/Names');

    $namesRef = $catalog['/Names'];
    $namesObject = $objects['obj:'.$namesRef]['value'];
    $destsRef = $namesObject['/Dests'];
    $destsObject = $objects['obj:'.$destsRef]['value'];
    $destination = $destsObject['/Names'][1];
    $targetPage = collect($json['pages'])->first(
        fn (array $page): bool => ($page['pageposfrom1'] ?? null) === 3,
    );

    expect($json['pages'])->toHaveCount(3)
        ->and($destsObject['/Names'][0])->toBe('u:section1')
        ->and($destination[0])->toBe($targetPage['object']);
});

/**
 * @return array<string, mixed>
 */
function namedDestinationMergeCatalog(array $json): array
{
    $objects = $json['qpdf'][1];
    $rootRef = $objects['trailer']['value']['/Root'];

    return $objects['obj:'.$rootRef]['value'];
}

function namedDestinationMergeCoverPdf(): string
{
    return namedDestinationMergePdf([
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        namedDestinationMergeStream('BT /F1 24 Tf 72 720 Td (Clean cover page) Tj ET'),
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ]);
}

function namedDestinationMergeContentsPdf(): string
{
    return namedDestinationMergePdf([
        '<< /Type /Catalog /Pages 2 0 R /Names 9 0 R >>',
        '<< /Type /Pages /Kids [3 0 R 7 0 R] /Count 2 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R /Annots [6 0 R] >>',
        namedDestinationMergeStream('BT /F1 18 Tf 72 720 Td (Table of contents) Tj 0 -40 Td (Clickable section link) Tj ET'),
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Type /Annot /Subtype /Link /Rect [72 670 260 700] /Border [0 0 0] /A << /S /GoTo /D (section1) >> >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 8 0 R >>',
        namedDestinationMergeStream('BT /F1 24 Tf 72 720 Td (Section target page) Tj ET'),
        '<< /Dests 10 0 R >>',
        '<< /Names [(section1) [7 0 R /Fit]] >>',
    ]);
}

/**
 * @param  list<string>  $objects
 */
function namedDestinationMergePdf(array $objects): string
{
    $pdf = "%PDF-1.7\n%----\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
        $offsets[] = mb_strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
    }

    $xref = mb_strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

    for ($object = 1; $object <= count($objects); $object++) {
        $pdf .= mb_str_pad((string) $offsets[$object], 10, '0', STR_PAD_LEFT)." 00000 n \n";
    }

    return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

function namedDestinationMergeStream(string $contents): string
{
    return '<< /Length '.mb_strlen($contents)." >>\nstream\n{$contents}\nendstream";
}
