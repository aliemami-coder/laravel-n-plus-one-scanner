<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Analyzers\ApiResourceAnalyzer;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ModelDefinition;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Tests\TestCase;

class ApiResourceAnalyzerTest extends TestCase
{
    public function test_detects_missing_when_loaded_in_json_resource(): void
    {
        $registry = new ModelRegistry;
        $registry->register(new ModelDefinition(
            fqn: 'App\\Models\\User',
            shortName: 'User',
            file: 'app/Models/User.php',
            relations: ['posts' => 'Post', 'profile' => 'Profile'],
        ));

        $code = <<<'PHP'
        <?php
        namespace App\Http\Resources;

        use Illuminate\Http\Resources\Json\JsonResource;

        class UserResource extends JsonResource
        {
            public function toArray($request): array
            {
                return [
                    'id' => $this->id,
                    'posts' => $this->posts,
                    'profile' => $this->whenLoaded('profile'),
                ];
            }
        }
        PHP;

        $analyzer = new ApiResourceAnalyzer($registry);
        $issues = $analyzer->analyze('app/Http/Resources/UserResource.php', $code);

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::TYPE_MISSING_WHEN_LOADED, $issues[0]->type);
        $this->assertSame('posts', $issues[0]->relation);
    }
}
