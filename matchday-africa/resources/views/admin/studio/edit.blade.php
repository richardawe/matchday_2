<form method="POST" action="{{ route('admin.studio.update', $story) }}" style="margin-bottom:1rem">@csrf
 <b>{{ strtoupper($story->lang) }}</b>
 @if(empty($radio))
  <label style="display:block">Hook <small>(on screen 0-3 s; blank = from the data: “{{ $story->generated_hook }}”)</small>
   <input name="hook" value="{{ $story->hook }}" maxlength="120" style="width:100%"></label>
  <label style="display:block">Banter <small>(blank = “{{ $story->generated_banter }}”)</small>
   <input name="banter" value="{{ $story->banter }}" maxlength="180" style="width:100%"></label>
 @endif
 @foreach(['tiktok', 'instagram', 'x', 'youtube'] as $channel)
  <label style="display:block">{{ $channel === 'x' ? 'X' : ($channel === 'tiktok' ? 'TikTok' : ($channel === 'youtube' ? 'YouTube' : 'Instagram')) }} caption
   <textarea name="captions[{{ $channel }}]" rows="3" style="width:100%">{{ $story->captions[$channel] ?? '' }}</textarea></label>
 @endforeach
 @if($story->captions_edited)<small>Captions edited by hand: a re-render keeps them.</small><br>@endif
 <label><input type="checkbox" name="rerender" value="1"> Render again</label>
 <button type="submit">Save</button>
</form>
