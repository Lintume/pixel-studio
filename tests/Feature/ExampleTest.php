<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_leads_to_the_studio()
    {
        $this->get('/')->assertRedirect('/studio');
    }
}
