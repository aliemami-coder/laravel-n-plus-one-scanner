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
}
