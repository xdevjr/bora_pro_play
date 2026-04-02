<?php

test('security settings route is not available', function () {
    $this->get('/settings/security')->assertNotFound();
});

test('appearance settings route is not available', function () {
    $this->get('/settings/appearance')->assertNotFound();
});