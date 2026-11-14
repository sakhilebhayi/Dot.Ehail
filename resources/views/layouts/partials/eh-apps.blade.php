{{--
    The shared "Dot Apps" switcher (dot-ui). $appsModel is the view model, built once per page by the shell
    (App\Support\Ui\AppsSwitcher::model: cache only on live trip screens, fail-soft everywhere); null prints nothing.
    $appsId is only needed when a page holds two (the side shells print one for the phone bar and one for the desktop strip).
--}}
@if (! empty($appsModel))
    @php($appsCompany = \App\Support\Ui\AppsSwitcher::companyFor(auth()->user()))
    <x-dot.switcher platform="ehail" :company="$appsCompany" :company-name="auth()->user()?->currentTeam?->name" :model="$appsModel" :id="$appsId ?? 'dsw-panel'" logos="/dot-ui/img/platforms" />
@endif
