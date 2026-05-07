<?php

declare(strict_types=1);

namespace Johind\Collate;

use InvalidArgumentException;

readonly class PageSize
{
    public function __construct(
        /** Width in PDF points, including /UserUnit scaling. */
        public float $width,

        /** Height in PDF points, including /UserUnit scaling. */
        public float $height,

        public float $userUnit = 1.0,
    ) {
        if ($width < 0 || $height < 0) {
            throw new InvalidArgumentException('Page dimensions must be greater than or equal to 0.');
        }

        if ($userUnit <= 0) {
            throw new InvalidArgumentException('Page user unit must be greater than 0.');
        }
    }

    public function widthInInches(): float
    {
        return $this->width / 72;
    }

    public function heightInInches(): float
    {
        return $this->height / 72;
    }

    public function widthInMillimeters(): float
    {
        return $this->widthInInches() * 25.4;
    }

    public function heightInMillimeters(): float
    {
        return $this->heightInInches() * 25.4;
    }
}
