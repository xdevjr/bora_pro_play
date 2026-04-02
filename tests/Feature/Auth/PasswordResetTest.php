<?php

test('forgot password screen is not available', function () {
    $this->get('/forgot-password')->assertNotFound();
});

test('forgot password submission is not available', function () {
    $this->post('/forgot-password', [
        'email' => 'test@example.com',
    ])->assertNotFound();
});

test('reset password screen is not available', function () {
    $this->get('/reset-password/test-token')->assertNotFound();
});

test('reset password submission is not available', function () {
    $this->post('/reset-password', [
        'token' => 'test-token',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});