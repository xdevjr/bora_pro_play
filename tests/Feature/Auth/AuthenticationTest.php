<?php

test('login screen is not available', function () {
    $this->get('/login')->assertNotFound();
});

test('login submission is not available', function () {
    $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ])->assertNotFound();
});

test('logout endpoint is not available', function () {
    $this->post('/logout')->assertNotFound();
});