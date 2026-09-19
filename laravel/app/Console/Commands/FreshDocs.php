<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'docs:fresh')]
class FreshDocs extends Command
{
    protected $description = 'Generate OpenAPI docs with a recent taken_at example';

    public function handle(): int
    {
        $this->call('l5-swagger:generate');

        $path = storage_path('api-docs/api-docs.json');
        if (is_file($path)) {
            $content = (string) file_get_contents($path);
            $fresh = now('UTC')->toIso8601ZuluString();
            $content = str_replace('2026-09-19T01:05:00Z', $fresh, $content);
            file_put_contents($path, $content);

            $this->info('taken_at example refreshed to '.$fresh);
        }

        return self::SUCCESS;
    }
}