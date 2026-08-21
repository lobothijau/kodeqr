{{--
    Constraint 10: every user-facing failure in this codebase is a branded Bahasa
    page. Laravel's stock 419 is an English framework page, and the person seeing it
    has just lost a typed abuse report — the worst possible moment to be handed an
    error in a language they may not read.
--}}
@include('redirect.layout', [
    'title' => __('errors.419.title'),
    'body' => __('errors.419.body'),
    'icon' => 'clock',
    'tone' => 'warning',
])
