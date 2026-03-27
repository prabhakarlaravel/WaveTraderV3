# WaveTraderV3 — Project Instructions

A professional multi-market trading analytics platform built with Laravel 13 (backend API)
and Vue 3 (frontend SPA). Both live in a single monorepo under `WaveTraderV3/` with two
subfolders: `backend/` and `frontend/`.

---
Database Details
WaveTraderV3
PostgreSQL16 version

## Project Structure

```
WaveTraderV3/
├── backend/                        # Laravel 13 API
│   ├── app/
│   │   ├── Console/Commands/
│   │   │   ├── FetchCandlesCommand.php
│   │   │   ├── RunEnginesCommand.php
│   │   │   └── FillDataGapsCommand.php
│   │   ├── Engines/
│   │   │   ├── ElliottWaveEngine.php
│   │   │   ├── MarketStructureEngine.php
│   │   │   ├── OrderBlockEngine.php
│   │   │   ├── FVGEngine.php
│   │   │   ├── SMCEngine.php
│   │   │   ├── VWAPEngine.php
│   │   │   └── PriceActionEngine.php
│   │   ├── Services/
│   │   │   ├── DataSources/
│   │   │   │   ├── ZerodhaDataSource.php
│   │   │   │   ├── BinanceDataSource.php
│   │   │   │   ├── OANDADataSource.php
│   │   │   │   └── YahooDataSource.php
│   │   │   ├── WaveHealthService.php
│   │   │   ├── BacktestService.php
│   │   │   ├── PaperTradeService.php
│   │   │   ├── GapDetectionService.php
│   │   │   └── BackupService.php
│   │   ├── Jobs/
│   │   │   ├── FetchCandlesJob.php
│   │   │   ├── RunEnginesJob.php
│   │   │   └── CalculateWavesJob.php
│   │   ├── Models/
│   │   │   ├── Symbol.php
│   │   │   ├── Candle.php
│   │   │   ├── Wave.php
│   │   │   ├── OrderBlock.php
│   │   │   ├── FVG.php
│   │   │   ├── Signal.php
│   │   │   ├── Trade.php
│   │   │   ├── PnL.php
│   │   │   ├── Backtest.php
│   │   │   ├── Setting.php
│   │   │   └── DataGap.php
│   │   └── Http/Controllers/Api/
│   │       ├── ChartController.php
│   │       ├── WaveController.php
│   │       ├── TradeController.php
│   │       ├── BacktestController.php
│   │       ├── SettingsController.php
│   │       ├── GapController.php
│   │       └── UdfController.php       # TradingView UDF bridge
│   └── routes/
│       ├── api.php
│       └── channels.php
│
├── frontend/                       # Vue 3 SPA
│   └── src/
│       ├── views/
│       │   ├── LiveChart.vue
│       │   ├── TvChartView.vue         # TradingView module view
│       │   ├── Backtest.vue
│       │   ├── WaveHealth.vue
│       │   ├── DataGaps.vue
│       │   └── Settings.vue
│       ├── components/
│       │   ├── chart/
│       │   ├── waves/
│       │   ├── signals/
│       │   ├── panels/
│       │   └── tv/
│       │       ├── TvChart.vue
│       │       ├── TvOverlayPanel.vue
│       │       └── TvLayoutSelector.vue
│       ├── stores/
│       │   ├── useChartStore.js
│       │   ├── useWaveStore.js
│       │   ├── useTradeStore.js
│       │   ├── useSettingsStore.js
│       │   ├── useRealtimeStore.js
│       │   └── useTvStore.js
│       └── composables/
│           ├── useWaveOverlay.js
│           ├── useSignalOverlay.js
│           ├── usePaperTrade.js
│           └── useTvOverlays.js
│
└── docker/
    ├── docker-compose.yml
    ├── nginx.conf
    └── supervisor.conf
```

---

## Tech Stack

### Backend
- **Framework:** Laravel 13, PHP 8.3+
- **Real-time:** Laravel Reverb (WebSocket server), Laravel Echo
- **Queue:** Laravel Horizon + Redis queues
- **Auth:** Laravel Passport (OAuth2 API), Laravel Sanctum (SPA)
- **Database:** PostgreSQL 16 + TimescaleDB extension (time-series OHLCV)
- **Cache/Pub-Sub:** Redis 7+
- **Monitoring:** Laravel Telescope, Laravel Horizon dashboard
- **Storage:** Cloudflare R2 (S3-compatible) + local filesystem

### Frontend
- **Framework:** Vue 3 (Composition API)
- **State:** Pinia
- **Styling:** Tailwind CSS 4
- **Build:** Vite 6
- **Charts:** TradingView Lightweight Charts (native view) + TradingView Charting Library (TV module)
- **Utilities:** VueUse, Axios, Laravel Echo client

### Infrastructure
- Docker Compose, Nginx, PHP-FPM, Supervisor

---

## Market Data Sources

| Source | Market | Asset Class | Connection |
|--------|--------|-------------|------------|
| Zerodha KiteConnect v3 | NSE, BSE, NFO | Indian Equities, F&O | REST + KTicker WebSocket |
| Binance API v3 | Crypto Spot/Futures | BTC, ETH, Altcoins | REST + kline WebSocket streams |
| OANDA REST v20 | Forex | 50+ currency pairs | REST + Pricing stream |
| Yahoo Finance | MCX | Gold, Silver, Crude | REST (unofficial, fallback only) |

**Note:** Yahoo Finance is unreliable. Prefer Zerodha MCX segment or Alpha Vantage for commodity data.

---

## Supported Timeframes

`1M` `5M` `15M` `1H` `4H` `1D`

- Historical data depth: minimum 3 months of 1M candles on first bootstrap
- Higher timeframes (4H etc.) are derived via `time_bucket()` aggregation from 1M in TimescaleDB
- Candle fetch interval: every 30 seconds via scheduled Horizon job
- All engines re-run immediately after each candle fetch

---

## Analysis Engines

All engines live in `app/Engines/`. Each engine receives full OHLCV data for all timeframes
and emits structured signals via Laravel Broadcasting (Reverb) to the Vue frontend.

### ElliottWaveEngine
- HTF-to-LTF wave derivation: 1D → 4H → 1H → 15M → 5M → 1M
- Shows 2 wave levels: HTF parent waves + current timeframe child waves
- Wave numbering: Impulse (1-2-3-4-5) + Corrective (A-B-C)
- Fibonacci extension targets: 0.618, 1.0, 1.618, 2.618
- Alternate wave counts with probability scores
- Wave invalidation levels tracked and broadcast
- Degree labelling from Grand Supercycle down to Minuette

### MarketStructureEngine
- Break of Structure (BOS) detection — bullish and bearish
- Change of Character (CHOCH) for trend reversal signals
- Swing high/low mapping per timeframe
- Equal highs/lows (EQH/EQL) detection
- Multi-timeframe structure alignment scoring

### OrderBlockEngine
- Bullish and bearish OB detection per timeframe
- OB zone visualization (high/low price boundaries)
- Mitigation status: fresh / partially mitigated / fully mitigated
- Breaker block detection (failed/flipped OBs)
- OB strength scoring based on impulse candle size
- Overlapping OB confluence zone highlighting

### FVGEngine (Fair Value Gap)
- Bullish and bearish FVG detection (3-candle imbalance)
- Fill percentage tracking (0% to 100%)
- Nested FVG / Optimal Trade Entry zone highlighting
- Inverse FVG detection (when FVG flips role)
- FVG confluence with Elliott Wave targets

### SMCEngine (Smart Money Concepts)
- Premium / Equilibrium / Discount zones per swing
- Buy-side and sell-side liquidity pool mapping
- Inducement detection (false BOS before reversal)
- ICT Power of 3: Accumulation, Manipulation, Distribution
- Optimal Trade Entry (OTE) zone: 0.618–0.786 Fibonacci
- SMC confluence scoring with Elliott Wave output

### VWAPEngine
- Daily, weekly, monthly VWAP with ±1σ / 2σ / 3σ bands
- Anchored VWAP (user-defined anchor points)
- Session VWAP: Asia, London, New York sessions
- VWAP reclaim signals (price crossing VWAP)
- Volume Profile (visible range) integration
- MVWAP (multi-day) overlay

### PriceActionEngine
- Candlestick patterns: Engulfing, Hammer, Doji, Shooting Star, Pinbar
- Inside bar and outside bar detection
- Key support/resistance auto-detection
- Trend line detection via linear regression
- Supply and demand zone identification
- Rejection candle signals at key confluence levels

### WaveHealthModule
- Elliott Wave rule validation: Rule 2 (wave 2 not below wave 1 start), Rule 3 (wave 3 is longest impulse), Rule 4 (wave 4 does not overlap wave 1)
- Health score per timeframe: 0 to 100
- Alerts when invalidation level is breached
- Alternate count recommendations when health score drops
- Wave completion probability estimates
- Historical health score chart

---

## Data Pipeline

```
Every 30 seconds:
  FetchCandlesJob (queued via Horizon)
    ├── ZerodhaDataSource  → pull latest 1M candles
    ├── BinanceDataSource  → pull kline updates
    ├── OANDADataSource    → pull pricing stream
    └── YahooDataSource    → pull REST snapshot

  On new candle data received:
    RunEnginesJob
      ├── ElliottWaveEngine
      ├── MarketStructureEngine
      ├── OrderBlockEngine
      ├── FVGEngine
      ├── SMCEngine
      ├── VWAPEngine
      └── PriceActionEngine

  On engine completion:
    Broadcast via Laravel Reverb:
      ├── CandleUpdated event
      ├── WaveUpdated event
      ├── SignalGenerated event
      ├── OrderBlockUpdated event
      └── FVGUpdated event

  Vue frontend:
    Laravel Echo listener → Pinia store update → chart re-render
```

### Historical Bootstrap (first run)
- Fetch 3 months of 1M candles per symbol per exchange
- Derive 5M, 15M, 1H, 4H, 1D via OHLCV aggregation from 1M for consistency
- Store in TimescaleDB hypertable: `candles(symbol, timeframe, timestamp, o, h, l, c, v)`
- Enable TimescaleDB automatic chunk compression after 7 days
- Subsequent fetches pull delta only from last stored candle timestamp

---

## Core Application Modules

### Live Trading View (`/chart`)
- TradingView Lightweight Charts with 30s WebSocket candle updates
- All engine outputs rendered as chart overlays (toggleable per engine)
- MTF Elliott Wave panel: tree showing HTF parent → LTF child waves
- Manual paper trade interface: set entry, SL, target, quantity
- Auto paper trade: system-driven entries from engine signals
- Signal feed: live alert cards with engine source, TF, direction, confluence score
- Live P&L panel with equity curve, win rate, drawdown, realized/unrealized P&L
- Wave health indicator: gauge per timeframe with violation log

### TradingView Module (`/tv`) — Independent Module
- Full TradingView Charting Library (self-hosted, requires individual license)
- Fed entirely via UDF (Universal Data Feed) protocol from WaveTraderV3 backend
- Laravel UDF Controller at `/api/udf/*` implements all 7 UDF endpoints + SSE streaming
- Engine signals injected via TV Drawing API and `/marks` + `/timescales_marks` endpoints
- Multi-chart layouts (1×1, 2×2, 1+3) built into TV
- Pine Script, drawing tools, 100+ indicators all available natively
- `TvChart.vue` wrapper component with `useTvOverlays.js` Drawing API bridge
- Route: `/tv` — completely independent from native `/chart` view

### Backtest Module (`/backtest`)
Three modes:
1. **Live Preview (Replay):** Bar-by-bar replay at 1×/5×/10×/50× speed. All engines re-run on each bar. Waves, signals, and overlays render as they would have in real time.
2. **Auto Trade Backtest:** System takes trades automatically from engine signals during replay. Configurable: which signals to trade, risk per trade %, max open positions.
3. **Manual Trade Backtest:** Pause replay at any bar, place trades manually, then continue. For discretionary strategy testing.

Backtest reports: Net P&L, Win Rate, Profit Factor, Sharpe Ratio, Sortino Ratio, Max Drawdown, Average RRR, Consecutive wins/losses, Equity curve, Drawdown chart, Trade distribution by engine/signal type.

### Wave Health Module (`/wave-health`)
- Real-time health score per symbol per timeframe
- Color coded: green (valid), amber (caution), red (invalidated)
- Elliott Wave rule violation log
- Alternate count recommendation on health drop
- Historical health score chart

### Data Gap Manager (`/gaps`)
- Auto-detection of missing candles (holiday-aware, weekend-excluded)
- Visual gap timeline per symbol per timeframe
- One-click gap fill: fetches only the missing date range
- Per-symbol, per-timeframe gap health percentage report

### Settings Module (`/settings`)
- **Exchange settings:** API keys and secrets per exchange (AES-256 encrypted). Zerodha: API Key, Secret, Access Token with daily auto-refresh. Binance: Key + Secret + testnet toggle. OANDA: Account ID + Bearer token + practice/live mode.
- **Backup settings:** Local (N-day retention) + Cloudflare R2 (Account ID, Access Key, Secret Key, Bucket). Scope: candles, waves, settings, trade history. Restore with point-in-time selection.
- **Engine settings:** Toggle per engine globally or per symbol. Per-engine parameter tuning (ATR multipliers, sensitivity, etc.).
- **System settings:** Fetch interval, active symbols management, historical depth, Horizon workers count, Telegram/email alert webhooks.

---

## UDF Bridge Endpoints (TradingView Module)

All routes under `/api/udf` prefix. No Sanctum middleware — use lightweight static token via query param.

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/udf/config` | Datafeed capabilities declaration |
| GET | `/api/udf/symbols?symbol=` | Symbol metadata lookup |
| GET | `/api/udf/search?query=` | Symbol search for TV toolbar |
| GET | `/api/udf/history?symbol=&resolution=&from=&to=` | Historical OHLCV bars (core) |
| GET | `/api/udf/marks` | Signal markers on price bars |
| GET | `/api/udf/timescales_marks` | Elliott Wave labels on time axis |
| SSE | `/api/udf/streaming` | Real-time tick updates via Server-Sent Events |

SSE requires Nginx `proxy_buffering off` on the `/api/udf/streaming` location block.

---

## Broadcasting Channels (Reverb)

```php
// channels.php
Broadcast::channel('candles.{symbol}.{timeframe}', ...);
Broadcast::channel('waves.{symbol}', ...);
Broadcast::channel('signals.{symbol}', ...);
Broadcast::channel('health.{symbol}', ...);
Broadcast::channel('trades.{userId}', ...);
```

---

## Database Schema (Key Tables)

All candle data stored in TimescaleDB hypertable partitioned by `timestamp`.

```
symbols         (id, exchange, ticker, name, type, session, timezone, lot_size, tick_size, active)
candles         (symbol_id, timeframe, timestamp, open, high, low, close, volume)  ← hypertable
waves           (id, symbol_id, timeframe, degree, wave_number, start_time, end_time, start_price, end_price, health_score, alternate)
order_blocks    (id, symbol_id, timeframe, type, high, low, formed_at, status, strength)
fvgs            (id, symbol_id, timeframe, type, high, low, formed_at, fill_pct)
signals         (id, symbol_id, timeframe, engine, direction, entry, sl, tp, confluence_score, created_at)
trades          (id, user_id, symbol_id, type, entry_price, exit_price, quantity, sl, tp, status, pnl, created_at)
backtests       (id, symbol_id, timeframe, from_date, to_date, mode, config, results_json)
settings        (id, key, value, group, encrypted)
data_gaps       (id, symbol_id, timeframe, gap_start, gap_end, filled_at)
```

---

## Critical Implementation Rules

### Always follow these when writing code for this project:

1. **Engine output must include a timestamp.** Every signal, wave, OB, and FVG record must store `created_at` and `candle_timestamp`. The frontend must check staleness — if the last engine run was >90 seconds ago, show a stale data warning.

2. **All candle inserts use upsert, not insert.** Use `INSERT ... ON CONFLICT DO UPDATE` to handle duplicate candles from overlapping fetch windows without errors.

3. **TimescaleDB for all OHLCV queries.** Never query raw candle rows with a PHP loop. Always use `time_bucket()` for aggregation, `first()` / `last()` for OHLC, and `sum()` for volume. This is critical for performance on 3+ months of 1M data.

4. **Redis Pub/Sub for SSE streaming.** `FetchCandlesJob` must publish new candles to a Redis channel (`candles:{symbol}:{timeframe}`) immediately after DB insert. The UDF streaming SSE controller subscribes to this — do not poll the database for SSE.

5. **Rate limiting on all exchange API calls.** Implement per-exchange request throttle: Binance 1200 req/min, Zerodha 3 req/sec, OANDA 100 req/sec. Use exponential backoff on 429 responses. Never fire parallel bulk-history requests without throttling.

6. **Zerodha access token auto-renewal.** KiteConnect tokens expire daily at midnight. A scheduled command must handle token renewal each morning before NSE market open (09:00 IST). Notify via Telegram if renewal fails.

7. **All API credentials stored encrypted.** Use AES-256-CBC encryption via Laravel's `Crypt` facade for all exchange keys stored in the `settings` table. Never store plaintext credentials.

8. **Engine runs are queued, never synchronous.** `RunEnginesJob` must be dispatched to a dedicated `engines` Horizon queue. Never run engines inside a controller or during the HTTP request cycle.

9. **TV library files must never be in a public git repo.** The `frontend/public/charting_library/` directory must be in `.gitignore`. Add as a private git submodule or CI/CD secret copy.

10. **Derive higher timeframes from 1M base candles.** Never store separately fetched 4H or 1D candles if they can be derived from 1M data in TimescaleDB. This guarantees OHLCV consistency across all timeframes.

---

## Exchange-Specific Notes

### Zerodha (NSE)
- Uses KiteConnect v3 REST + KTicker WebSocket
- Access token expires daily — must automate renewal
- Historical API: max 60 days per request, 3 requests/second rate limit
- MCX commodities accessible via F&O segment (preferred over Yahoo)
- Market hours: 09:15–15:30 IST, weekdays only

### Binance (Crypto)
- REST API v3 + WebSocket kline streams
- Rate limit: 1200 requests/minute (weight-based)
- Historical klines endpoint supports up to 1000 bars per request
- 24/7 market — no session gaps
- Support both Spot and Futures symbols

### OANDA (Forex)
- REST v20 + Streaming pricing API
- Granularity parameter maps to timeframes directly
- Practice and live accounts use different base URLs
- Forex market closed weekends — account for this in gap detection

### Yahoo Finance (MCX fallback)
- Unofficial API, rate-limited and unreliable
- Max 60 days historical for intraday
- Use only as last resort — prefer Zerodha MCX for Indian commodity data
- Consider Alpha Vantage or Quandl as paid alternatives

---

## Build Roadmap

### Phase 1 — Foundation (start here)
- [ ] Docker Compose scaffold (Nginx, PHP-FPM, PostgreSQL+TimescaleDB, Redis, Supervisor)
- [ ] Laravel 13 project setup with Reverb, Horizon, Passport, Sanctum
- [ ] Vue 3 project setup with Pinia, Tailwind 4, Vite 6
- [ ] TimescaleDB migrations (candles hypertable, all core tables)
- [ ] Exchange adapter contracts + Zerodha, Binance, OANDA, Yahoo implementations
- [ ] FetchCandlesJob + Horizon scheduler (30s interval)
- [ ] Historical bootstrap command (3 months of 1M data per symbol)
- [ ] Gap detection service + DataGaps UI
- [ ] Auth system (Passport OAuth2 + Sanctum SPA)
- [ ] Symbol management UI (add/remove tracked instruments)

### Phase 2 — Core Engines
- [ ] ElliottWaveEngine (pivot detection, wave labelling, HTF→LTF derivation)
- [ ] MarketStructureEngine (BOS/CHOCH)
- [ ] OrderBlockEngine + FVGEngine
- [ ] SMCEngine (premium/discount, liquidity pools)
- [ ] VWAPEngine (multi-session + anchored VWAP)
- [ ] PriceActionEngine (candlestick pattern recognition)
- [ ] WaveHealthModule (rule validation + health scoring)
- [ ] Reverb broadcasting for all engine outputs

### Phase 3 — Live Chart View
- [ ] TradingView Lightweight Charts integration in Vue
- [ ] All engine overlay composables (OBs, FVGs, waves, VWAP bands)
- [ ] MTF wave panel (HTF → LTF tree view)
- [ ] Manual paper trade interface
- [ ] Auto paper trade (signal-driven execution)
- [ ] Live P&L panel + equity curve chart
- [ ] Signal feed panel

### Phase 4 — TradingView Module
- [ ] Apply for TradingView Charting Library license
- [ ] UDF Controller with all 7 endpoints
- [ ] SSE streaming endpoint + Nginx config
- [ ] TvChart.vue wrapper + useTvOverlays.js bridge
- [ ] TvChartView.vue page with layout selector
- [ ] Engine overlays via TV marks and Drawing API

### Phase 5 — Backtest & Polish
- [ ] Backtest replay engine (bar-by-bar, speed control)
- [ ] Auto and manual trade modes in backtest
- [ ] Backtest P&L reports + performance metrics
- [ ] Full settings module (all exchange configs, engine params, backup)
- [ ] Telegram alert integration
- [ ] Confluence scoring meta-engine
- [ ] Multi-chart mosaic layout (native view)
- [ ] Drawing tools on native chart

---

## Suggested Future Features (Post-Launch)

- **Confluence Scoring Engine:** Meta-engine calculating composite score (0–100) when multiple engines agree at the same price zone
- **Trade Journal:** Tags per trade, auto-screenshot at entry/exit, performance analytics by tag and wave position
- **Volume Profile (VPVR):** Point of Control, Value Area High/Low, High/Low Volume Nodes
- **Economic Calendar:** Forex Factory or Investing.com feed, overlay event markers on chart, pause auto-trade during high-impact news
- **Session Highlighter:** Asia/London/New York session shading on chart with market clock per exchange
- **Mobile PWA:** Responsive breakpoints, service worker, offline signal caching
- **System Monitoring Dashboard:** Exchange connection status, last fetch timestamps, queue depth, Redis memory, DB table sizes
- **Notification System:** Telegram bot, email via Laravel Mail, in-app notification center

---

## Coding Conventions

- **PHP:** PSR-12, strict types declared, all engine classes implement a shared `EngineInterface` contract
- **Vue:** Composition API only, no Options API, `<script setup>` syntax
- **Naming:** snake_case for DB columns, camelCase for JS/Vue, PascalCase for PHP classes
- **API versioning:** All routes under `/api/v1/` prefix
- **Engines:** Each engine must have a `run(array $candles, string $symbol, string $timeframe): EngineResult` method signature
- **Jobs:** All jobs must be idempotent — safe to retry without duplicate side effects
- **Events:** All broadcast events must extend `ShouldBroadcastNow` for immediate delivery (not queued broadcast)
- **Tests:** Feature tests for all API endpoints, unit tests for each engine's core calculation logic
