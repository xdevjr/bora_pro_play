<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_deployment/trusted-proxies', function (Request $request) {
        return response()->json([
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
            'secure' => $request->isSecure(),
        ]);
    });
});

test('forwarded proxy headers are trusted', function () {
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.10',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.15',
        'HTTP_X_FORWARDED_HOST' => 'bora.example.com',
        'HTTP_X_FORWARDED_PORT' => '443',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->get('/_deployment/trusted-proxies');

    $response->assertSuccessful()
        ->assertJson([
            'host' => 'bora.example.com',
            'scheme' => 'https',
            'secure' => true,
        ]);
});
