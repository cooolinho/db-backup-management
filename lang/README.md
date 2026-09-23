UI source strings are written in German directly in the code (matching `APP_LOCALE=de`, the
project default). `en.json` provides the English translations looked up via Laravel's JSON
translator when `APP_LOCALE=en`. Filament's own chrome (buttons, table controls, the login
form, ...) already ships German and English translations in the package itself and needs no
entries here.
