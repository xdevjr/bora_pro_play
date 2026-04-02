<?php

test('dashboard route is not available', function () {
    $this->get('/dashboard')->assertNotFound();
});