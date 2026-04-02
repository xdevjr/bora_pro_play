<?php

test('registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('registration submission is not available', function () {
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});