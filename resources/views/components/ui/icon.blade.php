@props(['name'])
@php
$paths = [
    'play' => 'm9 5 11 7-11 7V5Z',
    'library' => 'M4 4h16v16H4z M9 8l6 4-6 4V8Z',
    'upload' => 'M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5',
    'settings' => 'M4 7h16M4 17h16M8 4v6m8 4v6',
    'lock' => 'M7 10V7a5 5 0 0 1 10 0v3M5 10h14v11H5zM12 14v3',
    'chevron' => 'm6 9 6 6 6-6',
    'back' => 'M20 12H4m6-6-6 6 6 6',
    'logout' => 'M9 4H4v16h5m6-12 4 4-4 4M9 12h10',
    'calendar' => 'M4 5h16v16H4zM8 3v4m8-4v4M4 10h16',
    'clock' => 'M12 7v5l3 2M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z',
    'film' => 'M4 3h16v18H4zM8 3v18m8-18v18M4 8h4m8 0h4M4 16h4m8 0h4',
    'check' => 'm5 12 4 4L19 6',
    'warning' => 'm12 3 10 18H2L12 3Zm0 6v5m0 3v.5',
];
@endphp
<svg {{ $attributes->class('hr-icon') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['play'] }}"/></svg>
