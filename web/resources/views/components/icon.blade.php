@props(['name' => 'grid'])
@php
    $paths = [
        'grid' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'user' => 'M20 21a8 8 0 0 0-16 0 M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'pin' => 'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0 M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
        'building' => 'M3 21V9l9-7 9 7v12 M9 21v-8h6v8',
        'briefcase' => 'M3 7h18v14H3z M8 7V3h8v4 M3 12h18 M10 12v3h4v-3',
        'map' => 'm3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2z M9 3v16 M15 5v16',
        'graduation' => 'm2 8 10-5 10 5-10 5z M6 10v8c3 3 9 3 12 0v-8 M22 8v8',
        'trend' => 'm3 17 6-6 4 4 8-11 M15 4h6v6',
        'chart' => 'M3 10h4v11H3z M10 3h4v18h-4z M17 14h4v7h-4z',
        'receipt' => 'M5 3h14v19l-3-2-4 2-4-2-3 2z M8 7h8 M8 11h8 M8 15h5',
        'calendar' => 'M3 5h18v16H3z M7 3v4 M17 3v4 M3 10h18 M7 14h3 M14 14h3',
        'chevron' => 'm9 5 7 7-7 7', 'search' => 'M16 10a6 6 0 1 1-12 0 6 6 0 0 1 12 0 M15 15l6 6',
        'filter' => 'M4 7h16 M7 12h10 M10 17h4', 'plus' => 'M12 5v14 M5 12h14',
        'close' => 'm6 6 12 12 M6 18 18 6', 'menu' => 'M3 6h18 M3 12h18 M3 18h18',
        'bell' => 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
        'arrow' => 'M5 12h14 m-6-6 6 6-6 6', 'back' => 'M19 12H5 m6-6-6 6 6 6',
        'check' => 'm5 12 4 4L19 6', 'info' => 'M12 11v6 M12 7h.01 M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        'shield' => 'm12 2 8 4v6c0 5-8 10-8 10S4 17 4 12V6z m-4 10 3 3 5-6',
        'layers' => 'm2 7 10-5 10 5-10 5z M2 12l10 5 10-5 M2 17l10 5 10-5',
        'download' => 'M12 3v12 m-5-5 5 5 5-5 M3 16v5h18v-5', 'minus' => 'M5 12h14',
        'eye' => 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12 M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
    ];
@endphp
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['grid'] }}" /></svg>
