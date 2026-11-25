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

    public function test_resets_eager_loads_on_reassignment_and_transfers_across_query_and_aliases(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\User;

        class UserController
        {
            public function reassigned()
            {
                $users = User::with('posts')->get();
                $users = User::all();

                foreach ($users as $user) {
                    echo $user->posts;
                }
            }

            public function aliasedQuery()
            {
                $query = User::query()->with('posts');
                $users = $query->get();
                $items = $users;

                foreach ($items as $item) {
                    echo $item->posts;
                }
            }
        }
        PHP;

        $analyzer = new PhpLoopAnalyzer($this->makeRegistry());
        $issues = $analyzer->analyze('app/Http/Controllers/UserController.php', $code);

        $this->assertCount(1, $issues);
        $this->assertSame(14, $issues[0]->line);
        $this->assertSame('posts', $issues[0]->relation);
    }

    public function test_merges_conditional_branches_using_intersection_of_eager_loads(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\User;

        class UserController
        {
            public function partialEagerLoad(bool $flag)
            {
                if ($flag) {
                    $users = User::with('posts')->get();
                } else {
                    $users = User::all();
                }

                foreach ($users as $user) {
                    echo $user->posts;
                }
            }

            public function fullEagerLoad(bool $flag)
            {
                if ($flag) {
                    $users = User::with('posts')->get();
                } else {
                    $users = User::query()->with('posts')->get();
                }

                foreach ($users as $user) {
                    echo $user->posts;
                }
            }
        }
        PHP;

        $analyzer = new PhpLoopAnalyzer($this->makeRegistry());
        $issues = $analyzer->analyze('app/Http/Controllers/UserController.php', $code);

        $this->assertCount(1, $issues);
        $this->assertSame(17, $issues[0]->line);
    }

    public function test_detects_load_and_load_missing_and_load_aggregates_inside_loop(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\User;

        class UserController
        {
            public function bad()
            {
                $users = User::all();
                foreach ($users as $user) {
                    $user->loadMissing('posts');
                    echo $user->posts;
                    $user->load('posts');
                    $user->loadCount('posts');
                    $user->loadSum('orders', 'amount');
                }
            }

            public function alreadyLoadedBeforeLoop()
            {
                $users = User::with('posts')->get();
                foreach ($users as $user) {
                    $user->loadMissing('posts');
                    echo $user->posts;
                }
            }
        }
        PHP;

        $analyzer = new PhpLoopAnalyzer($this->makeRegistry());
        $issues = $analyzer->analyze('app/Http/Controllers/UserController.php', $code);

        $this->assertCount(4, $issues);
        foreach ($issues as $issue) {
            $this->assertSame(Issue::TYPE_EAGER_LOAD_IN_LOOP, $issue->type);
            $this->assertSame(Issue::SEVERITY_HIGH, $issue->severity);
        }
        $this->assertStringContainsString("Move `->loadMissing('posts')` before the loop", $issues[0]->suggestion);
        $this->assertStringContainsString("withCount('posts')", $issues[2]->suggestion);
        $this->assertStringContainsString("withSum('orders', 'column')", $issues[3]->suggestion);
    }
}
