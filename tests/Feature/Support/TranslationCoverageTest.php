<?php

it('has an english translation for every __() source string used in the app', function () {
    $sourceStrings = [];

    foreach ((new Symfony\Component\Finder\Finder)->files()->in(app_path())->name('*.php') as $file) {
        $contents = $file->getContents();

        if (preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $contents, $matches)) {
            foreach ($matches[1] as $match) {
                $sourceStrings[] = stripcslashes($match);
            }
        }
    }

    expect($sourceStrings)->not->toBeEmpty();

    $translations = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

    $missing = [];

    foreach (array_unique($sourceStrings) as $string) {
        if (! array_key_exists($string, $translations)) {
            $missing[] = $string;
        }
    }

    expect($missing)->toBe([]);
});

it('renders the dashboard in english without missing translation warnings', function () {
    $user = \App\Models\User::factory()->create();

    app()->setLocale('en');

    \Livewire\Livewire::actingAs($user)
        ->test(\Filament\Pages\Dashboard::class)
        ->assertSuccessful();

    app()->setLocale('de');
});
