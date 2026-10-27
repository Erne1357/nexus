<?php

it('no ofrece registro público', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});
