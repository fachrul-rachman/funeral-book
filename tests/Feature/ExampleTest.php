<?php

test('returns a successful response', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertOk();
});
