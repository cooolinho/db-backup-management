UI source strings are written in German directly in the code (matching `APP_LOCALE=de`, the
project default), wrapped in `__('...')`. `en.json` provides the English translation for every one
of them, looked up via Laravel's JSON translator when `APP_LOCALE=en`. Filament's own chrome
(buttons, table controls, the login form, ...) already ships German and English translations in
the package itself and needs no entries here.

`Audit::record()` description strings are the one deliberate exception: they are stored data, not
live UI chrome, and are always written in German regardless of locale.

`tests/Feature/Support/TranslationCoverageTest.php` scans `app/` for every `__('...')` literal and
fails if `en.json` is missing an entry for it, and separately renders the dashboard under
`APP_LOCALE=en` to catch a broken lookup. Run it after adding any new translatable string.
