<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ScanResult;
use NPlusOneScanner\Reporting\HtmlReporter;
use NPlusOneScanner\Tests\TestCase;

class HtmlReporterTest extends TestCase
{
    public function test_renders_escaped_html_report_with_issues(): void
    {
        $result = new ScanResult(
            issues: [
                new Issue(
                    file: 'app/Http/Controllers/UserController.php',
                    line: 25,
                    type: Issue::TYPE_LAZY_LOADING_IN_LOOP,
                    severity: Issue::SEVERITY_HIGH,
                    model: 'User',
                    relation: 'posts',
                    message: 'Accessing `$user->posts` inside a loop.',
                    suggestion: "Add `->with('posts')`.",
                    snippet: 'echo $user->posts->count();',
                ),
            ],
            filesScanned: 10,
            modelsDiscovered: 3,
            ignoredCount: 1,
            baselinedCount: 2,
            durationSeconds: 0.42,
        );

        $html = (new HtmlReporter)->render($result);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Laravel N+1 Static Scanner Report', $html);
        $this->assertStringContainsString('app/Http/Controllers/UserController.php:25', $html);
        $this->assertStringContainsString('User::posts', $html);
        $this->assertStringContainsString('echo $user-&gt;posts-&gt;count();', $html);
    }

    public function test_artisan_command_writes_html_report_file(): void
    {
        $projectDir = sys_get_temp_dir().'/nplusone_html_cmd_'.uniqid();
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

        $reportPath = $projectDir.'/reports/nplusone.html';
        $this->artisan('nplusone:scan', [
            '--base-path' => $projectDir,
            '--format' => 'html',
            '--output' => $reportPath,
            '--no-fail' => true,
        ])->assertSuccessful();

        $this->assertFileExists($reportPath);
        $html = file_get_contents($reportPath);
        $this->assertIsString($html);
        $this->assertStringContainsString('Post::author', $html);

        unlink($reportPath);
        rmdir($projectDir.'/reports');
        unlink($projectDir.'/app/Models/Post.php');
        unlink($projectDir.'/app/Http/Controllers/PostController.php');
        rmdir($projectDir.'/app/Http/Controllers');
        rmdir($projectDir.'/app/Http');
        rmdir($projectDir.'/app/Models');
        rmdir($projectDir.'/app');
        rmdir($projectDir);
    }
}
