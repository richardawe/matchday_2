{{-- One table of social files: name, public URL with Copy and Open, size, date. Used for uploads and repo files. --}}
<table class="md-signup-table"><thead><tr><th>File</th><th>Public URL</th><th>Size</th><th>{{ $dateLabel }}</th></tr></thead><tbody>
 @foreach($rows as $f)
  <tr>
   <td>{{ $f['video'] ? '🎬' : '🖼️' }} {{ $f['name'] }}</td>
   <td><input readonly value="{{ $f['url'] }}" aria-label="Public URL of {{ $f['name'] }}" onclick="this.select()" style="width:100%;min-width:16rem;font-family:monospace">
    <button type="button" data-copy="{{ $f['url'] }}">Copy</button> <a href="{{ $f['url'] }}" target="_blank" rel="noopener">Open</a></td>
   <td>{{ number_format($f['size'] / 1048576, 1) }} MB</td>
   <td>{{ \Carbon\Carbon::createFromTimestamp($f['modified'])->setTimezone('Africa/Lagos')->format('D j M Y H:i') }}</td>
  </tr>
 @endforeach
</tbody></table>
