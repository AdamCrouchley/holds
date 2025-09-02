@props(['type' => 'info'])
@php
  $styles = [
    'info'    => 'bg-sky-50 text-sky-800 ring-sky-200',
    'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    'warn'    => 'bg-amber-50 text-amber-900 ring-amber-200',
    'error'   => 'bg-rose-50 text-rose-800 ring-rose-200',
  ][$type] ?? 'bg-slate-50 text-slate-800 ring-slate-200';
@endphp
<div {{ $attributes->merge(['class' => "rounded-xl ring-1 p-3 md:p-4 {$styles}"]) }}>
  {{ $slot }}
</div>
