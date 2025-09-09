@php
  // Fallbacks from config/app.php and optional brand config
  $appName = config('app.name', 'Dream Drives');
  $logo = config('brand.email_logo_url'); // e.g., set per-environment/brand
@endphp

<tr>
<td class="email-masthead" align="center">
  @if($logo)
    <a href="{{ config('app.url') }}" class="email-masthead_name" style="text-decoration:none;">
      <img src="{{ $logo }}" alt="{{ $appName }}" style="height:42px; display:block;">
    </a>
  @else
    <a href="{{ config('app.url') }}" class="email-masthead_name">{{ $appName }}</a>
  @endif
</td>
</tr>
