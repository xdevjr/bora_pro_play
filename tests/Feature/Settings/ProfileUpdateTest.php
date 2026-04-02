<?php

test('profile settings route is not available', function () {
    $this->get('/settings/profile')->assertNotFound();
});