<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Symbol;
use Illuminate\Console\Command;

class SeedNseSymbolsCommand extends Command
{
    protected $signature = 'symbols:seed-nse
        {--dry-run : List symbols without inserting}';

    protected $description = 'Seed NSE F&O symbols (indices + top stocks) for options trading';

    public function handle(): int
    {
        $symbols = $this->getNseSymbols();
        $dryRun  = $this->option('dry-run');

        if ($dryRun) {
            $this->table(
                ['#', 'Ticker', 'Name', 'Exchange', 'Type', 'Lot Size', 'Session'],
                collect($symbols)->map(fn ($s, $i) => [
                    $i + 1, $s['ticker'], $s['name'], $s['exchange'],
                    $s['type'], $s['lot_size'], $s['session'],
                ])
            );
            $this->info(count($symbols) . ' symbols would be seeded.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar(count($symbols));
        $bar->start();

        foreach ($symbols as $sym) {
            $existing = Symbol::where('exchange', $sym['exchange'])
                ->where('ticker', $sym['ticker'])
                ->first();

            if ($existing) {
                // Update lot_size if changed
                $existing->update(['lot_size' => $sym['lot_size'], 'active' => true]);
                $skipped++;
            } else {
                Symbol::create($sym);
                $created++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ Created: {$created}  |  Updated: {$skipped}  |  Total: " . count($symbols));

        return self::SUCCESS;
    }

    private function getNseSymbols(): array
    {
        // NSE market session: 09:15–15:30 IST, weekdays only
        $nseSession = '0915-1530';
        $timezone   = 'Asia/Kolkata';

        return [
            // ── Index Options ───────────────────────────────────────────
            ['exchange' => 'NSE', 'ticker' => 'NIFTY 50',     'name' => 'Nifty 50',                  'type' => 'INDEX', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 75,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'NIFTY BANK',   'name' => 'Nifty Bank',                'type' => 'INDEX', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 30,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'NIFTY FIN SERVICE', 'name' => 'Nifty Financial Services', 'type' => 'INDEX', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 40, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'NIFTY MID SELECT', 'name' => 'Nifty Midcap Select',    'type' => 'INDEX', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 75,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'BSE', 'ticker' => 'SENSEX',       'name' => 'S&P BSE Sensex',            'type' => 'INDEX', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 20,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'BSE', 'ticker' => 'BANKEX',       'name' => 'S&P BSE Bankex',            'type' => 'INDEX', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 30,  'tick_size' => 0.05, 'active' => true],

            // ── Top 40 F&O Stocks ───────────────────────────────────────
            ['exchange' => 'NSE', 'ticker' => 'RELIANCE',    'name' => 'Reliance Industries',        'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 250,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'TCS',         'name' => 'Tata Consultancy Services',  'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 175,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'HDFCBANK',    'name' => 'HDFC Bank',                  'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 550,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'INFY',        'name' => 'Infosys',                    'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 400,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'ICICIBANK',   'name' => 'ICICI Bank',                 'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 700,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'SBIN',        'name' => 'State Bank of India',        'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 750,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'BHARTIARTL',  'name' => 'Bharti Airtel',              'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 475,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'ITC',         'name' => 'ITC Ltd',                    'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 1600, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'KOTAKBANK',   'name' => 'Kotak Mahindra Bank',        'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 400,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'LT',          'name' => 'Larsen & Toubro',            'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 150,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'AXISBANK',    'name' => 'Axis Bank',                  'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 625,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'TATAMOTORS',  'name' => 'Tata Motors',                'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 575,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'MARUTI',      'name' => 'Maruti Suzuki',              'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 100,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'TATASTEEL',   'name' => 'Tata Steel',                 'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 5500, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'WIPRO',       'name' => 'Wipro',                      'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 1500, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'HCLTECH',     'name' => 'HCL Technologies',           'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 350,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'ADANIENT',    'name' => 'Adani Enterprises',          'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 250,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'BAJFINANCE',  'name' => 'Bajaj Finance',              'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 125,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'BAJAJ-AUTO',  'name' => 'Bajaj Auto',                 'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 75,   'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'SUNPHARMA',   'name' => 'Sun Pharmaceutical',         'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 350,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'TITAN',       'name' => 'Titan Company',              'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 175,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'POWERGRID',   'name' => 'Power Grid Corp',            'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 2400, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'NTPC',        'name' => 'NTPC Ltd',                   'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 1600, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'M&M',         'name' => 'Mahindra & Mahindra',        'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 350,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'ULTRACEMCO',  'name' => 'UltraTech Cement',           'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 50,   'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'ONGC',        'name' => 'Oil & Natural Gas Corp',     'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 3000, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'COALINDIA',   'name' => 'Coal India',                 'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 1500, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'INDUSINDBK',  'name' => 'IndusInd Bank',              'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 500,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'JSWSTEEL',    'name' => 'JSW Steel',                  'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 675,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'DIVISLAB',    'name' => "Divi's Laboratories",        'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 100,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'DRREDDY',     'name' => "Dr. Reddy's Laboratories",   'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 125,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'CIPLA',       'name' => 'Cipla',                      'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 650,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'APOLLOHOSP',  'name' => 'Apollo Hospitals',           'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 125,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'TATACONSUM',  'name' => 'Tata Consumer Products',     'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 675,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'HINDALCO',    'name' => 'Hindalco Industries',        'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 1075, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'BPCL',        'name' => 'Bharat Petroleum',           'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 1800, 'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'TECHM',       'name' => 'Tech Mahindra',              'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 400,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'EICHERMOT',   'name' => 'Eicher Motors',              'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 125,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'GRASIM',      'name' => 'Grasim Industries',          'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 250,  'tick_size' => 0.05, 'active' => true],
            ['exchange' => 'NSE', 'ticker' => 'ADANIPORTS',  'name' => 'Adani Ports & SEZ',          'type' => 'EQUITY', 'session' => $nseSession, 'timezone' => $timezone, 'lot_size' => 500,  'tick_size' => 0.05, 'active' => true],
        ];
    }
}
