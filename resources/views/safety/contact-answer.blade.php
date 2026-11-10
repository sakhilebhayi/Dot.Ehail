<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="description" content="Answer a Dot.Ehail trusted-contact request.">
    <title>Trusted contact | Dot.Ehail</title>
    @include('safety.partials.public-style')
</head>
<body>
<main class="eh-wrap">
    <p class="eh-mark">Dot.Ehail trusted contact</p>
    <div class="eh-board">
        <section class="eh-state">
            @if(! $valid)
                <h1>This link is not valid</h1>
                <p>It may have expired or been replaced by a newer email. Ask {{ $ownerFirstName === 'Someone' ? 'the person' : $ownerFirstName }} to send it again.</p>
            @elseif($mode === 'confirm' && $done)
                <h1>You are a trusted contact</h1>
                <p>If {{ $ownerFirstName }} presses the emergency button or shares a trip with you, Dot.Ehail will email you. Every email has a link to stop.</p>
            @elseif($mode === 'confirm')
                <h1>Be {{ $ownerFirstName }}’s trusted contact?</h1>
                <p>Dot.Ehail will email you if {{ $ownerFirstName }} presses the emergency button during a trip or shares a trip with you. Nothing else.</p>
            @elseif($done)
                <h1>You will not be contacted</h1>
                <p>Dot.Ehail will not send you alerts or trip links for {{ $ownerFirstName }}.</p>
            @else
                <h1>Stop alerts about {{ $ownerFirstName }}?</h1>
                <p>You will no longer receive emergency alerts or trip links for {{ $ownerFirstName }} from Dot.Ehail.</p>
            @endif
        </section>
        @if($valid && ! $done && $action)
            <section>
                <form method="POST" action="{{ $action }}">
                    @csrf
                    <button type="submit" class="eh-button">{{ $mode === 'confirm' ? 'Yes, I agree' : 'Stop alerts' }}</button>
                </form>
            </section>
        @endif
    </div>
</main>
</body>
</html>
