<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | QPDF Binary Path
    |--------------------------------------------------------------------------
    |
    | The path to the qpdf binary on your system. If qpdf is available in your
    | system's PATH, you may simply use 'qpdf'. Otherwise, provide the full
    | absolute path to the binary.
    |
    */

    'binary_path' => env('COLLATE_BINARY_PATH', 'qpdf'),

    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | The default filesystem disk to use when reading and writing PDFs. When
    | null, the application's default filesystem disk will be used.
    |
    */

    'default_disk' => env('COLLATE_DISK'),

    /*
    |--------------------------------------------------------------------------
    | Temporary Directory
    |--------------------------------------------------------------------------
    |
    | The directory where Collate will store temporary files during processing.
    | These files are automatically cleaned up after each operation.
    |
    */

    'temp_directory' => env('COLLATE_TEMP_DIR', storage_path('app/collate')),
];
