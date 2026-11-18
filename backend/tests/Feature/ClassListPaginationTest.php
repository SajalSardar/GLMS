<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassListPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_index_is_paginated(): void
    {
        for ($i = 1; $i <= 18; $i++) {
            SchoolClass::create(['name' => "Class {$i}"]);
        }

        $response = $this->getJson('/api/v1/classes');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 18);
    }

    public function test_class_index_filters_by_search(): void
    {
        SchoolClass::create(['name' => 'Six']);
        SchoolClass::create(['name' => 'Seven']);

        $this->getJson('/api/v1/classes?search=six')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Six');
    }
}
