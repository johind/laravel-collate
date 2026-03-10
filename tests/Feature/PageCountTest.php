<?php

use Johind\Collate\Facades\Collate;

it('can get page count', function () {
    $count = Collate::open(pdf_fixture('single-page.pdf'))->pageCount();

    expect($count)->toBe(1);
});

it('can get page count of multi-page pdf', function () {
    $count = Collate::open(pdf_fixture('multi-page.pdf'))->pageCount();

    expect($count)->toBe(5);
});

it('throws when getting page count without source', function () {
    Collate::merge()->pageCount();
})->throws(\BadMethodCallException::class);
