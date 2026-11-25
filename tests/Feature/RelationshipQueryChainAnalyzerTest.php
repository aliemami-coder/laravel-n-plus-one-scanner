<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Analyzers\RelationshipQueryChainAnalyzer;
use NPlusOneScanner\Data\ModelDefinition;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Tests\TestCase;

class RelationshipQueryChainAnalyzerTest extends TestCase
{
    public function test_detects_chained_relationship_queries_and_suggests_appropriate_aggregates(): void
    {
        $registry = new ModelRegistry;
        $registry->register(new ModelDefinition(
            fqn: 'App\\Models\\User',
            shortName: 'User',
            file: 'app/Models/User.php',
            relations: [
                'posts' => 'App\\Models\\Post',
                'comments' => 'App\\Models\\Comment',
                'orders' => 'App\\Models\\Order',
            ],
        ));

        $code = <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\User;

        class UserController
        {
            public function index()
            {
                $users = User::with('posts')->get();
                foreach ($users as $user) {
                    $c = $user->posts()->where('published', true)->count();
                    $f = $user->posts()->latest()->first();
                    $e = $user->comments()->whereNull('deleted_at')->exists();
                    $s = $user->orders()->sum('amount');
                    $a = $user->orders()->avg('amount');
                    $mx = $user->orders()->max('amount');
                    $mn = $user->orders()->min('amount');
                }
            }
        }
        PHP;

        $analyzer = new RelationshipQueryChainAnalyzer($registry);
        $issues = $analyzer->analyze('app/Http/Controllers/UserController.php', $code);

        $this->assertCount(7, $issues);
        $this->assertStringContainsString("withCount('posts')", $issues[0]->suggestion);
        $this->assertStringContainsString("with('posts')", $issues[1]->suggestion);
        $this->assertStringContainsString("withExists('comments')", $issues[2]->suggestion);
        $this->assertStringContainsString("withSum('orders', 'amount')", $issues[3]->suggestion);
        $this->assertStringContainsString("withAvg('orders', 'amount')", $issues[4]->suggestion);
        $this->assertStringContainsString("withMax('orders', 'amount')", $issues[5]->suggestion);
        $this->assertStringContainsString("withMin('orders', 'amount')", $issues[6]->suggestion);
    }

    public function test_stops_at_query_execution_boundary_and_does_not_flag_collection_methods(): void
    {
        $registry = new ModelRegistry;
        $registry->register(new ModelDefinition(
            fqn: 'App\\Models\\User',
            shortName: 'User',
            file: 'app/Models/User.php',
            relations: ['posts' => 'App\\Models\\Post'],
        ));

        $code = <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\User;

        class UserController
        {
            public function index()
            {
                $users = User::all();
                foreach ($users as $user) {
                    $post = $user->posts()
                        ->where('published', true)
                        ->get()
                        ->sortBy('created_at')
                        ->first();
                }
            }
        }
        PHP;

        $analyzer = new RelationshipQueryChainAnalyzer($registry);
        $issues = $analyzer->analyze('app/Http/Controllers/UserController.php', $code);

        // Only ->get() executes a SQL query; ->first() on the resulting Collection must NOT be flagged.
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('->get()', $issues[0]->message);
    }
}
