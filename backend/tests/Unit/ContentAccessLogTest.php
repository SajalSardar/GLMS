<?php

namespace Tests\Unit;

use App\Models\ContentAccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentAccessLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_log_row_can_be_created_with_only_a_book_id(): void
    {
        $log = ContentAccessLog::create([
            'book_id' => 1,
            'action' => 'downloaded',
        ]);

        $this->assertNotNull($log->created_at);
        $this->assertNull($log->chapter_id);
        $this->assertNull($log->chapter_material_id);
        $this->assertNull($log->user_id);
    }

    public function test_the_model_has_no_updated_at_column(): void
    {
        $log = ContentAccessLog::create(['book_id' => 1, 'action' => 'previewed']);

        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }
}
