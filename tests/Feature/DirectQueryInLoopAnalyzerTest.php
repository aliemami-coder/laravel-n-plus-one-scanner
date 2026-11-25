<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Analyzers\DirectQueryInLoopAnalyzer;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ModelDefinition;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Tests\TestCase;

class DirectQueryInLoopAnalyzerTest extends TestCase
{
    public function test_detects_model_and_db_queries_inside_loops(): void
    {
        $registry = new ModelRegistry;
        $registry->register(new ModelDefinition(
            fqn: 'App\\Models\\Order',
            shortName: 'Order',
            file: 'app/Models/Order.php',
        ));

        $code = <<<'PHP'
        <?php
        use App\Models\Order;
        use Illuminate\Support\Facades\DB;

        foreach ($ids as $id) {
            $order = Order::find($id);
            $first = Order::where('id', $id)->first();
            $rows = DB::table('logs')->where('order_id', $id)->get();
        }
        PHP;

        $analyzer = new DirectQueryInLoopAnalyzer($registry);
        $issues = $analyzer->analyze('app/Services/SyncService.php', $code);

        $this->assertCount(3, $issues);
        foreach ($issues as $issue) {
            $this->assertSame(Issue::TYPE_QUERY_IN_LOOP, $issue->type);
            $this->assertSame(Issue::SEVERITY_HIGH, $issue->severity);
        }
    }
}
