{{--
    The free-tier and lapsed interstitial (M1-T6).

    A lapsed owner's code shows exactly what a free one shows, minus any mention of
    money: "Paket Anda telah berakhir" addressed to a stranger reads as nonsense, and
    it tells a business's customers that the business did not pay. The nudge that
    replaces it is legible to the owner and invisible to everybody else.
--}}
@include('redirect.layout', [
    'title' => __('redirect.splash.title'),
    'host' => $host,
    'destination' => $destination,
    'refreshTo' => $destination,
    // Long enough to read the host, short enough that the whole scan stays inside
    // the three seconds the acceptance criterion allows on a real phone.
    'refreshAfter' => '2.5',
    // The footer is echoed raw so the brand can be a link, so the URL inside it is
    // escaped here: url() derives from the request Host, which is not ours to trust.
    'footer' => $managed
        ? __('redirect.splash.managed', ['brand' => '<a href="'.e(route('login')).'">kodeqr</a>'])
        : __('redirect.splash.cta', ['brand' => '<a href="https://kodeqr.com">kodeqr.com</a>']),
])
