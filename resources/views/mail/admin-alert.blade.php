<x-mail::message>
# {{ $resolved ? 'Resolved: ' : '' }}{{ $title }}

{{ $details }}

@if ($action)
**What to do:** {{ $action }}
@endif

Environment: {{ $environment }} · {{ $url }} · {{ now()->toDayDateTimeString() }} UTC

@unless ($resolved)
You'll get a reminder every hour while this continues, and a "Resolved" email when it recovers (where detectable).
@endunless
</x-mail::message>
