import { watch, ref, nextTick } from 'vue'
import { LineSeries } from 'lightweight-charts'
import { toISTEpoch } from '../utils/timezone'
import { createProjectileRenderer } from './useWaveProjectile'

/**
 * Full SVG overlay system for rendering waves, OBs, FVGs, BOS/CHOCH, VWAP,
 * and wave projectile projections on top of the lightweight-charts canvas.
 */
export function useChartOverlays(chartRef, candleSeriesRef, chartStore, overlayToggles) {
  let vwapSeries = null
  let vwapU1Series = null
  let vwapL1Series = null
  const svgOverlay = ref(null)

  // Alias for overlay coordinate mapping — all times displayed in IST
  const toUnix = toISTEpoch

  // ── Coordinate helpers ──
  function getX(time) {
    if (!chartRef.value) return null
    const coord = chartRef.value.timeScale().timeToCoordinate(time)
    return coord !== null && isFinite(coord) ? coord : null
  }

  function getY(price) {
    if (!candleSeriesRef.value) return null
    const coord = candleSeriesRef.value.priceToCoordinate(price)
    return coord !== null && isFinite(coord) ? coord : null
  }

  // ── Setup SVG overlay div ──
  function ensureSvgOverlay(container) {
    // Target the chart's internal wrapper div (first child of container)
    const chartWrapper = container.children[0]
    if (!chartWrapper) return null

    if (svgOverlay.value && svgOverlay.value.parentElement === chartWrapper) return svgOverlay.value

    // Remove old SVGs
    container.querySelectorAll('.svg-chart-overlay').forEach(el => el.remove())

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
    svg.classList.add('svg-chart-overlay')
    svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:10;'
    chartWrapper.style.position = 'relative'
    chartWrapper.appendChild(svg)
    svgOverlay.value = svg
    return svg
  }

  // External container ref for the SVG overlay
  let containerEl = null

  function setContainer(el) {
    containerEl = el
  }

  // ── Wave Projectile renderer (lazy-initialized) ──
  let projectile = null
  function getProjectileRenderer() {
    if (!projectile) projectile = createProjectileRenderer(getX, getY, toUnix)
    return projectile
  }

  // ── Render all overlays ──
  function renderAll() {
    if (!chartRef.value || !candleSeriesRef.value || !containerEl) return
    const container = containerEl

    const svg = ensureSvgOverlay(container)
    const w = container.clientWidth
    const h = container.clientHeight
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`)
    svg.setAttribute('width', w)
    svg.setAttribute('height', h)

    // Clear previous overlay content
    svg.innerHTML = ''

    const overlays = chartStore.overlays
    if (!overlays) return

    const toggles = overlayToggles?.value || { waves: true, ob: true, fvg: true, bos: true, vwap: true, signals: true, projectile: true }

    // Render order: VWAP bands → FVG zones → OB zones → BOS/CHOCH → Wave lines + labels → Projectile → Signal markers
    if (toggles.vwap) renderVwap(overlays.vwap || [], svg)
    if (toggles.fvg) renderFVGs(overlays.fvgs || [], svg, w)
    if (toggles.ob) renderOBs(overlays.orderBlocks || [], svg, w)
    if (toggles.ob) renderOTE(overlays.oteZones || [], svg, w)
    if (toggles.ob) renderLiquidityPools(overlays.liquidityPools || [], svg, w)
    if (toggles.bos) renderBos(overlays.bos || [], svg)
    if (toggles.waves) renderWaveLabels(overlays.waveLabels || [], svg)

    // Projectile replaces the simpler wave targets + time estimate when enabled
    if (toggles.projectile) {
      try { getProjectileRenderer().render(svg, w, h, overlays) } catch (e) { /* projectile render error */ }
    } else {
      if (toggles.waves) renderWaveTargets(overlays.nextTargets || {}, svg, w)
      if (toggles.waves) renderWaveTimeEstimate(overlays.timeEstimate || {}, svg, w, h)
    }

    if (toggles.signals) { try { renderSignalMarkers() } catch (e) { /* markers may fail if series not ready */ } }
  }

  // ── VWAP with bands ──
  function renderVwap(vwapData, svg) {
    if (vwapData.length < 2) return

    // Sample every Nth point to avoid SVG overload
    const step = Math.max(1, Math.floor(vwapData.length / 200))
    const sampled = vwapData.filter((_, i) => i % step === 0 || i === vwapData.length - 1)

    const vwapPoints = []
    const u1Points = []
    const l1Points = []

    for (const v of sampled) {
      const x = getX(toUnix(v.timestamp))
      const yV = getY(v.vwap)
      const yU = getY(v.upper1)
      const yL = getY(v.lower1)
      if (x !== null && yV !== null) {
        vwapPoints.push(`${x},${yV}`)
        if (yU !== null) u1Points.push(`${x},${yU}`)
        if (yL !== null) l1Points.push(`${x},${yL}`)
      }
    }

    if (vwapPoints.length < 2) return

    // Band fill
    if (u1Points.length > 1 && l1Points.length > 1) {
      const bandPath = `M${u1Points.join(' L')} L${[...l1Points].reverse().join(' L')} Z`
      addPath(svg, bandPath, { fill: 'rgba(236,72,153,0.06)', stroke: 'none' })
    }

    // Upper/lower bands
    if (u1Points.length > 1) addPath(svg, `M${u1Points.join(' L')}`, { fill: 'none', stroke: 'rgba(236,72,153,0.4)', strokeWidth: '0.6', strokeDasharray: '3 2' })
    if (l1Points.length > 1) addPath(svg, `M${l1Points.join(' L')}`, { fill: 'none', stroke: 'rgba(236,72,153,0.4)', strokeWidth: '0.6', strokeDasharray: '3 2' })

    // VWAP main line
    addPath(svg, `M${vwapPoints.join(' L')}`, { fill: 'none', stroke: '#ec4899', strokeWidth: '1.2', opacity: '0.7' })
  }

  // ── Order Blocks as zones ──
  function renderOBs(obs, svg, chartWidth) {
    for (const ob of obs.slice(-8)) {
      const ts = toUnix(ob.formed_at)
      const x1 = getX(ts)
      const yHigh = getY(parseFloat(ob.high))
      const yLow = getY(parseFloat(ob.low))
      if (x1 === null || yHigh === null || yLow === null) continue

      const rectH = Math.max(Math.abs(yLow - yHigh), 4)
      const rectY = Math.min(yHigh, yLow)
      const rectW = Math.max(chartWidth - x1 - 60, 40)
      const isBull = ob.type === 'bullish'
      const isFresh = ob.status === 'fresh'

      // Bullish OB (support) = blue, Bearish OB (resistance) = pink
      const obFill = isBull ? 'rgba(59,130,246,0.12)' : 'rgba(236,72,153,0.12)'
      const obStroke = isBull ? 'rgba(59,130,246,0.45)' : 'rgba(236,72,153,0.45)'
      const obTextColor = isBull ? '#3b82f6' : '#ec4899'

      // Zone rectangle
      const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
      rect.setAttribute('x', x1)
      rect.setAttribute('y', rectY)
      rect.setAttribute('width', rectW)
      rect.setAttribute('height', rectH)
      rect.setAttribute('fill', obFill)
      rect.setAttribute('stroke', obStroke)
      rect.setAttribute('stroke-width', '1')
      rect.setAttribute('rx', '2')
      if (!isFresh) rect.setAttribute('stroke-dasharray', '4 2')
      svg.appendChild(rect)

      // Accent line at top or bottom
      const accentY = isBull ? rectY + rectH - 2 : rectY
      const accent = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
      accent.setAttribute('x', x1)
      accent.setAttribute('y', accentY)
      accent.setAttribute('width', rectW)
      accent.setAttribute('height', '2.5')
      accent.setAttribute('fill', isBull ? '#3b82f6' : '#ec4899')
      accent.setAttribute('opacity', '0.7')
      accent.setAttribute('rx', '1')
      svg.appendChild(accent)

      // Label
      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      text.setAttribute('x', x1 + 4)
      text.setAttribute('y', rectY + 12)
      text.setAttribute('fill', obTextColor)
      text.setAttribute('font-size', '9')
      text.setAttribute('font-family', "'JetBrains Mono', monospace")
      text.setAttribute('font-weight', '700')
      text.setAttribute('opacity', '1')
      text.textContent = `${isBull ? '▲' : '▼'} OB ${isFresh ? '●' : '○'}`
      svg.appendChild(text)
    }
  }

  // ── FVG Zones ──
  function renderFVGs(fvgs, svg, chartWidth) {
    const unfilled = fvgs.filter(f => parseFloat(f.fill_pct || 0) < 80).slice(-6)

    for (const fvg of unfilled) {
      const ts = toUnix(fvg.formed_at)
      const x1 = getX(ts)
      const yHigh = getY(parseFloat(fvg.high))
      const yLow = getY(parseFloat(fvg.low))
      if (x1 === null || yHigh === null || yLow === null) continue

      const rectH = Math.max(Math.abs(yLow - yHigh), 3)
      const rectY = Math.min(yHigh, yLow)
      const rectW = Math.max(chartWidth - x1 - 60, 30)

      const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
      rect.setAttribute('x', x1)
      rect.setAttribute('y', rectY)
      rect.setAttribute('width', rectW)
      rect.setAttribute('height', rectH)
      rect.setAttribute('fill', 'rgba(46,204,113,0.10)')
      rect.setAttribute('stroke', 'rgba(46,204,113,0.4)')
      rect.setAttribute('stroke-width', '1')
      rect.setAttribute('rx', '1')
      svg.appendChild(rect)

      // Label
      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      text.setAttribute('x', x1 + 4)
      text.setAttribute('y', rectY + 10)
      text.setAttribute('fill', '#2ecc71')
      text.setAttribute('font-size', '9')
      text.setAttribute('font-family', "'JetBrains Mono', monospace")
      text.setAttribute('font-weight', '700')
      text.setAttribute('opacity', '1')
      text.textContent = 'FVG'
      svg.appendChild(text)
    }
  }

  // ── BOS / CHOCH markers ──
  function renderBos(bosData, svg) {
    const recent = bosData.slice(-8)

    for (const b of recent) {
      const ts = toUnix(b.timestamp)
      const bx = getX(ts)
      const by = getY(b.price)
      if (bx === null || by === null) continue

      const isBos = b.type === 'bos'
      const color = isBos ? '#10b981' : '#f97316'
      const lineStart = Math.max(0, bx - 80)

      // Dashed line
      addLine(svg, lineStart, by, bx + 40, by, { stroke: color, strokeWidth: '1', strokeDasharray: '4 2', opacity: '0.6' })

      // Label box
      const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
      rect.setAttribute('x', bx - 20)
      rect.setAttribute('y', by - 8)
      rect.setAttribute('width', '40')
      rect.setAttribute('height', '16')
      rect.setAttribute('rx', '4')
      rect.setAttribute('fill', '#0c1221')
      rect.setAttribute('stroke', color)
      rect.setAttribute('stroke-width', '1')
      svg.appendChild(rect)

      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      text.setAttribute('x', bx)
      text.setAttribute('y', by + 3.5)
      text.setAttribute('text-anchor', 'middle')
      text.setAttribute('fill', color)
      text.setAttribute('font-size', '8')
      text.setAttribute('font-weight', '700')
      text.setAttribute('font-family', "'JetBrains Mono', monospace")
      text.textContent = `${b.type.toUpperCase()} ${b.direction === 'buy' ? '↑' : '↓'}`
      svg.appendChild(text)
    }
  }

  // ── Wave labels with circles + connection lines ──
  function renderWaveLabels(waveLabels, svg) {
    if (waveLabels.length < 2) return

    // Build points
    const points = []
    for (const w of waveLabels) {
      const wx = getX(toUnix(w.timestamp))
      const wy = getY(w.price)
      if (wx !== null && wy !== null) {
        points.push({ ...w, wx, wy })
      }
    }

    if (points.length < 2) return

    // Connection line
    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.wx},${p.wy}`).join(' ')
    addPath(svg, linePath, { fill: 'none', stroke: 'rgba(139,92,246,0.5)', strokeWidth: '1.5', strokeDasharray: '6 3' })

    // Labels
    for (const p of points) {
      const isAbove = p.type === 'high'
      const labelY = isAbove ? p.wy - 18 : p.wy + 18
      const isCorr = p.isCorrection
      const color = isCorr ? '#f59e0b' : '#8b5cf6'

      // Stem line
      addLine(svg, p.wx, p.wy, p.wx, isAbove ? labelY + 8 : labelY - 8, {
        stroke: color, strokeWidth: '0.8', opacity: '0.5',
      })

      // Circle background
      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle')
      circle.setAttribute('cx', p.wx)
      circle.setAttribute('cy', labelY)
      circle.setAttribute('r', '10')
      circle.setAttribute('fill', '#0c1221')
      circle.setAttribute('stroke', color)
      circle.setAttribute('stroke-width', '1.5')
      svg.appendChild(circle)

      // Label text
      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      text.setAttribute('x', p.wx)
      text.setAttribute('y', labelY + 4)
      text.setAttribute('text-anchor', 'middle')
      text.setAttribute('fill', color)
      text.setAttribute('font-size', '11')
      text.setAttribute('font-weight', '700')
      text.setAttribute('font-family', "'JetBrains Mono', monospace")
      text.textContent = p.label
      svg.appendChild(text)
    }
  }

  // ── Signal markers on candle series ──
  // ── OTE Zones (0.618-0.786 Fibonacci) ──
  function renderOTE(oteZones, svg, chartWidth) {
    for (const ote of oteZones) {
      const ts = toUnix(ote.timestamp)
      const x1 = getX(ts)
      const yHigh = getY(ote.high)
      const yLow = getY(ote.low)
      if (x1 === null || yHigh === null || yLow === null) continue

      const rectH = Math.max(Math.abs(yLow - yHigh), 3)
      const rectY = Math.min(yHigh, yLow)
      const rectW = Math.max(chartWidth - x1 - 60, 30)
      const isBull = ote.type === 'bullish'

      const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
      rect.setAttribute('x', x1)
      rect.setAttribute('y', rectY)
      rect.setAttribute('width', rectW)
      rect.setAttribute('height', rectH)
      rect.setAttribute('fill', isBull ? 'rgba(0,220,130,0.05)' : 'rgba(255,59,92,0.05)')
      rect.setAttribute('stroke', isBull ? 'rgba(0,220,130,0.2)' : 'rgba(255,59,92,0.2)')
      rect.setAttribute('stroke-width', '0.5')
      rect.setAttribute('stroke-dasharray', '3 2')
      rect.setAttribute('rx', '1')
      svg.appendChild(rect)

      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      text.setAttribute('x', x1 + 4)
      text.setAttribute('y', rectY + 10)
      text.setAttribute('fill', isBull ? '#00dc82' : '#ff3b5c')
      text.setAttribute('font-size', '7')
      text.setAttribute('font-family', "'JetBrains Mono', monospace")
      text.setAttribute('font-weight', '600')
      text.setAttribute('opacity', '0.7')
      text.textContent = `OTE ${isBull ? '▲' : '▼'}`
      svg.appendChild(text)
    }
  }

  // ── Liquidity Pools (BSL/SSL) ──
  function renderLiquidityPools(pools, svg, chartWidth) {
    for (const pool of pools.slice(-6)) {
      const ts = toUnix(pool.timestamp)
      const x1 = getX(ts)
      const py = getY(pool.price)
      if (x1 === null || py === null) continue

      const isBSL = pool.type === 'BSL'
      const color = isBSL ? '#ec4899' : '#8b5cf6'
      const lineEnd = Math.min(chartWidth - 60, x1 + 200)

      // Dashed line at liquidity level
      addLine(svg, x1, py, lineEnd, py, {
        stroke: color, strokeWidth: '0.8', strokeDasharray: '6 3', opacity: pool.swept ? '0.3' : '0.6',
      })

      // Small label
      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      text.setAttribute('x', x1 + 2)
      text.setAttribute('y', py - 3)
      text.setAttribute('fill', color)
      text.setAttribute('font-size', '7')
      text.setAttribute('font-family', "'JetBrains Mono', monospace")
      text.setAttribute('font-weight', '600')
      text.setAttribute('opacity', pool.swept ? '0.4' : '0.8')
      text.textContent = `${pool.type} ${pool.swept ? '✗' : '●'}`
      svg.appendChild(text)
    }
  }

  // ── Wave target projections (fib zones + invalidation) ──
  function renderWaveTargets(nextTargets, svg, chartWidth) {
    if (!nextTargets || !nextTargets.targets || nextTargets.targets.length === 0) return
    if (!candleSeriesRef.value) return

    const targets = nextTargets.targets
    const invalidation = nextTargets.invalidation
    const nextWave = nextTargets.nextWave
    const retracements = nextTargets.retracements || []

    // Render each target zone
    targets.forEach((t, i) => {
      const y = candleSeriesRef.value.priceToCoordinate(t.price)
      if (y === null || y < 0 || y > 2000) return

      const color = t.color || '#8b5cf6'
      const isPrimary = t.type === 'primary'
      const opacity = isPrimary ? 0.2 : 0.12
      const lineOpacity = isPrimary ? 0.7 : 0.4
      const zoneHeight = isPrimary ? 16 : 10

      // Target zone rectangle (pulsing)
      const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
      rect.setAttribute('x', chartWidth * 0.6)
      rect.setAttribute('y', y - zoneHeight / 2)
      rect.setAttribute('width', chartWidth * 0.4)
      rect.setAttribute('height', zoneHeight)
      rect.setAttribute('rx', '2')
      rect.setAttribute('fill', color)
      rect.setAttribute('opacity', opacity)
      if (isPrimary) {
        const anim = document.createElementNS('http://www.w3.org/2000/svg', 'animate')
        anim.setAttribute('attributeName', 'opacity')
        anim.setAttribute('values', `${opacity};${opacity * 2};${opacity}`)
        anim.setAttribute('dur', '2s')
        anim.setAttribute('repeatCount', 'indefinite')
        rect.appendChild(anim)
      }
      svg.appendChild(rect)

      // Dashed target line
      const line = document.createElementNS('http://www.w3.org/2000/svg', 'line')
      line.setAttribute('x1', chartWidth * 0.4)
      line.setAttribute('y1', y)
      line.setAttribute('x2', chartWidth)
      line.setAttribute('y2', y)
      line.setAttribute('stroke', color)
      line.setAttribute('stroke-width', isPrimary ? '1.5' : '1')
      line.setAttribute('stroke-dasharray', '6 4')
      line.setAttribute('opacity', lineOpacity)
      svg.appendChild(line)

      // Price badge on right edge
      const badgeW = 65
      const badgeH = 14
      const badgeX = chartWidth - badgeW - 4
      const badgeY = y - badgeH / 2

      const badge = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
      badge.setAttribute('x', badgeX)
      badge.setAttribute('y', badgeY)
      badge.setAttribute('width', badgeW)
      badge.setAttribute('height', badgeH)
      badge.setAttribute('rx', '3')
      badge.setAttribute('fill', color)
      badge.setAttribute('opacity', '0.25')
      badge.setAttribute('stroke', color)
      badge.setAttribute('stroke-width', '0.5')
      badge.setAttribute('stroke-opacity', '0.5')
      svg.appendChild(badge)

      const priceText = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      priceText.setAttribute('x', badgeX + badgeW / 2)
      priceText.setAttribute('y', y + 3.5)
      priceText.setAttribute('text-anchor', 'middle')
      priceText.setAttribute('fill', color)
      priceText.setAttribute('font-size', '9')
      priceText.setAttribute('font-weight', '700')
      priceText.setAttribute('font-family', 'monospace')
      priceText.textContent = parseFloat(t.price).toLocaleString('en-US', { maximumFractionDigits: 0 })
      svg.appendChild(priceText)

      // Label on left side of zone
      const labelText = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      labelText.setAttribute('x', chartWidth * 0.42)
      labelText.setAttribute('y', y - zoneHeight / 2 - 2)
      labelText.setAttribute('fill', color)
      labelText.setAttribute('font-size', '7')
      labelText.setAttribute('font-weight', '600')
      labelText.setAttribute('opacity', '0.8')
      labelText.textContent = t.label
      svg.appendChild(labelText)
    })

    // Invalidation level (red dashed line)
    if (invalidation) {
      const invY = candleSeriesRef.value.priceToCoordinate(invalidation.price)
      if (invY !== null && invY > 0 && invY < 2000) {
        const invLine = document.createElementNS('http://www.w3.org/2000/svg', 'line')
        invLine.setAttribute('x1', '0')
        invLine.setAttribute('y1', invY)
        invLine.setAttribute('x2', chartWidth)
        invLine.setAttribute('y2', invY)
        invLine.setAttribute('stroke', '#ef5350')
        invLine.setAttribute('stroke-width', '1')
        invLine.setAttribute('stroke-dasharray', '8 4')
        invLine.setAttribute('opacity', '0.5')
        svg.appendChild(invLine)

        // Invalidation badge
        const invBadge = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
        invBadge.setAttribute('x', chartWidth - 69)
        invBadge.setAttribute('y', invY - 7)
        invBadge.setAttribute('width', 65)
        invBadge.setAttribute('height', 14)
        invBadge.setAttribute('rx', '3')
        invBadge.setAttribute('fill', 'rgba(239,83,80,0.2)')
        invBadge.setAttribute('stroke', 'rgba(239,83,80,0.4)')
        invBadge.setAttribute('stroke-width', '0.5')
        svg.appendChild(invBadge)

        const invText = document.createElementNS('http://www.w3.org/2000/svg', 'text')
        invText.setAttribute('x', chartWidth - 36)
        invText.setAttribute('y', invY + 3.5)
        invText.setAttribute('text-anchor', 'middle')
        invText.setAttribute('fill', '#ef5350')
        invText.setAttribute('font-size', '9')
        invText.setAttribute('font-weight', '700')
        invText.setAttribute('font-family', 'monospace')
        invText.textContent = parseFloat(invalidation.price).toLocaleString('en-US', { maximumFractionDigits: 0 })
        svg.appendChild(invText)

        // Warning label
        const warnLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text')
        warnLabel.setAttribute('x', '8')
        warnLabel.setAttribute('y', invY - 4)
        warnLabel.setAttribute('fill', '#ef5350')
        warnLabel.setAttribute('font-size', '7')
        warnLabel.setAttribute('font-weight', '700')
        warnLabel.setAttribute('opacity', '0.7')
        warnLabel.textContent = `⚠ INVALID: ${invalidation.rule}`
        svg.appendChild(warnLabel)
      }
    }

    // Retracement lines (subtle between last 2 waves)
    retracements.forEach(r => {
      const y = candleSeriesRef.value.priceToCoordinate(r.price)
      if (y === null || y < 0 || y > 2000) return

      const retLine = document.createElementNS('http://www.w3.org/2000/svg', 'line')
      retLine.setAttribute('x1', chartWidth * 0.3)
      retLine.setAttribute('y1', y)
      retLine.setAttribute('x2', chartWidth * 0.6)
      retLine.setAttribute('y2', y)
      retLine.setAttribute('stroke', 'rgba(139,92,246,0.15)')
      retLine.setAttribute('stroke-width', '1')
      retLine.setAttribute('stroke-dasharray', '3 3')
      svg.appendChild(retLine)

      const retLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      retLabel.setAttribute('x', chartWidth * 0.3 + 2)
      retLabel.setAttribute('y', y - 2)
      retLabel.setAttribute('fill', '#555')
      retLabel.setAttribute('font-size', '7')
      retLabel.setAttribute('font-family', 'monospace')
      retLabel.textContent = `${(r.level * 100).toFixed(1)}%`
      svg.appendChild(retLabel)
    })
  }

  // ── Wave time estimation overlay (countdown + ETA + progress) ──
  function renderWaveTimeEstimate(timeEstimate, svg, chartWidth, chartHeight) {
    if (!timeEstimate || !timeEstimate.estimate) return
    const est = timeEstimate.estimate
    const currentWave = timeEstimate.currentWave
    const elapsed = timeEstimate.elapsed || 0
    const remaining = est.remaining || 0
    const progressPct = est.progressPct || 0

    // ETA info box (bottom-right corner of chart)
    const boxW = 155
    const boxH = 72
    const boxX = chartWidth - boxW - 8
    const boxY = chartHeight - boxH - 30

    // Background
    const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
    bg.setAttribute('x', boxX)
    bg.setAttribute('y', boxY)
    bg.setAttribute('width', boxW)
    bg.setAttribute('height', boxH)
    bg.setAttribute('rx', '6')
    bg.setAttribute('fill', 'rgba(11,17,32,0.92)')
    bg.setAttribute('stroke', '#1e293b')
    bg.setAttribute('stroke-width', '1')
    svg.appendChild(bg)

    // Title
    const title = document.createElementNS('http://www.w3.org/2000/svg', 'text')
    title.setAttribute('x', boxX + 8)
    title.setAttribute('y', boxY + 13)
    title.setAttribute('fill', '#555')
    title.setAttribute('font-size', '7')
    title.setAttribute('font-weight', '700')
    title.textContent = `⏱ WAVE ${currentWave} TIME`
    svg.appendChild(title)

    // Countdown (big number)
    const countdownColor = remaining <= 0 ? '#ef5350' : remaining < 10 ? '#fbbf24' : '#34d399'
    const countdownText = remaining <= 0 ? 'COMPLETING' : `${remaining}m left`
    const cd = document.createElementNS('http://www.w3.org/2000/svg', 'text')
    cd.setAttribute('x', boxX + 8)
    cd.setAttribute('y', boxY + 30)
    cd.setAttribute('fill', countdownColor)
    cd.setAttribute('font-size', '14')
    cd.setAttribute('font-weight', '900')
    cd.setAttribute('font-family', 'monospace')
    cd.textContent = countdownText
    // Add pulse animation if near completion
    if (remaining <= 5) {
      const anim = document.createElementNS('http://www.w3.org/2000/svg', 'animate')
      anim.setAttribute('attributeName', 'opacity')
      anim.setAttribute('values', '1;0.5;1')
      anim.setAttribute('dur', '1s')
      anim.setAttribute('repeatCount', 'indefinite')
      cd.appendChild(anim)
    }
    svg.appendChild(cd)

    // Formula
    const formula = document.createElementNS('http://www.w3.org/2000/svg', 'text')
    formula.setAttribute('x', boxX + 8)
    formula.setAttribute('y', boxY + 42)
    formula.setAttribute('fill', '#666')
    formula.setAttribute('font-size', '7')
    formula.textContent = est.formula || ''
    svg.appendChild(formula)

    // ETA times
    if (est.primaryEta) {
      const etaTime = new Date(est.primaryEta).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true })
      const etaText = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      etaText.setAttribute('x', boxX + 8)
      etaText.setAttribute('y', boxY + 54)
      etaText.setAttribute('fill', '#34d399')
      etaText.setAttribute('font-size', '8')
      etaText.setAttribute('font-weight', '600')
      etaText.setAttribute('font-family', 'monospace')
      etaText.textContent = `ETA: ${etaTime}`
      svg.appendChild(etaText)
    }

    // Progress bar
    const barX = boxX + 8
    const barY = boxY + 60
    const barW = boxW - 16
    const barH = 4

    const barBg = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
    barBg.setAttribute('x', barX)
    barBg.setAttribute('y', barY)
    barBg.setAttribute('width', barW)
    barBg.setAttribute('height', barH)
    barBg.setAttribute('rx', '2')
    barBg.setAttribute('fill', '#1a2440')
    svg.appendChild(barBg)

    const barFill = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
    barFill.setAttribute('x', barX)
    barFill.setAttribute('y', barY)
    barFill.setAttribute('width', Math.max(0, barW * progressPct / 100))
    barFill.setAttribute('height', barH)
    barFill.setAttribute('rx', '2')
    barFill.setAttribute('fill', countdownColor)
    barFill.setAttribute('opacity', '0.7')
    svg.appendChild(barFill)

    // Progress % label
    const pctLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text')
    pctLabel.setAttribute('x', boxX + boxW - 8)
    pctLabel.setAttribute('y', boxY + 54)
    pctLabel.setAttribute('text-anchor', 'end')
    pctLabel.setAttribute('fill', '#555')
    pctLabel.setAttribute('font-size', '8')
    pctLabel.setAttribute('font-family', 'monospace')
    pctLabel.textContent = `${progressPct}%`
    svg.appendChild(pctLabel)

    // Vertical completion line on the chart (if we can estimate time position)
    // This requires timeScale coordinate conversion which may not be available,
    // so we place it as a relative position on the right side of the chart
    if (remaining > 0 && est.primaryMinutes > 0) {
      const completionX = Math.min(chartWidth - 20, chartWidth * 0.7 + (chartWidth * 0.25 * progressPct / 100))

      const vLine = document.createElementNS('http://www.w3.org/2000/svg', 'line')
      vLine.setAttribute('x1', completionX)
      vLine.setAttribute('y1', '0')
      vLine.setAttribute('x2', completionX)
      vLine.setAttribute('y2', chartHeight - 30)
      vLine.setAttribute('stroke', '#34d399')
      vLine.setAttribute('stroke-width', '1')
      vLine.setAttribute('stroke-dasharray', '6 4')
      vLine.setAttribute('opacity', '0.3')
      const vAnim = document.createElementNS('http://www.w3.org/2000/svg', 'animate')
      vAnim.setAttribute('attributeName', 'opacity')
      vAnim.setAttribute('values', '0.2;0.4;0.2')
      vAnim.setAttribute('dur', '2s')
      vAnim.setAttribute('repeatCount', 'indefinite')
      vLine.appendChild(vAnim)
      svg.appendChild(vLine)
    }
  }

  // ── Signal markers on candle series ──
  function renderSignalMarkers() {
    if (!candleSeriesRef.value) return
    const signals = chartStore.overlays?.signals || []
    const markers = signals
      .filter(s => s.candle_timestamp)
      .map(s => ({
        time: toUnix(s.candle_timestamp),
        position: s.direction === 'buy' ? 'belowBar' : 'aboveBar',
        color: s.direction === 'buy' ? '#00dc82' : '#ff3b5c',
        shape: s.direction === 'buy' ? 'arrowUp' : 'arrowDown',
        text: s.engine?.replace('_', ' ') || '',
      }))
      .sort((a, b) => a.time - b.time)

    // Deduplicate
    const seen = new Set()
    const deduped = markers.filter(m => {
      const key = `${m.time}-${m.position}`
      if (seen.has(key)) return false
      seen.add(key)
      return true
    })

    candleSeriesRef.value.setMarkers(deduped.slice(-50))
  }

  // ── SVG helpers ──
  function addPath(svg, d, attrs) {
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path')
    path.setAttribute('d', d)
    for (const [k, v] of Object.entries(attrs)) {
      const attr = k.replace(/([A-Z])/g, '-$1').toLowerCase()
      path.setAttribute(attr, v)
    }
    svg.appendChild(path)
  }

  function addLine(svg, x1, y1, x2, y2, attrs) {
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line')
    line.setAttribute('x1', x1)
    line.setAttribute('y1', y1)
    line.setAttribute('x2', x2)
    line.setAttribute('y2', y2)
    for (const [k, v] of Object.entries(attrs)) {
      const attr = k.replace(/([A-Z])/g, '-$1').toLowerCase()
      line.setAttribute(attr, v)
    }
    svg.appendChild(line)
  }

  // ── Cleanup ──
  function cleanup() {
    if (svgOverlay.value) {
      svgOverlay.value.remove()
      svgOverlay.value = null
    }
  }

  // Throttled render — max one per animation frame for scroll/zoom
  let scrollRAF = null
  function throttledRender() {
    if (scrollRAF) return
    scrollRAF = requestAnimationFrame(() => {
      scrollRAF = null
      renderAll()
    })
  }

  // Re-render on scroll/zoom
  function attachChartListeners() {
    if (!chartRef.value) return
    chartRef.value.timeScale().subscribeVisibleLogicalRangeChange(throttledRender)
  }

  // Watch for overlay data changes — throttle via rAF to batch rapid updates
  let overlayRAF = null
  watch(() => chartStore.overlays, () => {
    if (overlayRAF) return
    overlayRAF = requestAnimationFrame(() => {
      overlayRAF = null
      renderAll()
    })
  }, { deep: true })

  return { renderAll, cleanup, attachChartListeners, setContainer }
}
