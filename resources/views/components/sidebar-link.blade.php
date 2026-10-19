@props(['href', 'active' => false])

<a href="{{ $href }}" {{ $attributes->merge([
    'class' => ($active
        ? 'bg-slate-800 text-white '
        : 'text-slate-400 hover:bg-slate-800/60 hover:text-white ')
        .'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
]) }}>
    {{ $icon ?? '' }}
    {{ $slot }}
</a>
