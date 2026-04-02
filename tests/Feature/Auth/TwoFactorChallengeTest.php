<?php

test('two factor challenge screen is not available', function () {
    $this->get('/two-factor-challenge')->assertNotFound();
});

test('two factor challenge submission is not available', function () {
    $this->post('/two-factor-challenge', [
        'code' => '123456',
    ])->assertNotFound();
});