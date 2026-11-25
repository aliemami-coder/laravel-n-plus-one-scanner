<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Analyzers\PhpLoopAnalyzer;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ModelDefinition;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Tests\TestCase;

class PhpLoopAnalyzerTest extends TestCase
{
    private function makeRegistry(): ModelRegistry
    {
        $registry = new ModelRegistry;
        $registry->register(new ModelDefinition(
            fqn: 'App\\Models\\User',
            shortName: 'User',
            file: 'app/Models/User.php',
            relations: ['posts' => 'Post', 'profile' => 'Profile'],
            defaultWith: ['profile'],
        ));
        $registry->register(new ModelDefinition(
            fqn: 'App\\Models\\Post',
            shortName: 'Post',
            file: 'app/Models/Post.php',
            relations: ['comments' => 'Comment'],
        ));

        return $registry;
    }

    public function test_detects_lazy_loading_in_foreach_and_collection_each(): void
    {
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
                    echo $user->posts->count();
                    echo $user->profile->bio; // defaultWith: safe
                }

                $users->each(function ($u) {
                    $u->posts;
                });
            }
        }
        PHP;

        $analyzer = new PhpLoopAnalyzer($this->makeRegistry());
        $issues = $analyzer->analyze('app/Http/Controllers/UserController.php', $code);

        $this->assertCount(2, $issues);
        $this->assertSame(Issue::TYPE_LAZY_LOADING_IN_LOOP, $issues[0]->type);
        $this->assertSame('posts', $issues[0]->relation);
        $this->assertSame(Issue::SEVERITY_HIGH, $issues[0]->severity);
    }

    public function test_passes_when_eager_loaded_with_with_or_load_missing_or_nested(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\User;

        class UserController
        {
            public function index()
            {
                $users = User::with('posts.comments')->get();
                foreach ($users as $user) {
                    foreach ($user->posts as $post) {
                        echo $post->comments->count();
                    }
                }

                $otherUsers = User::all();
                $otherUsers->loadMissing('posts');
                foreach ($otherUsers as $ou) {
                    $ou->posts;
                }
            }
        }
        PHP;

        $analyzer = new PhpLoopAnalyzer($this->makeRegistry());
        $issues = $analyzer->analyze('app/Http/Controllers/UserController.php', $code);

        $this->assertCount(0, $issues);
    }

    public function test_detects_relation_method_query_inside_loop(): void
    {
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
                    $count = $user->posts()->count();
                }
            }
        }
        PHP;

        $analyzer = new PhpLoopAnalyzer($this->makeRegistry());
        $issues = $analyzer->analyze('app/Http/Controllers/UserController.php', $code);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('->posts()->count()', $issues[0]->message);
    }
}
