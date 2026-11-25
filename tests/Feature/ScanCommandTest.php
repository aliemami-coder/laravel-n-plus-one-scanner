<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Tests\TestCase;

class ScanCommandTest extends TestCase
{
    public function test_artisan_command_outputs_json_and_returns_failure_when_issues_exist(): void
    {
        $projectDir = sys_get_temp_dir().'/nplusone_cmd_'.uniqid();
        mkdir($projectDir.'/app/Models', 0755, true);
        mkdir($projectDir.'/app/Http/Controllers', 0755, true);

        file_put_contents($projectDir.'/app/Models/Post.php', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Model;

        class Post extends Model
        {
            public function author()
            {
                return $this->belongsTo(User::class);
            }
        }
        PHP);

        file_put_contents($projectDir.'/app/Http/Controllers/PostController.php', <<<'PHP'
        <?php
        namespace App\Http\Controllers;
        use App\Models\Post;

        class PostController
        {
            public function index()
            {
                foreach (Post::all() as $post) {
                    echo $post->author->name;
                }
            }
        }
        PHP);

        $this->artisan('nplusone:scan', [
            '--base-path' => $projectDir,
            '--format' => 'json',
        ])->assertFailed();

        $this->artisan('nplusone:scan', [
            '--base-path' => $projectDir,
            '--no-fail' => true,
        ])->assertSuccessful();

        unlink($projectDir.'/app/Models/Post.php');
        unlink($projectDir.'/app/Http/Controllers/PostController.php');
        rmdir($projectDir.'/app/Http/Controllers');
        rmdir($projectDir.'/app/Http');
        rmdir($projectDir.'/app/Models');
        rmdir($projectDir.'/app');
        rmdir($projectDir);
    }
}
