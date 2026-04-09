import { watch, ref, nextTick } from 'vue'
import { LineSeries } from 'lightweight-charts'
import { toISTEpoch } from '../utils/timezone'
// useWaveProjectile removed — projected curves/cones were unreliable

/**
 * Full SVG overlay system for rendering waves, OBs, FVGs, BOS/CHOCH, VWAP,
 * and wave projectile projections on top of the lightweight-charts canvas.
 */
export function useChartOverlays(chartRef, candleSeriesRef, chartStore, overlayToggles) {
  let vwapSeries = null
  let vwapU1Series = null
  let vwapL1Series = null
  let fibPriceLines = [] // Track Fib price lines for cleanup
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

    if (svgOverlay.value && svgOverlay.value.parentElement === chartWrapper && svgOverlay.value.isConnected) return svgOverlay.value

    // Reset stale reference
    svgOverlay.value = null

    // Remove old SVGs from both container and wrapper
    container.querySelectorAll('.svg-chart-overlay').forEach(el => el.remove())
    chartWrapper.querySelectorAll('.svg-chart-overlay').forEach(el => el.remove())

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


  // ── Render all overlays ──
  function renderAll() {
    if (!chartRef.value || !candleSeriesRef.value || !containerEl) return
    const container = containerEl

    const svg = ensureSvgOverlay(container)
    if (!svg) return
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
    if (toggles.legs) renderSubLegs(overlays.subLegs || [], svg)
    if (toggles.waves) renderWaveLabels(overlays.waveLabels || [], svg, false)

    // Live edge: connect last confirmed wave to current price + forming wave tentative pivots
    if (toggles.waves) renderFormingWave(overlays.formingWave || null, overlays.waveLabels || [], svg)

    // Fib retracement + extension levels mapped to actual wave structure
    renderWaveFibs(overlays, svg, w, toggles)

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

  // ── Main wave labels — dashed lines + circle node labels ──
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

    const total = points.length

    // Dashed connection lines between consecutive main wave pivots
    for (let i = 0; i < total - 1; i++) {
      const p1 = points[i]
      const p2 = points[i + 1]
      const isCorr = p2.isCorrection
      const isLast = i === total - 2
      const color = isLast ? '#34d399' : (isCorr ? '#f59e0b' : '#8b5cf6')

      // Fade older segments
      const age = i / Math.max(total - 2, 1)
      const opacity = isLast ? 0.8 : (0.3 + age * 0.4)

      const line = document.createElementNS('http://www.w3.org/2000/svg', 'line')
      line.setAttribute('x1', p1.wx)
      line.setAttribute('y1', p1.wy)
      line.setAttribute('x2', p2.wx)
      line.setAttribute('y2', p2.wy)
      line.setAttribute('stroke', color)
      line.setAttribute('stroke-width', isLast ? '2.4' : '2.2')
      line.setAttribute('stroke-dasharray', '10 5')
      line.setAttribute('opacity', opacity.toFixed(2))
      line.setAttribute('stroke-linecap', 'round')
      if (isLast) {
        const anim = document.createElementNS('http://www.w3.org/2000/svg', 'animate')
        anim.setAttribute('attributeName', 'opacity')
        anim.setAttribute('values', '0.5;0.95;0.5')
        anim.setAttribute('dur', '2s')
        anim.setAttribute('repeatCount', 'indefinite')
        line.appendChild(anim)
      }
      svg.appendChild(line)
    }

    // Circle node labels at each pivot
    for (let i = 0; i < total; i++) {
      const p = points[i]
      const isAbove = p.type === 'high'
      const isCorr = p.isCorrection
      const isLast = i === total - 1
      const color = isLast ? '#34d399' : (isCorr ? '#f59e0b' : '#8b5cf6')
      const age = i / Math.max(total - 1, 1)

      // Only show labels for recent pivots (last 10), faded dots for older
      const recentStart = Math.max(0, total - 10)
      if (i < recentStart) {
        const dotOpacity = 0.12 + age * 0.2
        const dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle')
        dot.setAttribute('cx', p.wx)
        dot.setAttribute('cy', p.wy)
        dot.setAttribute('r', (1.5 + age * 1).toFixed(1))
        dot.setAttribute('fill', color)
        dot.setAttribute('opacity', dotOpacity.toFixed(2))
        svg.appendChild(dot)
        continue
      }

      const labelY = isAbove ? p.wy - 18 : p.wy + 18

      // Stem line
      addLine(svg, p.wx, p.wy, p.wx, isAbove ? labelY + 8 : labelY - 8, {
        stroke: color, strokeWidth: '0.7', opacity: '0.4',
      })

      // Circle background
      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle')
      circle.setAttribute('cx', p.wx)
      circle.setAttribute('cy', labelY)
      circle.setAttribute('r', '11')
      circle.setAttribute('fill', '#0c1221')
      circle.setAttribute('stroke', color)
      circle.setAttribute('stroke-width', '1.5')
      svg.appendChild(circle)

      // Label text
      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      text.setAttribute('x', p.wx)
      text.setAttribute('y', labelY + 4.5)
      text.setAttribute('text-anchor', 'middle')
      text.setAttribute('fill', color)
      text.setAttribute('font-size', '12')
      text.setAttribute('font-weight', '800')
      text.setAttribute('font-family', "'JetBrains Mono', monospace")
      text.textContent = p.label
      svg.appendChild(text)

      // Pulse on last pivot
      if (isLast) {
        const pulse = document.createElementNS('http://www.w3.org/2000/svg', 'circle')
        pulse.setAttribute('cx', p.wx)
        pulse.setAttribute('cy', p.wy)
        pulse.setAttribute('r', '4.5')
        pulse.setAttribute('fill', '#34d399')
        pulse.setAttribute('opacity', '0.2')
        const animR = document.createElementNS('http://www.w3.org/2000/svg', 'animate')
        animR.setAttribute('attributeName', 'r')
        animR.setAttribute('values', '4.5;10;4.5')
        animR.setAttribute('dur', '2s')
        animR.setAttribute('repeatCount', 'indefinite')
        pulse.appendChild(animR)
        svg.appendChild(pulse)
      }
    }
  }

  // ── Forming Wave: live edge from last confirmed wave to current price ──
  function renderFormingWave(formingWave, waveLabels, svg) {
    if (!formingWave || !formingWave.tentative) return
    if (!waveLabels || waveLabels.length === 0) return

    const lastLabel = waveLabels[waveLabels.length - 1]
    const startX = getX(toUnix(lastLabel.timestamp))
    const startY = getY(lastLabel.price)
    if (startX === null || startY === null) return

    // Current price point (end of forming wave)
    const endX = getX(toUnix(formingWave.currentTime))
    const endY = getY(formingWave.currentPrice)
    if (endX === null || endY === null) return

    // Gap must be meaningful (at least 10px)
    if (Math.abs(endX - startX) < 10) return

    // ── Dashed green line from last confirmed wave to current price ──
    const edgeLine = document.createElementNS('http://www.w3.org/2000/svg', 'line')
    edgeLine.setAttribute('x1', startX)
    edgeLine.setAttribute('y1', startY)
    edgeLine.setAttribute('x2', endX)
    edgeLine.setAttribute('y2', endY)
    edgeLine.setAttribute('stroke', '#34d399')
    edgeLine.setAttribute('stroke-width', '2')
    edgeLine.setAttribute('stroke-dasharray', '8 6')
    edgeLine.setAttribute('opacity', '0.6')
    edgeLine.setAttribute('stroke-linecap', 'round')
    // Pulse animation
    const anim = document.createElementNS('http://www.w3.org/2000/svg', 'animate')
    anim.setAttribute('attributeName', 'opacity')
    anim.setAttribute('values', '0.3;0.7;0.3')
    anim.setAttribute('dur', '2.5s')
    anim.setAttribute('repeatCount', 'indefinite')
    edgeLine.appendChild(anim)
    svg.appendChild(edgeLine)

    // ── Tentative pivots (faint cyan dots + thin connecting lines) ──
    const tentPivots = formingWave.tentativePivots || []
    if (tentPivots.length > 0) {
      // Build all points: start → tentative pivots → current
      const allPoints = [{ x: startX, y: startY }]

      for (const tp of tentPivots) {
        const px = getX(toUnix(tp.timestamp))
        const py = getY(tp.price)
        if (px !== null && py !== null) {
          allPoints.push({ x: px, y: py, label: tp.type === 'high' ? 'H' : 'L', price: tp.price })
        }
      }
      allPoints.push({ x: endX, y: endY })

      // Draw thin dotted connecting lines between tentative points
      for (let i = 0; i < allPoints.length - 1; i++) {
        const p1 = allPoints[i]
        const p2 = allPoints[i + 1]
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line')
        line.setAttribute('x1', p1.x)
        line.setAttribute('y1', p1.y)
        line.setAttribute('x2', p2.x)
        line.setAttribute('y2', p2.y)
        line.setAttribute('stroke', '#22d3ee')
        line.setAttribute('stroke-width', '1.2')
        line.setAttribute('stroke-dasharray', '4 4')
        line.setAttribute('opacity', '0.4')
        svg.appendChild(line)
      }

      // Draw small circle markers at each tentative pivot
      for (let i = 1; i < allPoints.length - 1; i++) {
        const p = allPoints[i]
        const dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle')
        dot.setAttribute('cx', p.x)
        dot.setAttribute('cy', p.y)
        dot.setAttribute('r', '3')
        dot.setAttribute('fill', '#22d3ee')
        dot.setAttribute('opacity', '0.5')
        svg.appendChild(dot)
      }
    }

    // ── Tentative label at current price: "1?" or "A?" ──
    const nextLabel = formingWave.nextLabel || '?'
    const labelY = endY < startY ? endY - 22 : endY + 22 // Above if price went up, below if down

    // Small stem line
    const stem = document.createElementNS('http://www.w3.org/2000/svg', 'line')
    stem.setAttribute('x1', endX)
    stem.setAttribute('y1', endY)
    stem.setAttribute('x2', endX)
    stem.setAttribute('y2', endY < startY ? labelY + 9 : labelY - 9)
    stem.setAttribute('stroke', '#34d399')
    stem.setAttribute('stroke-width', '0.7')
    stem.setAttribute('opacity', '0.4')
    svg.appendChild(stem)

    // Circle with "?" label
    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle')
    circle.setAttribute('cx', endX)
    circle.setAttribute('cy', labelY)
    circle.setAttribute('r', '11')
    circle.setAttribute('fill', '#0c1221')
    circle.setAttribute('stroke', '#34d399')
    circle.setAttribute('stroke-width', '1.5')
    circle.setAttribute('stroke-dasharray', '3 2') // Dashed border = tentative
    svg.appendChild(circle)

    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
    text.setAttribute('x', endX)
    text.setAttribute('y', labelY + 4.5)
    text.setAttribute('text-anchor', 'middle')
    text.setAttribute('fill', '#34d399')
    text.setAttribute('font-size', '10')
    text.setAttribute('font-weight', '700')
    text.setAttribute('font-family', "'JetBrains Mono', monospace")
    text.textContent = nextLabel + '?'
    svg.appendChild(text)

    // Pulsing dot at current price
    const pulse = document.createElementNS('http://www.w3.org/2000/svg', 'circle')
    pulse.setAttribute('cx', endX)
    pulse.setAttribute('cy', endY)
    pulse.setAttribute('r', '4')
    pulse.setAttribute('fill', '#34d399')
    pulse.setAttribute('opacity', '0.3')
    const pulseAnim = document.createElementNS('http://www.w3.org/2000/svg', 'animate')
    pulseAnim.setAttribute('attributeName', 'r')
    pulseAnim.setAttribute('values', '4;10;4')
    pulseAnim.setAttribute('dur', '2s')
    pulseAnim.setAttribute('repeatCount', 'indefinite')
    pulse.appendChild(pulseAnim)
    svg.appendChild(pulse)
  }

  // ── Sub-Legs (lower degree pivots within each main wave) ──
  function renderSubLegs(subLegs, svg) {
    if (!subLegs || subLegs.length < 2) return

    // Build pixel points
    const points = []
    for (const sl of subLegs) {
      const sx = getX(toUnix(sl.timestamp))
      const sy = getY(sl.price)
      if (sx !== null && sy !== null) {
        points.push({ ...sl, sx, sy })
      }
    }
    if (points.length < 2) return

    // Group sub-legs by parentWave for connecting lines
    const groups = {}
    for (const p of points) {
      const key = p.parentWave || '_'
      if (!groups[key]) groups[key] = []
      groups[key].push(p)
    }

    // Ensure glow filter for sub-legs
    if (!svg.querySelector('#subLegGlow')) {
      const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs')
      defs.id = 'subLegDefs'
      const filter = document.createElementNS('http://www.w3.org/2000/svg', 'filter')
      filter.setAttribute('id', 'subLegGlow')
      const blur = document.createElementNS('http://www.w3.org/2000/svg', 'feGaussianBlur')
      blur.setAttribute('stdDeviation', '1.5')
      blur.setAttribute('result', 'b')
      filter.appendChild(blur)
      const merge = document.createElementNS('http://www.w3.org/2000/svg', 'feMerge')
      const mn1 = document.createElementNS('http://www.w3.org/2000/svg', 'feMergeNode')
      mn1.setAttribute('in', 'b')
      merge.appendChild(mn1)
      const mn2 = document.createElementNS('http://www.w3.org/2000/svg', 'feMergeNode')
      mn2.setAttribute('in', 'SourceGraphic')
      merge.appendChild(mn2)
      filter.appendChild(merge)
      defs.appendChild(filter)
      svg.insertBefore(defs, svg.firstChild)
    }

    const subLegColor = '#22d3ee' // Cyan for sub-legs

    // Draw dotted connection lines within each parent wave group
    for (const key of Object.keys(groups)) {
      const gPts = groups[key]
      if (gPts.length < 2) continue

      for (let i = 0; i < gPts.length - 1; i++) {
        const p1 = gPts[i]
        const p2 = gPts[i + 1]
        const isActive = key === groups[Object.keys(groups).pop()] && i === gPts.length - 2

        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line')
        line.setAttribute('x1', p1.sx)
        line.setAttribute('y1', p1.sy)
        line.setAttribute('x2', p2.sx)
        line.setAttribute('y2', p2.sy)
        line.setAttribute('stroke', isActive ? '#34d399' : subLegColor)
        line.setAttribute('stroke-width', isActive ? '2' : '1.8')
        line.setAttribute('stroke-dasharray', '3 4')
        line.setAttribute('opacity', isActive ? '0.9' : '0.75')
        line.setAttribute('stroke-linecap', 'round')
        line.setAttribute('filter', 'url(#subLegGlow)')
        svg.appendChild(line)
      }
    }

    // Draw circle node labels for each sub-leg pivot
    const totalPoints = points.length
    for (let i = 0; i < totalPoints; i++) {
      const p = points[i]
      const isAbove = p.type === 'high'
      const isLast = i === totalPoints - 1
      const isActive = isLast || (p.parentWave === points[totalPoints - 1]?.parentWave)
      const color = isActive && p.parentWave === points[totalPoints - 1]?.parentWave ? '#34d399' : subLegColor
      const labelY = isAbove ? p.sy - 16 : p.sy + 16

      // Stem line
      addLine(svg, p.sx, p.sy, p.sx, isAbove ? labelY + 6 : labelY - 6, {
        stroke: color, strokeWidth: '0.6', opacity: '0.5',
      })

      // Circle background (smaller than main wave labels)
      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle')
      circle.setAttribute('cx', p.sx)
      circle.setAttribute('cy', labelY)
      circle.setAttribute('r', '8')
      circle.setAttribute('fill', '#0c1221')
      circle.setAttribute('stroke', color)
      circle.setAttribute('stroke-width', '1.2')
      svg.appendChild(circle)

      // Label text
      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
      text.setAttribute('x', p.sx)
      text.setAttribute('y', labelY + 3.5)
      text.setAttribute('text-anchor', 'middle')
      text.setAttribute('fill', color)
      text.setAttribute('font-size', '9')
      text.setAttribute('font-weight', '800')
      text.setAttribute('font-family', "'JetBrains Mono', monospace")
      text.textContent = p.label
      svg.appendChild(text)
    }
  }

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

  // ── Fibonacci Levels — Hybrid: Native Price Lines + SVG Zones ──
  // Warm Gold monochrome palette — distinct from all other overlay colors
  const FIB_GOLD        = 'rgba(212, 160, 84, '   // base gold (append opacity + ')')
  const FIB_GOLD_HEX    = '#d4a054'
  const FIB_BRIGHT_HEX  = '#e6b450'               // 0.618 emphasis
  const FIB_DIM_HEX     = '#b08840'               // extensions

  function cleanupFibLines() {
    if (!candleSeriesRef.value) return
    for (const pl of fibPriceLines) {
      try { candleSeriesRef.value.removePriceLine(pl) } catch { /* already removed */ }
    }
    fibPriceLines = []
  }

  // ── Extract the latest impulse wave anchors from waveLabels ──
  // Scans backwards through waveLabels to find the most recent 1→2→3→4→5 sequence
  /**
   * Extract the CURRENT wave's Fib anchors from waveLabels.
   * Uses the last two confirmed waves to define the retracement range,
   * and the preceding impulse wave's length for extension projection.
   *
   * For each wave we show:
   *   Wave 2: retrace of W1 (C→1 range)     + W3 extension targets
   *   Wave 3: retrace of W1 (C→1 range)     + W3 extension targets
   *   Wave 4: retrace of W3 (2→3 range)     + W5 extension targets
   *   Wave 5: retrace of W3 (2→3 range)     + W5 extension targets
   *   Wave A: retrace of full impulse (0→5)  + correction targets
   *   Wave B: retrace of A (5→A range)       + C extension targets
   *   Wave C: retrace of A (5→A range)       + C extension targets
   */
  function extractWaveAnchors(waveLabels) {
    if (!waveLabels || waveLabels.length < 3) return null

    // Build a map of the last occurrence of each wave label
    const lastIdx = {}
    for (let i = waveLabels.length - 1; i >= 0; i--) {
      const label = String(waveLabels[i].label)
      if (!(label in lastIdx)) {
        lastIdx[label] = i
      }
    }

    const waveAt = (label) => {
      const idx = lastIdx[label]
      if (idx === undefined) return null
      const wl = waveLabels[idx]
      return { price: wl.price, time: wl.time, type: wl.type, label }
    }

    // Current wave = last label in the array
    const currentLabel = String(waveLabels[waveLabels.length - 1].label)

    // Determine impulse direction from Wave 1
    let bullish = true
    const w1Idx = lastIdx['1']
    if (w1Idx !== undefined && w1Idx > 0) {
      bullish = waveLabels[w1Idx].price > waveLabels[w1Idx - 1].price
    }

    // Define retracement swing (from→to) and extension base depending on current wave
    let swingFrom = null  // Retracement 0% (impulse end)
    let swingTo = null    // Retracement 100% (impulse start)
    let extBase = null    // Extension projects from this price
    let extLength = null  // Length to project (in price units)
    let retLabel = ''     // Label prefix for retracement
    let extLabel = ''     // Label prefix for extension
    let actual = null     // Actual wave endpoint to show marker

    if (['2', '3'].includes(currentLabel)) {
      // Retrace: Wave 1 (from C→1)
      const w1 = waveAt('1')
      const w1StartIdx = lastIdx['1'] > 0 ? lastIdx['1'] - 1 : null
      const w1Start = w1StartIdx !== null ? { price: waveLabels[w1StartIdx].price } : null
      if (!w1 || !w1Start) return null
      swingFrom = w1    // 0%
      swingTo = w1Start // 100%
      retLabel = 'W1→2'
      // Extension: W1 length from W2 end
      const w2 = waveAt('2')
      extBase = w2
      extLength = Math.abs(w1.price - w1Start.price)
      extLabel = 'W3 Ext'
      actual = currentLabel === '3' ? waveAt('3') : waveAt('2')
    } else if (['4', '5'].includes(currentLabel)) {
      // Retrace: Wave 3 (from 2→3)
      const w1 = waveAt('1')
      const w2 = waveAt('2')
      const w3 = waveAt('3')
      if (!w2 || !w3) return null
      swingFrom = w3    // 0%
      swingTo = w2      // 100%
      retLabel = 'W3→4'
      // Extension: W5 = W1 length projected from W4 end (classic Elliott rule)
      const w4 = waveAt('4')
      const w1StartIdx = lastIdx['1'] > 0 ? lastIdx['1'] - 1 : null
      const w1Start = w1StartIdx !== null ? { price: waveLabels[w1StartIdx].price } : null
      extBase = w4
      extLength = (w1 && w1Start) ? Math.abs(w1.price - w1Start.price) : Math.abs(w3.price - w2.price)
      extLabel = 'W5 Ext'
      actual = currentLabel === '5' ? waveAt('5') : waveAt('4')
    } else if (['A', 'B', 'C'].includes(currentLabel)) {
      // Retrace: full impulse (start→5) for wave A, or A swing for B/C
      const w5 = waveAt('5')
      const wA = waveAt('A')
      if (currentLabel === 'A' && w5) {
        // Retrace of full impulse
        const w1Idx2 = lastIdx['1']
        const impStart = w1Idx2 !== undefined && w1Idx2 > 0 ? { price: waveLabels[w1Idx2 - 1].price } : null
        if (!impStart) return null
        swingFrom = w5
        swingTo = impStart
        retLabel = 'Imp→A'
        extBase = wA || w5
        extLength = Math.abs(w5.price - impStart.price)
        extLabel = 'A Ext'
        actual = wA
      } else if (['B', 'C'].includes(currentLabel) && w5 && wA) {
        // Retrace of Wave A (5→A)
        swingFrom = wA
        swingTo = w5
        retLabel = 'A→B'
        const wB = waveAt('B')
        extBase = wB || wA
        extLength = Math.abs(wA.price - w5.price)
        extLabel = 'C Ext'
        actual = currentLabel === 'C' ? waveAt('C') : waveAt('B')
      } else {
        return null
      }
    } else {
      return null
    }

    if (!swingFrom || !swingTo) return null

    return { swingFrom, swingTo, extBase, extLength, retLabel, extLabel, actual, bullish, currentLabel }
  }

  // ── Main Fib renderer: wave-mapped retracement + extension ──
  function renderWaveFibs(overlays, svg, chartWidth, toggles) {
    // Always clean previous fib price lines before re-rendering
    cleanupFibLines()

    if (!candleSeriesRef.value) return

    const waveLabels = overlays.waveLabels || []
    const nextTargets = overlays.nextTargets || {}
    const invalidation = nextTargets.invalidation

    // Extract wave anchor points for CURRENT wave position
    const anchors = extractWaveAnchors(waveLabels)
    if (!anchors) return

    const { swingFrom, swingTo, extBase, extLength, retLabel, extLabel, actual, bullish } = anchors

    // Swing range (always positive)
    const swingRange = Math.abs(swingFrom.price - swingTo.price)
    if (swingRange <= 0) return

    // Direction of swing: swingFrom is the impulse end (0%), swingTo is the start (100%)
    const swingUp = swingFrom.price > swingTo.price // true if 0% is high (retrace goes down)

    // ── FIB SET 1: Retracement levels ──
    if (toggles.fibRetrace) {
      const retraceLevels = [
        { level: 0,     label: `${retLabel}  0%`,    lineWidth: 1, lineStyle: 0 },
        { level: 0.236, label: `${retLabel}  23.6%`, lineWidth: 1, lineStyle: 2 },
        { level: 0.382, label: `${retLabel}  38.2%`, lineWidth: 1, lineStyle: 2 },
        { level: 0.5,   label: `${retLabel}  50%`,   lineWidth: 1, lineStyle: 2 },
        { level: 0.618, label: `${retLabel}  61.8%`, lineWidth: 2, lineStyle: 2, bright: true },
        { level: 0.786, label: `${retLabel}  78.6%`, lineWidth: 1, lineStyle: 2 },
        { level: 1.0,   label: `${retLabel}  100%`,  lineWidth: 1, lineStyle: 0 },
      ]

      // Calculate prices: 0% = swingFrom (impulse end), 100% = swingTo (impulse start)
      for (const fl of retraceLevels) {
        fl.price = swingUp
          ? swingFrom.price - fl.level * swingRange   // retrace down
          : swingFrom.price + fl.level * swingRange   // retrace up
      }

      // Create native price lines for retracement
      for (const fl of retraceLevels) {
        if (!fl.price || !isFinite(fl.price)) continue
        const color = fl.bright ? FIB_BRIGHT_HEX : FIB_GOLD_HEX
        try {
          const pl = candleSeriesRef.value.createPriceLine({
            price: fl.price,
            color: color,
            lineWidth: fl.lineWidth,
            lineStyle: fl.lineStyle,
            axisLabelVisible: true,
            title: fl.label,
            lineVisible: true,
          })
          fibPriceLines.push(pl)
        } catch { /* chart not ready */ }
      }

      // Actual retracement marker (where the corrective wave actually landed)
      if (actual) {
        const actualRetrace = swingUp
          ? (swingFrom.price - actual.price) / swingRange
          : (actual.price - swingFrom.price) / swingRange
        const actualPct = (actualRetrace * 100).toFixed(1)
        const actualY = getY(actual.price)
        if (actualY !== null && actualY > 0 && actualY < 2000) {
          const markerLine = document.createElementNS('http://www.w3.org/2000/svg', 'line')
          markerLine.setAttribute('x1', Math.max(0, chartWidth - 250))
          markerLine.setAttribute('y1', actualY)
          markerLine.setAttribute('x2', chartWidth - 70)
          markerLine.setAttribute('y2', actualY)
          markerLine.setAttribute('stroke', '#ef5350')
          markerLine.setAttribute('stroke-width', '1.5')
          markerLine.setAttribute('stroke-dasharray', '3 2')
          markerLine.setAttribute('opacity', '0.7')
          svg.appendChild(markerLine)

          const markerLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text')
          markerLabel.setAttribute('x', chartWidth - 245)
          markerLabel.setAttribute('y', actualY - 4)
          markerLabel.setAttribute('fill', '#ef5350')
          markerLabel.setAttribute('font-size', '8')
          markerLabel.setAttribute('font-weight', '700')
          markerLabel.setAttribute('font-family', 'monospace')
          markerLabel.textContent = `${actual.label || 'Actual'}: ${actualPct}%`
          svg.appendChild(markerLabel)

          if (actualRetrace > 0.786) {
            const warnText = document.createElementNS('http://www.w3.org/2000/svg', 'text')
            warnText.setAttribute('x', chartWidth - 245)
            warnText.setAttribute('y', actualY + 10)
            warnText.setAttribute('fill', '#ef5350')
            warnText.setAttribute('font-size', '7')
            warnText.setAttribute('opacity', '0.6')
            warnText.textContent = 'Deep retracement!'
            svg.appendChild(warnText)
          }
        }
      }

      // Golden Pocket zone (0.5→0.618)
      const fib500 = retraceLevels.find(f => f.level === 0.5)
      const fib618 = retraceLevels.find(f => f.level === 0.618)
      if (fib500 && fib618) {
        const y500 = getY(fib500.price)
        const y618 = getY(fib618.price)
        if (y500 !== null && y618 !== null) {
          const yTop = Math.min(y500, y618)
          const yBot = Math.max(y500, y618)
          const zoneH = yBot - yTop
          if (zoneH > 2 && zoneH < 500) {
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
            rect.setAttribute('x', '0')
            rect.setAttribute('y', yTop)
            rect.setAttribute('width', chartWidth - 70)
            rect.setAttribute('height', zoneH)
            rect.setAttribute('fill', FIB_GOLD + '0.04)')
            svg.appendChild(rect)

            const gpLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text')
            gpLabel.setAttribute('x', chartWidth - 80)
            gpLabel.setAttribute('y', yTop + zoneH / 2 + 3)
            gpLabel.setAttribute('text-anchor', 'end')
            gpLabel.setAttribute('fill', FIB_GOLD + '0.3)')
            gpLabel.setAttribute('font-size', '7')
            gpLabel.setAttribute('font-weight', '800')
            gpLabel.setAttribute('letter-spacing', '1')
            gpLabel.textContent = 'GOLDEN POCKET'
            svg.appendChild(gpLabel)
          }
        }
      }

      // OTE zone (0.618→0.786)
      const fib786 = retraceLevels.find(f => f.level === 0.786)
      if (fib618 && fib786) {
        const y618 = getY(fib618.price)
        const y786 = getY(fib786.price)
        if (y618 !== null && y786 !== null) {
          const yTop = Math.min(y618, y786)
          const yBot = Math.max(y618, y786)
          const zoneH = yBot - yTop
          if (zoneH > 2 && zoneH < 500) {
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
            rect.setAttribute('x', '0')
            rect.setAttribute('y', yTop)
            rect.setAttribute('width', chartWidth - 70)
            rect.setAttribute('height', zoneH)
            rect.setAttribute('fill', FIB_GOLD + '0.05)')
            rect.setAttribute('stroke', FIB_GOLD + '0.12)')
            rect.setAttribute('stroke-width', '0.5')
            svg.appendChild(rect)

            const accent = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
            accent.setAttribute('x', '0')
            accent.setAttribute('y', yTop)
            accent.setAttribute('width', '2')
            accent.setAttribute('height', zoneH)
            accent.setAttribute('fill', FIB_GOLD + '0.2)')
            svg.appendChild(accent)

            const oteLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text')
            oteLabel.setAttribute('x', '8')
            oteLabel.setAttribute('y', yTop + zoneH / 2 + 3)
            oteLabel.setAttribute('fill', FIB_GOLD + '0.35)')
            oteLabel.setAttribute('font-size', '7')
            oteLabel.setAttribute('font-weight', '800')
            oteLabel.setAttribute('letter-spacing', '1')
            oteLabel.textContent = 'OTE ZONE'
            svg.appendChild(oteLabel)
          }
        }
      }
    }

    // ── FIB SET 2: Extensions (project preceding wave length from corrective end) ──
    if (toggles.fibExt && extBase && extLength > 0) {
      const extLevels = [
        { level: 0.618, label: `${extLabel} 0.618`, lineWidth: 1, lineStyle: 3 },
        { level: 1.0,   label: `${extLabel} 1.000`, lineWidth: 1, lineStyle: 3 },
        { level: 1.272, label: `${extLabel} 1.272`, lineWidth: 1, lineStyle: 3 },
        { level: 1.618, label: `${extLabel} 1.618`, lineWidth: 1, lineStyle: 3, bright: true },
        { level: 2.618, label: `${extLabel} 2.618`, lineWidth: 1, lineStyle: 3 },
      ]

      // Calculate extension prices: project from extBase
      for (const el of extLevels) {
        el.price = bullish
          ? extBase.price + el.level * extLength   // extend up
          : extBase.price - el.level * extLength   // extend down (bearish)
      }

      // Create native price lines for extensions
      for (const el of extLevels) {
        if (!el.price || !isFinite(el.price)) continue
        const color = el.bright ? FIB_BRIGHT_HEX : FIB_DIM_HEX
        try {
          const pl = candleSeriesRef.value.createPriceLine({
            price: el.price,
            color: color,
            lineWidth: el.lineWidth,
            lineStyle: el.lineStyle,
            axisLabelVisible: true,
            title: el.label,
            lineVisible: true,
          })
          fibPriceLines.push(pl)
        } catch { /* ignore */ }
      }

      // Extension target zone badges (pulsing)
      for (const el of extLevels) {
        const y = getY(el.price)
        if (y === null || y < 0 || y > 2000) continue

        const badgeW = 80
        const badgeH = 14
        const badgeX = chartWidth - 70 - badgeW - 8
        const badgeY = y - badgeH / 2

        const badge = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
        badge.setAttribute('x', badgeX)
        badge.setAttribute('y', badgeY)
        badge.setAttribute('width', badgeW)
        badge.setAttribute('height', badgeH)
        badge.setAttribute('rx', '2')
        badge.setAttribute('fill', FIB_GOLD + '0.06)')
        badge.setAttribute('stroke', FIB_GOLD + '0.15)')
        badge.setAttribute('stroke-width', '0.5')
        const anim = document.createElementNS('http://www.w3.org/2000/svg', 'animate')
        anim.setAttribute('attributeName', 'opacity')
        anim.setAttribute('values', '0.7;1;0.7')
        anim.setAttribute('dur', '2.5s')
        anim.setAttribute('repeatCount', 'indefinite')
        badge.appendChild(anim)
        svg.appendChild(badge)

        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
        text.setAttribute('x', badgeX + 6)
        text.setAttribute('y', y + 3)
        text.setAttribute('fill', FIB_DIM_HEX)
        text.setAttribute('font-size', '7')
        text.setAttribute('font-weight', '700')
        text.setAttribute('font-family', 'monospace')
        text.textContent = el.label
        svg.appendChild(text)
      }
    }

    // ── Invalidation level (always shown when waves are on) ──
    if (toggles.waves && invalidation) {
      const invY = getY(invalidation.price)
      if (invY !== null && invY > 0 && invY < 2000) {
        const invLine = document.createElementNS('http://www.w3.org/2000/svg', 'line')
        invLine.setAttribute('x1', '0')
        invLine.setAttribute('y1', invY)
        invLine.setAttribute('x2', chartWidth - 70)
        invLine.setAttribute('y2', invY)
        invLine.setAttribute('stroke', '#ef5350')
        invLine.setAttribute('stroke-width', '1')
        invLine.setAttribute('stroke-dasharray', '8 4')
        invLine.setAttribute('opacity', '0.5')
        svg.appendChild(invLine)

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
    cleanupFibLines()
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
