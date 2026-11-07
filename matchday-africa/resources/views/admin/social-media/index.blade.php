@extends('layouts.admin')
@section('title','Social media')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">SOCIAL MEDIA</p><h1>Social creatives.</h1><p>Upload a video or image (MP4, MOV, JPG or PNG, up to 200 MB) and copy its public link. No deploy needed.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }} @if(session('uploaded'))<a href="{{ session('uploaded') }}" target="_blank" rel="noopener">{{ session('uploaded') }}</a>@endif</p>@endif
 @if($errors->any())<p class="md-notice md-flash-error">{{ $errors->first() }}</p>@endif

 <form class="md-admin-form" method="POST" action="{{ route('admin.social-media.store') }}" enctype="multipart/form-data">
  @csrf
  <label>File
   <input type="file" name="file" accept=".mp4,.mov,.jpg,.jpeg,.png,video/mp4,video/quicktime,image/jpeg,image/png" required>
  </label>
  <p><small>Saved as <code>social/{{ now()->format('Y-m') }}/&lt;name&gt;-&lt;random&gt;.&lt;ext&gt;</code> on the public disk.
   Server limits: upload_max_filesize {{ $uploadLimit }}, post_max_size {{ $postLimit }}.
   @if($serverBytes < $maxBytes)<b>The server accepts less than 200 MB, so larger files will be refused until those PHP limits are raised (cPanel → MultiPHP INI Editor).</b>@endif
  </small></p>
  <button class="md-primary" type="submit">Upload</button>
 </form>

 <header class="md-xsection"><div><p class="md-eyebrow">UPLOADED</p><h2>Files</h2></div></header>
 @if($files->isEmpty())<p>Nothing uploaded yet.</p>@else
 <table class="md-signup-table"><thead><tr><th>File</th><th>Public URL</th><th>Size</th><th>Uploaded (Lagos)</th></tr></thead><tbody>
  @foreach($files as $f)
   <tr>
    <td>{{ $f['video'] ? '🎬' : '🖼️' }} {{ $f['name'] }}</td>
    <td><input readonly value="{{ $f['url'] }}" aria-label="Public URL of {{ $f['name'] }}" onclick="this.select()" style="width:100%;min-width:16rem;font-family:monospace">
     <button type="button" data-copy="{{ $f['url'] }}">Copy</button> <a href="{{ $f['url'] }}" target="_blank" rel="noopener">Open</a></td>
    <td>{{ number_format($f['size'] / 1048576, 1) }} MB</td>
    <td>{{ \Carbon\Carbon::createFromTimestamp($f['modified'])->setTimezone('Africa/Lagos')->format('D j M Y H:i') }}</td>
   </tr>
  @endforeach
 </tbody></table>@endif
</div>
<script>
document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-copy]');
  if (!button) return;
  const url = button.dataset.copy;
  try {
    await navigator.clipboard.writeText(url);
  } catch (e) {
    const input = button.previousElementSibling;
    input.select();
    document.execCommand('copy');
  }
  const label = button.textContent;
  button.textContent = 'Copied';
  setTimeout(() => { button.textContent = label; }, 1500);
});
</script>
@endsection
