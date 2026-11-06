{{-- An in-app message to Beat the Griot fans: inbox always, phones by push when ticked. --}}
<form method="POST" action="{{ $action }}" class="md-admin-form" onsubmit="return confirm('Send this message to {{ number_format($count) }} {{ \Illuminate\Support\Str::plural('fan', $count) }} now? It cannot be unsent.')">
    @csrf
    <label for="griot-title-{{ $id }}">Title</label>
    <input id="griot-title-{{ $id }}" name="title" maxlength="120" required value="{{ old('title') }}">
    <label for="griot-body-{{ $id }}">Message</label>
    <textarea id="griot-body-{{ $id }}" name="body" maxlength="1000" rows="3" required>{{ old('body') }}</textarea>
    <label for="griot-url-{{ $id }}">Link (optional; Beat the Griot if empty)</label>
    <input id="griot-url-{{ $id }}" name="url" type="url" placeholder="{{ route('griot.challenge') }}" value="{{ old('url') }}">
    <label class="md-check"><input type="checkbox" name="push" value="1" {{ $pushReady ? '' : 'disabled' }}> Also send to their phones{{ $pushReady ? '' : ' (push is off)' }}</label>
    <button class="md-primary" type="submit">Send to {{ number_format($count) }} {{ \Illuminate\Support\Str::plural('fan', $count) }}</button>
</form>
