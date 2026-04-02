<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Black-Scholes options pricing service for paper trading.
 *
 * Calculates theoretical call/put premiums and lightweight Greeks (Delta, Theta)
 * using the Black-Scholes-Merton model. Includes options chain generation with
 * ITM/ATM/OTM classification for Indian index options (NIFTY/BANKNIFTY).
 *
 * Uses a rational approximation for the standard normal CDF (Abramowitz & Stegun)
 * to avoid external library dependencies.
 */
class BlackScholesService
{
    /**
     * Default risk-free rate (annualized) — India RBI repo rate.
     */
    private const DEFAULT_RISK_FREE_RATE = 0.065;

    /**
     * Default implied volatility for index options (NIFTY/BANKNIFTY).
     */
    private const DEFAULT_IV = 0.15;

    /**
     * Number of strikes on each side of ATM in the chain.
     */
    private const STRIKES_PER_SIDE = 10;

    /**
     * Trading days per year used for Theta normalization.
     */
    private const TRADING_DAYS_PER_YEAR = 365;

    /**
     * Calculate call and put premiums with Delta and Theta using the Black-Scholes model.
     *
     * @param  float  $spot          Current spot/underlying price.
     * @param  float  $strike        Option strike price.
     * @param  float  $timeToExpiry  Time to expiration in years (e.g. 30 days = 30/365).
     * @param  float  $iv            Implied volatility as a decimal (e.g. 0.15 for 15%).
     * @param  float  $riskFreeRate  Annualized risk-free interest rate (default 6.5%).
     * @return array{
     *     call: float,
     *     put: float,
     *     callDelta: float,
     *     putDelta: float,
     *     callTheta: float,
     *     putTheta: float
     * }
     *
     * @throws InvalidArgumentException If inputs are invalid (non-positive spot/strike/time/iv).
     */
    public function price(
        float $spot,
        float $strike,
        float $timeToExpiry,
        float $iv,
        float $riskFreeRate = self::DEFAULT_RISK_FREE_RATE,
    ): array {
        $this->validateInputs($spot, $strike, $timeToExpiry, $iv);

        $d1 = $this->d1($spot, $strike, $timeToExpiry, $iv, $riskFreeRate);
        $d2 = $d1 - $iv * sqrt($timeToExpiry);

        $discountFactor = exp(-$riskFreeRate * $timeToExpiry);

        // --- Premiums ---
        $callPrice = $spot * $this->normalCdf($d1) - $strike * $discountFactor * $this->normalCdf($d2);
        $putPrice = $strike * $discountFactor * $this->normalCdf(-$d2) - $spot * $this->normalCdf(-$d1);

        // --- Delta ---
        $callDelta = $this->normalCdf($d1);
        $putDelta = $callDelta - 1.0;

        // --- Theta (per calendar day) ---
        $nD1 = $this->normalPdf($d1);
        $sqrtT = sqrt($timeToExpiry);

        // Common term: -(S * N'(d1) * sigma) / (2 * sqrt(T))
        $thetaCommon = -($spot * $nD1 * $iv) / (2.0 * $sqrtT);

        $callTheta = ($thetaCommon - $riskFreeRate * $strike * $discountFactor * $this->normalCdf($d2))
            / self::TRADING_DAYS_PER_YEAR;

        $putTheta = ($thetaCommon + $riskFreeRate * $strike * $discountFactor * $this->normalCdf(-$d2))
            / self::TRADING_DAYS_PER_YEAR;

        return [
            'call'       => round($callPrice, 2),
            'put'        => round($putPrice, 2),
            'callDelta'  => round($callDelta, 4),
            'putDelta'   => round($putDelta, 4),
            'callTheta'  => round($callTheta, 2),
            'putTheta'   => round($putTheta, 2),
        ];
    }

    /**
     * Generate an options chain (strike ladder) for a given spot price and expiry.
     *
     * Produces 21 strikes centered on the ATM strike: 10 below + ATM + 10 above.
     * Each strike includes CE/PE premiums, Delta, Theta, and moneyness classification.
     *
     * @param  float   $spot            Current spot/underlying price.
     * @param  string  $expiryDate      Expiry date in 'Y-m-d' format (e.g. '2026-04-30').
     * @param  float   $strikeInterval  Gap between consecutive strikes (e.g. 50 for NIFTY, 100 for BANKNIFTY).
     * @param  float   $iv              Implied volatility as a decimal (default 15%).
     * @param  float   $riskFreeRate    Annualized risk-free rate (default 6.5%).
     * @return array<int, array{
     *     strike: float,
     *     moneyness: string,
     *     ce_premium: float,
     *     pe_premium: float,
     *     ce_delta: float,
     *     pe_delta: float,
     *     ce_theta: float,
     *     pe_theta: float
     * }>
     *
     * @throws InvalidArgumentException If expiry date is in the past or inputs are invalid.
     */
    public function chain(
        float $spot,
        string $expiryDate,
        float $strikeInterval,
        float $iv = self::DEFAULT_IV,
        float $riskFreeRate = self::DEFAULT_RISK_FREE_RATE,
    ): array {
        if ($spot <= 0 || $strikeInterval <= 0) {
            throw new InvalidArgumentException('Spot price and strike interval must be positive.');
        }

        $timeToExpiry = $this->calculateTimeToExpiry($expiryDate);

        if ($timeToExpiry <= 0) {
            throw new InvalidArgumentException("Expiry date '{$expiryDate}' is in the past.");
        }

        $atm = $this->atmStrike($spot, $strikeInterval);
        $chain = [];

        for ($i = -self::STRIKES_PER_SIDE; $i <= self::STRIKES_PER_SIDE; $i++) {
            $strike = $atm + ($i * $strikeInterval);

            if ($strike <= 0) {
                continue;
            }

            $pricing = $this->price($spot, $strike, $timeToExpiry, $iv, $riskFreeRate);
            $moneyness = $this->classifyMoneyness($spot, $strike, $atm);

            $chain[] = [
                'strike'     => $strike,
                'moneyness'  => $moneyness,
                'ce_premium' => $pricing['call'],
                'pe_premium' => $pricing['put'],
                'ce_delta'   => $pricing['callDelta'],
                'pe_delta'   => $pricing['putDelta'],
                'ce_theta'   => $pricing['callTheta'],
                'pe_theta'   => $pricing['putTheta'],
            ];
        }

        return $chain;
    }

    /**
     * Calculate the at-the-money strike — the strike nearest to the current spot price.
     *
     * @param  float  $spot            Current spot/underlying price.
     * @param  float  $strikeInterval  Gap between consecutive strikes.
     * @return float  The ATM strike price.
     *
     * @throws InvalidArgumentException If spot or interval is non-positive.
     */
    public function atmStrike(float $spot, float $strikeInterval): float
    {
        if ($spot <= 0 || $strikeInterval <= 0) {
            throw new InvalidArgumentException('Spot price and strike interval must be positive.');
        }

        return round(round($spot / $strikeInterval) * $strikeInterval, 2);
    }

    /**
     * Calculate time to expiry in years from now to the given expiry date.
     *
     * Assumes expiry at end of trading day (15:30 IST for Indian markets).
     *
     * @param  string  $expiryDate  Expiry date in 'Y-m-d' format.
     * @return float   Time to expiry in fractional years.
     */
    private function calculateTimeToExpiry(string $expiryDate): float
    {
        $now = Carbon::now('Asia/Kolkata');
        $expiry = Carbon::parse($expiryDate, 'Asia/Kolkata')->setTime(15, 30, 0);

        $diffInSeconds = $now->diffInSeconds($expiry, false);

        // Seconds in a calendar year
        $secondsPerYear = 365.0 * 24.0 * 3600.0;

        return max(0.0, $diffInSeconds / $secondsPerYear);
    }

    /**
     * Calculate d1 from the Black-Scholes formula.
     *
     * d1 = [ln(S/K) + (r + sigma^2/2) * T] / (sigma * sqrt(T))
     */
    private function d1(
        float $spot,
        float $strike,
        float $timeToExpiry,
        float $iv,
        float $riskFreeRate,
    ): float {
        $numerator = log($spot / $strike) + ($riskFreeRate + ($iv * $iv) / 2.0) * $timeToExpiry;
        $denominator = $iv * sqrt($timeToExpiry);

        return $numerator / $denominator;
    }

    /**
     * Standard normal cumulative distribution function (CDF).
     *
     * Uses the Abramowitz & Stegun rational approximation (formula 26.2.17)
     * with maximum absolute error < 7.5e-8.
     *
     * @param  float  $x  Input value.
     * @return float  Probability P(Z <= x) for standard normal Z.
     */
    private function normalCdf(float $x): float
    {
        // Constants for the Abramowitz & Stegun approximation
        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;
        $p  = 0.3275911;

        $sign = ($x < 0) ? -1 : 1;
        $absX = abs($x);

        $t = 1.0 / (1.0 + $p * $absX);
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        $t4 = $t3 * $t;
        $t5 = $t4 * $t;

        $y = 1.0 - (($a1 * $t + $a2 * $t2 + $a3 * $t3 + $a4 * $t4 + $a5 * $t5)
            * exp(-$absX * $absX / 2.0));

        return 0.5 * (1.0 + $sign * $y);
    }

    /**
     * Standard normal probability density function (PDF).
     *
     * N'(x) = (1 / sqrt(2*pi)) * exp(-x^2 / 2)
     *
     * @param  float  $x  Input value.
     * @return float  Density at x.
     */
    private function normalPdf(float $x): float
    {
        return exp(-$x * $x / 2.0) / sqrt(2.0 * M_PI);
    }

    /**
     * Classify a strike as ITM, ATM, or OTM relative to the spot price.
     *
     * ATM is the single strike closest to spot (as determined by atmStrike).
     * For calls: strike < spot = ITM, strike > spot = OTM.
     * For puts: strike > spot = ITM, strike < spot = OTM.
     * Since we provide a unified label per strike, we classify from the call perspective:
     *   - strike < spot => ITM CE / OTM PE
     *   - strike > spot => OTM CE / ITM PE
     *   - strike == atm => ATM
     *
     * @param  float  $spot    Current spot price.
     * @param  float  $strike  The strike to classify.
     * @param  float  $atm     The ATM strike for reference.
     * @return string One of 'ITM', 'ATM', 'OTM' (from the call option perspective).
     */
    private function classifyMoneyness(float $spot, float $strike, float $atm): string
    {
        if (abs($strike - $atm) < 0.01) {
            return 'ATM';
        }

        return $strike < $spot ? 'ITM' : 'OTM';
    }

    /**
     * Validate core pricing inputs.
     *
     * @throws InvalidArgumentException If any input is non-positive.
     */
    private function validateInputs(float $spot, float $strike, float $timeToExpiry, float $iv): void
    {
        if ($spot <= 0) {
            throw new InvalidArgumentException("Spot price must be positive, got {$spot}.");
        }

        if ($strike <= 0) {
            throw new InvalidArgumentException("Strike price must be positive, got {$strike}.");
        }

        if ($timeToExpiry <= 0) {
            throw new InvalidArgumentException("Time to expiry must be positive, got {$timeToExpiry}.");
        }

        if ($iv <= 0) {
            throw new InvalidArgumentException("Implied volatility must be positive, got {$iv}.");
        }
    }
}
