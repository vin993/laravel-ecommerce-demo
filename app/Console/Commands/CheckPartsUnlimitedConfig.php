<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckPartsUnlimitedConfig extends Command
{
    protected $signature = 'check:parts-unlimited-config';
    protected $description = 'Check Parts Unlimited configuration and test mode status';

    public function handle()
    {
        $this->info('Parts Unlimited Configuration Status');
        $this->info('=========================================');
        
        $config = [
            ['Setting', 'Value', 'Status'],
            ['API Base URL', env('PARTS_UNLIMITED_API_BASE_URL', 'NOT SET'), env('PARTS_UNLIMITED_API_BASE_URL') ? '✅' : '❌'],
            ['API Key', env('PARTS_UNLIMITED_API_KEY') ? 'SET (' . strlen(env('PARTS_UNLIMITED_API_KEY')) . ' chars)' : 'NOT SET', env('PARTS_UNLIMITED_API_KEY') ? '✅' : '❌'],
            ['Dealer Number', env('PARTS_UNLIMITED_DEALER_NUMBER', 'NOT SET'), env('PARTS_UNLIMITED_DEALER_NUMBER') ? '✅' : '⚠️'],
            ['Test Mode', env('PARTS_UNLIMITED_TEST_MODE', 'false') ? 'ENABLED' : 'DISABLED', env('PARTS_UNLIMITED_TEST_MODE', false) ? '🧪 TEST' : '🚨 LIVE'],
            ['API Timeout', env('PARTS_UNLIMITED_API_TIMEOUT', '30') . 's', '✅']
        ];
        
        $this->table($config[0], array_slice($config, 1));
        
        if (env('PARTS_UNLIMITED_TEST_MODE', false)) {
            $this->info('');
            $this->info('🧪 TEST MODE ENABLED');
            $this->info('✅ Orders will NOT be sent to Parts Unlimited API');
            $this->info('✅ Safe to test frontend checkout without real orders');
            $this->info('✅ Payment will still be processed via Stripe');
            $this->info('');
            $this->warn('To enable LIVE orders, set PARTS_UNLIMITED_TEST_MODE=false in .env');
        } else {
            $this->info('');
            $this->error('🚨 LIVE MODE ENABLED');
            $this->error('⚠️  Orders WILL be sent to Parts Unlimited API');
            $this->error('⚠️  Real products will be ordered and shipped');
            $this->error('⚠️  Real money will be charged via Stripe');
            $this->info('');
            $this->info('To enable TEST mode, set PARTS_UNLIMITED_TEST_MODE=true in .env');
        }
        
        if (!env('PARTS_UNLIMITED_DEALER_NUMBER')) {
            $this->info('');
            $this->warn('⚠️  Dealer Number not set - order creation will fail');
            $this->info('Please contact Parts Unlimited to get your dealer number and add it to .env');
        }
        
        return 0;
    }
}