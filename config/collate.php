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
    | These files are automatically cleaned up after each operation. When the
    | directory does not exist yet, it is created readable only by the user
    | running PHP, since it may temporarily hold decrypted documents.
    |
    */

    'temp_directory' => env('COLLATE_TEMP_DIR', storage_path('app/collate')),

    /*
    |--------------------------------------------------------------------------
    | Process Timeout
    |--------------------------------------------------------------------------
    |
    | The maximum number of seconds any qpdf process may run before it is
    | terminated. Increase this for large documents or slower workers, or
    | set it to 0 to disable the limit.
    |
    */

    'timeout' => env('COLLATE_TIMEOUT', 60),
];
