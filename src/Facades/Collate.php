<?php

declare(strict_types=1);

namespace Johind\Collate\Facades;

use Illuminate\Support\Facades\Facade;
use Johind\Collate\CollateFake;

/**
 * @method static \Johind\Collate\Collate fromDisk(string $disk)
 * @method static \Johind\Collate\PendingCollate open(string|\Illuminate\Http\UploadedFile $file)
 * @method static \Johind\Collate\PendingCollate inspect(string|\Illuminate\Http\UploadedFile $file)
 * @method static \Johind\Collate\PendingCollate merge(\Closure|string|\Illuminate\Http\UploadedFile|array<int, string|\Illuminate\Http\UploadedFile> ...$files)
 *
 * @see \Johind\Collate\Collate
 */
class Collate extends Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(): CollateFake
    {
        return tap(new CollateFake, function ($fake): void {
            static::swap($fake);
        });
    }

    protected static function getFacadeAccessor(): string
    {
        return \Johind\Collate\Collate::class;
    }
}
