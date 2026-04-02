<?php

test('confirm password screen is not available', function () {
    $this->get('/user/confirm-password')->assertNotFound();
});

test('confirm password submission is not available', function () {
    $this->post('/user/confirm-password', [
        'password' => 'password',
    ])->assertNotFound();
});