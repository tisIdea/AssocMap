<?php

return [
    // Absolute path to an approved Python 3.12 executable; no PATH lookup.
    'python' => env('REPORTING_PYTHON_EXECUTABLE', ''),
    'timeout' => 45,
    'max_rows' => 5000,
    'max_total_rows' => 20000,
    'max_json_bytes' => 8 * 1024 * 1024,
    'max_artifact_bytes' => 12 * 1024 * 1024,
    'concurrency' => 2,
    'font_regular' => env('REPORTING_FONT_REGULAR', ''),
    'font_bold' => env('REPORTING_FONT_BOLD', ''),
];
