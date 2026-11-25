<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Scanner;
use NPlusOneScanner\Support\ParsedFileCache;
use NPlusOneScanner\Tests\TestCase;

class ParsedFileCacheTest extends TestCase
{
    public function test_parses_each_php_file_only_once_across_discovery_and_all_analyzers(): void
    {
        $projectDir = sys_get_temp_dir().'/nplusone_cache_'.uniqid();
        mkdir($projectDir.'/app/Models', 0755, true);
        mkdir($projectDir.'/app/Http/Controllers', 0755, true);

        file_put_contents($projectDir.'/app/Models/User.php', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Model;

        class User extends Model
        {
            public function posts()
            {
                return $this->hasMany(Post::class);
            }
        }
        PHP);

        file_put_contents($projectDir.'/app/Http/Controllers/UserController.php', <<<'PHP'
        <?php
        namespace App\Http\Controllers;
        use App\Models\User;

        class UserController
        {
            public function index()
            {
                foreach (User::all() as $user) {
                    echo $user->posts;
                }
            }
        }
        PHP);

        $cache = new ParsedFileCache;
        $scanner = new Scanner;
        $result = $scanner->scan($projectDir, [
            'parsed_file_cache' => $cache,
        ]);

        // Only 2 PHP files exist; each must be parsed exactly once despite discovery + 4 analyzers.
        $this->assertSame(2, $cache->getParseCount());
        $this->assertGreaterThanOrEqual(4, $cache->getCacheHits());
        $this->assertCount(1, $result->issues);

        unlink($projectDir.'/app/Models/User.php');
        unlink($projectDir.'/app/Http/Controllers/UserController.php');
        rmdir($projectDir.'/app/Http/Controllers');
        rmdir($projectDir.'/app/Http');
        rmdir($projectDir.'/app/Models');
        rmdir($projectDir.'/app');
        rmdir($projectDir);
    }
}
