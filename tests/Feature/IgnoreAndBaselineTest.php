<?php

namespace NPlusOneScanner\Tests\Feature;

use NPlusOneScanner\Scanner;
use NPlusOneScanner\Tests\TestCase;

class IgnoreAndBaselineTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir().'/nplusone_proj_'.uniqid();
        mkdir($this->projectDir.'/app/Models', 0755, true);
        mkdir($this->projectDir.'/app/Http/Controllers', 0755, true);

        file_put_contents($this->projectDir.'/app/Models/Customer.php', <<<'PHP'
        <?php
        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        class Customer extends Model
        {
            public function orders()
            {
                return $this->hasMany(Order::class);
            }

            public function invoices()
            {
                return $this->hasMany(Invoice::class);
            }
        }
        PHP);

        file_put_contents($this->projectDir.'/app/Http/Controllers/CustomerController.php', <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Models\Customer;

        class CustomerController
        {
            public function index()
            {
                $customers = Customer::all();
                foreach ($customers as $customer) {
                    // @nplusone-ignore
                    $customer->orders;
                    $customer->invoices;
                }
            }
        }
        PHP);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectDir);
        parent::tearDown();
    }

    public function test_inline_ignore_and_baseline_filtering(): void
    {
        $scanner = new Scanner;
        $result = $scanner->scan($this->projectDir);

        $this->assertSame(1, $result->ignoredCount);
        $this->assertCount(1, $result->issues);
        $this->assertSame('invoices', $result->issues[0]->relation);

        $baselineFile = $this->projectDir.'/nplusone-baseline.json';
        $this->artisan('nplusone:scan', [
            '--base-path' => $this->projectDir,
            '--generate-baseline' => true,
            '--baseline' => $baselineFile,
        ])->assertSuccessful();

        $this->assertFileExists($baselineFile);

        $afterBaseline = $scanner->scan($this->projectDir, [
            'baseline_path' => 'nplusone-baseline.json',
        ]);

        $this->assertCount(0, $afterBaseline->issues);
        $this->assertSame(1, $afterBaseline->baselinedCount);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
