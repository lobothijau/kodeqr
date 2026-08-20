{{--
    The free-tier and lapsed interstitial (M1-T6).

    A lapsed owner's code shows exactly what a free one shows, down to the footer.
    Nothing on this page is addressed to the owner, because the owner is the one
    person who is reliably not the one holding the phone.
--}}
@php
    // One number, and everything on the page that describes it derives from it. The
    // bar fills over exactly this wait; the fallback button appears a second after
    // the wait should already have ended.
    //
    // Five seconds, not the 2.5 the task file specifies, and knowingly outside its
    // "auto-redirects <= 3s" acceptance criterion — owner's call, logged in
    // docs/BACKLOG.md. 2.5s was not enough time to read a destination host, which is
    // the only reason this page exists.
    $seconds = 5;
@endphp
@include('redirect.layout', [
    'title' => __('redirect.splash.title'),
    'host' => $host,
    'path' => $path,
    'destination' => $destination,
    'refreshTo' => $destination,
    'refreshAfter' => (string) $seconds,
    'revealAfter' => (string) ($seconds + 1),
])
