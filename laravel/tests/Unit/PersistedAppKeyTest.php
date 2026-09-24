<?php

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/app_key_test_'.bin2hex(random_bytes(6)).'.key';
});

afterEach(function () {
    @unlink($this->file);
});

it('returns the trimmed key from the file', function () {
    file_put_contents($this->file, "base64:some-generated-key=\n");

    expect(persisted_app_key($this->file))->toBe('base64:some-generated-key=');
});

it('returns null when the file does not exist', function () {
    expect(persisted_app_key($this->file))->toBeNull();
});

it('returns null when the file is empty', function () {
    file_put_contents($this->file, '');

    expect(persisted_app_key($this->file))->toBeNull();
});
