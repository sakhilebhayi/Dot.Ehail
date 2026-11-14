@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="{{ asset('images/logo-light.png') }}" class="logo" alt="Dot.Ehail" width="96" height="72">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
