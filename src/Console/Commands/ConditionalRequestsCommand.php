<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Console\Commands;

use Illuminate\Console\Command;

class ConditionalRequestsCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'laravel-conditional-requests:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package laravel-conditional-requests.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('ConditionalRequests placeholder command executed.');

        return self::SUCCESS;
    }
}
