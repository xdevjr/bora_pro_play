<?php

test('email verification notice is not available', function () {
    $this->get('/email/verify')->assertNotFound();
});

test('email verification notification endpoint is not available', function () {
    $this->post('/email/verification-notification')->assertNotFound();
});

test('email verification link endpoint is not available', function () {
    $this->get('/email/verify/1/test-hash')->assertNotFound();
});