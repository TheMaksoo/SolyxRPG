<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\MarketplaceController;
use Illuminate\Console\Command;

/**
 * Backstop expiry sweep for marketplace listings — MarketplaceController::index()/mine() already expire
 * past-due listings inline on every call, this just catches listings nobody has browsed past
 * (so the seller's escrowed item doesn't sit locked away forever if the market goes quiet).
 */
class MarketExpireListings extends Command
{
    protected $signature = 'market:expire-listings';

    protected $description = 'Expires past-due marketplace listings and returns escrowed items to their sellers.';

    public function handle(MarketplaceController $marketplace): int
    {
        $marketplace->expireDueListings();

        $this->info('Expired listings swept.');

        return self::SUCCESS;
    }
}
