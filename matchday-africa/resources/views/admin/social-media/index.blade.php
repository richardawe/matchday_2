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
 @include('admin.social-media.table', ['rows' => $files, 'dateLabel' => 'Uploaded (Lagos)'])
 @endif

 <header class="md-xsection"><div><p class="md-eyebrow">IN THE REPOSITORY</p><h2>Files in the repo</h2>
  <p>Committed under <code>public/social/</code> and published with each deploy. Read-only here: change them with a commit.</p></div></header>
 @if($repoFiles->isEmpty())<p>No videos or images under <code>public/social/</code>.</p>@else
 @include('admin.social-media.table', ['rows' => $repoFiles, 'dateLabel' => 'Modified (Lagos)'])
 @endif
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
