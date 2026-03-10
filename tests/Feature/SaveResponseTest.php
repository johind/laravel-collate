<?php

use Illuminate\Http\Request;
use Johind\Collate\Facades\Collate;

it('can download a pdf', function () {
    $response = Collate::open(pdf_fixture('single-page.pdf'))
        ->download('test-download.pdf');

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->headers->get('Content-Disposition'))->toContain('test-download.pdf');
});

it('can stream a pdf inline', function () {
    $response = Collate::open(pdf_fixture('single-page.pdf'))
        ->stream('test-stream.pdf');

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('can get raw content', function () {
    $content = Collate::open(pdf_fixture('single-page.pdf'))->content();

    expect($content)->toBeString();
    expect(strlen($content))->toBeGreaterThan(0);
});

it('can return directly from controller via Responsable', function () {
    $pending = Collate::open(pdf_fixture('single-page.pdf'));

    expect($pending)->toBeInstanceOf(\Illuminate\Contracts\Support\Responsable::class);

    $response = $pending->toResponse(new Request);

    expect($response->getStatusCode())->toBe(200);
});
