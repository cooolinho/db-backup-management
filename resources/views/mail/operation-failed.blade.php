<x-mail::message>
# {{ $operation }} fehlgeschlagen

**Datenbank:** {{ $database }}

**Fehler:**

<x-mail::panel>
{{ $error }}
</x-mail::panel>

<x-mail::button :url="$url">
Oberfläche öffnen
</x-mail::button>

Diese Nachricht wurde automatisch von {{ config('app.name') }} verschickt.
</x-mail::message>
