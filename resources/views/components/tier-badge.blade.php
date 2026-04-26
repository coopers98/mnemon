@props(['tier' => 'raw'])

@php
    $isCompiled = in_array($tier, ['reviewed', 'consolidated']);
    $klass = $isCompiled ? 'is-rubric' : 'is-ink';
@endphp

<span class="pill {{ $klass }}"><span class="dot"></span>{{ $tier }}</span>
