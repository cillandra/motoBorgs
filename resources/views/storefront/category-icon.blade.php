<svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch(strtolower($product->category?->name??''))
    @case('brakes')
        <circle cx="24" cy="24" r="17"/><circle cx="24" cy="24" r="6"/><circle cx="24" cy="12" r="1.5"/><circle cx="12" cy="24" r="1.5"/><circle cx="24" cy="36" r="1.5"/><path d="M36 16h6v16h-6z"/>
        @break
    @case('tires')
        <circle cx="24" cy="24" r="18"/><circle cx="24" cy="24" r="11"/><path d="m18 7 2 6m9-6-2 6m14 6-6 2m6 9-6-2m-5 14-2-6m-9 6 2-6M7 30l6-2M7 19l6 2"/>
        @break
    @case('drivetrain')
        <path d="m20 14 5-5a10 10 0 0 1 14 14l-5 5M28 34l-5 5A10 10 0 0 1 9 25l5-5m3 11 14-14"/><path d="m17 12 3 3m13 18 3 3"/>
        @break
    @case('electrical')
        <path d="M27 5 10 27h12l-1 16 17-23H26l1-15z"/>
        @break
    @case('lubricants')
        <path d="M17 6h14v7H17zM18 13l-6 8v20h24V21l-6-8M12 26h24"/><path d="M24 29s-5 5-5 7a5 5 0 0 0 10 0c0-2-5-7-5-7z"/>
        @break
    @case('accessories')
        <path d="M7 28a17 17 0 0 1 34 0v7H26l-5-9H7v2zm0 0v9h15M27 20h13m-12 0-3 7h16"/>
        @break
    @case('controls')
        <path d="m7 14 7 4 10 14 10-14 7-4M4 12l7 4m26 0 7-4M18 38h12M24 32v6"/><circle cx="24" cy="24" r="3"/>
        @break
    @default
        <path d="M15 15h16l5 6v14H15l-5-6V21h5zm5 0V8h9m-7 27v5m10-5v5M5 21v10m0-5h5m26-1h7v9h-7M20 8h12"/>
@endswitch
</svg>
