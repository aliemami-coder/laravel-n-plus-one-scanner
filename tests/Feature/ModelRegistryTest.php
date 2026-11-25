<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Tests\TestCase;

class ModelRegistryTest extends TestCase
{
    public function test_discovers_models_relations_and_default_with(): void
    {
        $tempDir = sys_get_temp_dir().'/nplusone_model_test_'.uniqid();
        mkdir($tempDir, 0755, true);

        file_put_contents($tempDir.'/User.php', <<<'PHP'
        <?php
        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\HasMany;
        use Illuminate\Database\Eloquent\Relations\HasOne;

        class User extends Model
        {
            protected $with = ['profile'];

            public function posts(): HasMany
            {
                return $this->hasMany(Post::class);
            }

            public function profile(): HasOne
            {
                return $this->hasOne(Profile::class);
            }

            public function scopeActive($query)
            {
                return $query;
            }
        }
        PHP);

        $registry = new ModelRegistry;
        $registry->discover([$tempDir]);

        $this->assertSame(1, $registry->count());

        $user = $registry->findByClassName('App\\Models\\User');
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRelation('posts'));
        $this->assertTrue($user->hasRelation('profile'));
        $this->assertFalse($user->hasRelation('scopeActive'));
        $this->assertSame('Post', $user->getRelatedModel('posts'));
        $this->assertTrue($user->isDefaultEagerLoaded('profile'));
        $this->assertFalse($user->isDefaultEagerLoaded('posts'));

        unlink($tempDir.'/User.php');
        rmdir($tempDir);
    }
}
