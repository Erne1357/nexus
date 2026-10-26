<?php

namespace Tests\Feature;

use Livewire\Livewire;
use Tests\TestCase;

class CounterTest extends TestCase
{
    public function test_home_page_renders_counter(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeLivewire('counter');
    }

    public function test_counter_increments_and_decrements(): void
    {
        Livewire::test('counter')
            ->assertSet('count', 0)
            ->call('increment')
            ->call('increment')
            ->assertSet('count', 2)
            ->call('decrement')
            ->assertSet('count', 1);
    }
}
