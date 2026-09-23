<?php

test('guests are redirected from the dashboard to the login page', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});

test('the login page is reachable', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});
