<?php
declare(strict_types=1);
namespace CmdStan\Tests;
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected string $dir;
    protected function setUp(): void { $this->dir = sys_get_temp_dir().'/cmdstanphp-test-'.bin2hex(random_bytes(8)); mkdir($this->dir, 0700); }
    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->dir);
    }
    protected function csv(string $name = 'chain.csv', string $extra = '', array $rows = []): string
    {
        $file = $this->dir.'/'.$name;
        file_put_contents($file, "# method = sample\n# id = 1\n# num_warmup = 2\n# save_warmup = 1\n# thin = 1\n# max_depth = 10\n".$extra."lp__,theta,x.1,x.2,divergent__,treedepth__,energy__\n-3,0.1,1,2,0,2,1\n-2,0.2,2,3,0,3,2\n# Adaptation terminated\n# Step size = 0.5\n# Diagonal elements of inverse mass matrix:\n# 1,2\n".implode("\n", $rows ?: ['-1,0.3,3,4,0,4,1', '-1,0.4,4,5,1,10,3'])."\n# Elapsed Time: 0.1 seconds (Warm-up)\n# 0.2 seconds (Sampling)\n# 0.3 seconds (Total)\n");
        return $file;
    }
}
