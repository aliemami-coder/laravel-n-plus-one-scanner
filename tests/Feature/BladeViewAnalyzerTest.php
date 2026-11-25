<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Analyzers\BladeViewAnalyzer;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ModelDefinition;
use NPlusOneScanner\Discovery\ControllerViewMap;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Tests\TestCase;

class BladeViewAnalyzerTest extends TestCase
{
    public function test_detects_blade_lazy_loading_and_respects_controller_eager_load(): void
    {
        $registry = new ModelRegistry;
        $registry->register(new ModelDefinition(
            fqn: 'App\\Models\\User',
            shortName: 'User',
            file: 'app/Models/User.php',
            relations: ['posts' => 'Post', 'profile' => 'Profile'],
        ));

        $tempController = sys_get_temp_dir().'/nplusone_ctrl_'.uniqid().'.php';
        file_put_contents($tempController, <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\User;

        class UserController
        {
            public function index()
            {
                $users = User::with('profile')->get();
                return view('users.index', compact('users'));
            }
        }
        PHP);

        $viewMap = new ControllerViewMap($registry);
        $viewMap->scanFile($tempController);
        unlink($tempController);

        $blade = <<<'BLADE'
        <div>
            @foreach ($users as $user)
                <p>{{ $user->profile->bio }}</p>
                <span>{{ $user->posts->count() }}</span>
            @endforeach
        </div>
        BLADE;

        $analyzer = new BladeViewAnalyzer($registry, $viewMap);
        $issues = $analyzer->analyze('resources/views/users/index.blade.php', $blade);

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_BLADE_LAZY_LOADING, $issues[0]->type);
        $this->assertSame('posts', $issues[0]->relation);
        $this->assertSame(4, $issues[0]->line);
    }

    public function test_flags_blade_when_only_one_controller_method_eager_loads_relation(): void
    {
        $registry = new ModelRegistry;
        $registry->register(new ModelDefinition(
            fqn: 'App\\Models\\User',
            shortName: 'User',
            file: 'app/Models/User.php',
            relations: ['posts' => 'Post', 'profile' => 'Profile'],
        ));

        $tempController = sys_get_temp_dir().'/nplusone_multi_ctrl_'.uniqid().'.php';
        file_put_contents($tempController, <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\User;

        class UserController
        {
            public function index()
            {
                $users = User::with(['posts', 'profile'])->get();
                return view('users.index', compact('users'));
            }

            public function archive()
            {
                $users = User::with('profile')->get();
                return view('users.index', compact('users'));
            }
        }
        PHP);

        $viewMap = new ControllerViewMap($registry);
        $viewMap->scanFile($tempController);
        unlink($tempController);

        $this->assertCount(2, $viewMap->getCallSitesForView('users.index'));

        $blade = <<<'BLADE'
        <div>
            @foreach ($users as $user)
                <p>{{ $user->profile->bio }}</p>
                <span>{{ $user->posts->count() }}</span>
            @endforeach
        </div>
        BLADE;

        $analyzer = new BladeViewAnalyzer($registry, $viewMap);
        $issues = $analyzer->analyze('resources/views/users/index.blade.php', $blade);

        $this->assertCount(1, $issues);
        $this->assertSame('posts', $issues[0]->relation);
    }
}
