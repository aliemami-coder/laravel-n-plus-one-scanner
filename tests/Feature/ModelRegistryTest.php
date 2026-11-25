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
        $this->assertSame('App\\Models\\Post', $user->getRelatedModel('posts'));
        $this->assertTrue($user->isDefaultEagerLoaded('profile'));
        $this->assertFalse($user->isDefaultEagerLoaded('posts'));

        unlink($tempDir.'/User.php');
        rmdir($tempDir);
    }

    public function test_discovers_trait_and_inherited_relations_polymorphic_and_ambiguous_short_names(): void
    {
        $tempDir = sys_get_temp_dir().'/nplusone_inherit_test_'.uniqid();
        mkdir($tempDir.'/Traits', 0755, true);
        mkdir($tempDir.'/Billing', 0755, true);
        mkdir($tempDir.'/Sales', 0755, true);

        file_put_contents($tempDir.'/Traits/HasPosts.php', <<<'PHP'
        <?php
        namespace App\Models\Traits;

        use App\Models\Post;

        trait HasPosts
        {
            public function posts()
            {
                return $this->hasMany(Post::class);
            }
        }
        PHP);

        file_put_contents($tempDir.'/BaseModel.php', <<<'PHP'
        <?php
        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        class BaseModel extends Model
        {
            public function creator()
            {
                return $this->belongsTo(User::class);
            }
        }
        PHP);

        file_put_contents($tempDir.'/Comment.php', <<<'PHP'
        <?php
        namespace App\Models;

        use App\Models\Traits\HasPosts;

        class Comment extends BaseModel
        {
            use HasPosts;

            public function commentable()
            {
                return $this->morphTo();
            }
        }
        PHP);

        file_put_contents($tempDir.'/Billing/Invoice.php', <<<'PHP'
        <?php
        namespace App\Models\Billing;
        use Illuminate\Database\Eloquent\Model;
        class Invoice extends Model {}
        PHP);

        file_put_contents($tempDir.'/Sales/Invoice.php', <<<'PHP'
        <?php
        namespace App\Models\Sales;
        use Illuminate\Database\Eloquent\Model;
        class Invoice extends Model {}
        PHP);

        $registry = new ModelRegistry;
        $registry->discover([$tempDir]);

        $comment = $registry->findByClassName('App\\Models\\Comment');
        $this->assertNotNull($comment);
        $this->assertTrue($comment->hasRelation('posts'));
        $this->assertSame('App\\Models\\Post', $comment->getRelatedModel('posts'));
        $this->assertTrue($comment->hasRelation('creator'));
        $this->assertTrue($comment->hasRelation('commentable'));

        // Ambiguous short name must return null; FQN must resolve cleanly.
        $this->assertNull($registry->findByClassName('Invoice'));
        $this->assertNotNull($registry->findByClassName('App\\Models\\Billing\\Invoice'));
        $this->assertNotNull($registry->findByClassName('App\\Models\\Sales\\Invoice'));

        unlink($tempDir.'/Traits/HasPosts.php');
        rmdir($tempDir.'/Traits');
        unlink($tempDir.'/Billing/Invoice.php');
        rmdir($tempDir.'/Billing');
        unlink($tempDir.'/Sales/Invoice.php');
        rmdir($tempDir.'/Sales');
        unlink($tempDir.'/BaseModel.php');
        unlink($tempDir.'/Comment.php');
        rmdir($tempDir);
    }

    public function test_child_model_with_property_overrides_parent_and_trait_with_instead_of_merging(): void
    {
        $tempDir = sys_get_temp_dir().'/nplusone_with_override_'.uniqid();
        mkdir($tempDir, 0755, true);

        file_put_contents($tempDir.'/BaseModel.php', <<<'PHP'
        <?php
        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        class BaseModel extends Model
        {
            protected $with = ['profile'];

            public function profile()
            {
                return $this->hasOne(Profile::class);
            }
        }
        PHP);

        file_put_contents($tempDir.'/User.php', <<<'PHP'
        <?php
        namespace App\Models;

        class User extends BaseModel
        {
            protected $with = ['settings'];

            public function settings()
            {
                return $this->hasOne(Setting::class);
            }
        }
        PHP);

        file_put_contents($tempDir.'/Admin.php', <<<'PHP'
        <?php
        namespace App\Models;

        class Admin extends BaseModel
        {
        }
        PHP);

        file_put_contents($tempDir.'/Guest.php', <<<'PHP'
        <?php
        namespace App\Models;

        class Guest extends BaseModel
        {
            protected $with = [];
        }
        PHP);

        $registry = new ModelRegistry;
        $registry->discover([$tempDir]);

        $user = $registry->findByClassName('App\\Models\\User');
        $this->assertNotNull($user);
        $this->assertTrue($user->isDefaultEagerLoaded('settings'));
        $this->assertFalse($user->isDefaultEagerLoaded('profile'));

        $admin = $registry->findByClassName('App\\Models\\Admin');
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isDefaultEagerLoaded('profile'));

        $guest = $registry->findByClassName('App\\Models\\Guest');
        $this->assertNotNull($guest);
        $this->assertFalse($guest->isDefaultEagerLoaded('profile'));

        unlink($tempDir.'/BaseModel.php');
        unlink($tempDir.'/User.php');
        unlink($tempDir.'/Admin.php');
        unlink($tempDir.'/Guest.php');
        rmdir($tempDir);
    }
}
