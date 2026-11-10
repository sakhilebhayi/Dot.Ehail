@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="{{ asset('icons/icon-192.png') }}" class="logo" alt="Dot.Ehail" width="64" height="64">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
