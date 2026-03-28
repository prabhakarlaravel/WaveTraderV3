import { ref, computed, onUnmounted } from 'vue'

const NS = 'http://www.w3.org/2000/svg'

/**
 * Drawing tools composable — state machine, mouse handlers, SVG rendering.
 *
 * @param {Ref} chartRef          lightweight-charts IChartApi
 * @param {Ref} candleSeriesRef   candle series reference (for coordinate conversion)
 * @param {Ref} containerRef      chart container DOM element
 * @param {Object} drawingStore   Pinia drawing store instance
 */
export function useDrawingTools(chartRef, candleSeriesRef, containerRef, drawingStore) {
  // ── State ──
  const activeTool = ref(null)       // null | 'trendline' | 'hline' | 'fib' | 'rect'
  const drawingState = ref('idle')   // 'idle' | 'placing_first' | 'placing_second'
  const pendingPoints = ref([])
  const previewPoint = ref(null)
  const isDrawingMode = computed(() => activeTool.value !== null)

  // ── Coordinate conversion ──
  function pixelToTime(x) {
    if (!chartRef.value) return null
    return chartRef.value.timeScale().coordinateToTime(x)
  }

  function pixelToPrice(y) {
    if (!candleSeriesRef.value) return null
    return candleSeriesRef.value.coordinateToPrice(y)
  }

  function timeToPixel(time) {
    if (!chartRef.value) return null
    const coord = chartRef.value.timeScale().timeToCoordinate(time)
    return coord !== null && isFinite(coord) ? coord : null
  }

  function priceToPixel(price) {
    if (!candleSeriesRef.value) return null
    const coord = candleSeriesRef.value.priceToCoordinate(price)
    return coord !== null && isFinite(coord) ? coord : null
  }

  // ── Tool selection ──
  function selectTool(tool) {
    if (activeTool.value === tool) {
      cancelDrawing()
      return
    }
    activeTool.value = tool
    drawingState.value = 'placing_first'
    pendingPoints.value = []
    previewPoint.value = null
  }

  function cancelDrawing() {
    activeTool.value = null
    drawingState.value = 'idle'
    pendingPoints.value = []
    previewPoint.value = null
  }

  // ── Generate unique ID ──
  function uid() {
    return 'draw_' + Math.random().toString(36).slice(2, 10)
  }

  // ── Mouse handlers ──
  function getChartCoords(e) {
    const rect = containerRef.value?.getBoundingClientRect()
    if (!rect) return null
    const x = e.clientX - rect.left
    const y = e.clientY - rect.top
    const time = pixelToTime(x)
    const price = pixelToPrice(y)
    if (time === null || price === null) return null
    return { time, price, x, y }
  }

  function onMouseDown(e) {
    if (!activeTool.value) return
    const coords = getChartCoords(e)
    if (!coords) return

    if (drawingState.value === 'placing_first') {
      pendingPoints.value = [{ time: coords.time, price: coords.price }]

      // Horizontal line completes on single click
      if (activeTool.value === 'hline') {
        commitDrawing()
        return
      }

      drawingState.value = 'placing_second'
    } else if (drawingState.value === 'placing_second') {
      pendingPoints.value.push({ time: coords.time, price: coords.price })
      commitDrawing()
    }
  }

  function onMouseMove(e) {
    if (!activeTool.value) return
    const coords = getChartCoords(e)
    if (!coords) return
    previewPoint.value = { time: coords.time, price: coords.price }
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') {
      cancelDrawing()
    }
    if (e.key === 'Delete' || e.key === 'Backspace') {
      // Delete last drawing for current context
      const drawings = drawingStore.currentDrawings
      if (drawings.length > 0) {
        drawingStore.removeDrawing(drawings[drawings.length - 1].id)
      }
    }
  }

  // ── Commit drawing to store ──
  function commitDrawing() {
    const tool = activeTool.value
    const points = [...pendingPoints.value]
    const symbolId = drawingStore.activeSymbolId
    const timeframe = drawingStore.activeTimeframe

    const base = { id: uid(), symbolId, timeframe, visible: true, locked: false, createdAt: new Date().toISOString() }

    if (tool === 'trendline') {
      drawingStore.addDrawing({ ...base, type: 'trendline', points, extend: true, style: { color: '#3b82f6', width: 1.5, dash: null } })
    } else if (tool === 'hline') {
      const price = points[0].price
      drawingStore.addDrawing({ ...base, type: 'hline', points: [{ time: null, price }], label: formatPrice(price), style: { color: '#f59e0b', width: 1, dash: [6, 3] } })
    } else if (tool === 'fib') {
      drawingStore.addDrawing({ ...base, type: 'fib', points, levels: [0, 0.236, 0.382, 0.5, 0.618, 0.786, 1.0], style: { color: '#8b5cf6', width: 0.8 } })
    } else if (tool === 'rect') {
      drawingStore.addDrawing({ ...base, type: 'rect', points, style: { color: '#06b6d4', fillOpacity: 0.08, width: 1 } })
    }

    // Reset state — stay in same tool for repeated drawing
    drawingState.value = 'placing_first'
    pendingPoints.value = []
    previewPoint.value = null
  }

  function formatPrice(p) {
    return p >= 1000 ? p.toLocaleString('en-US', { maximumFractionDigits: 0 })
      : p >= 1 ? p.toFixed(2)
      : p.toFixed(5)
  }

  // ══════════════════════════════════════════════════════
  // SVG RENDERING
  // ══════════════════════════════════════════════════════

  function renderDrawings(svg, w, h) {
    const drawings = drawingStore.currentDrawings
    for (const d of drawings) {
      try {
        if (d.type === 'trendline') renderTrendLine(svg, d, w, h)
        else if (d.type === 'hline') renderHLine(svg, d, w)
        else if (d.type === 'fib') renderFib(svg, d, w)
        else if (d.type === 'rect') renderRect(svg, d)
      } catch (e) { /* skip broken drawings */ }
    }

    // Render in-progress preview
    renderPreview(svg, w, h)
  }

  // ── Trend Line ──
  function renderTrendLine(svg, d, w, h) {
    if (d.points.length < 2) return
    const x1 = timeToPixel(d.points[0].time)
    const y1 = priceToPixel(d.points[0].price)
    const x2 = timeToPixel(d.points[1].time)
    const y2 = priceToPixel(d.points[1].price)

    // For extended lines, compute using price slope even if anchors are off-screen
    if (d.extend) {
      const p0 = d.points[0].price
      const p1 = d.points[1].price
      const t0 = d.points[0].time
      const t1 = d.points[1].time
      if (t1 === t0) return
      // Use price-based slope relative to time (more stable than pixel-based)
      const py0 = priceToPixel(p0)
      const py1 = priceToPixel(p1)
      if (py0 === null || py1 === null) return
      // Compute pixel positions for chart edges using any two visible price points
      const pxSlope = (py1 - py0) / ((x2 ?? 1) - (x1 ?? 0) || 1)
      if (x1 !== null && x2 !== null) {
        const slope = (py1 - py0) / (x2 - x1)
        const ly1 = py0 + slope * (0 - x1)
        const ly2 = py0 + slope * (w - x1)
        makeLine(svg, 0, ly1, w, ly2, d.style.color, d.style.width, d.style.dash)
        // Anchor dots (only if visible)
        if (x1 >= 0 && x1 <= w) makeCircle(svg, x1, py0, 3, d.style.color)
        if (x2 >= 0 && x2 <= w) makeCircle(svg, x2, py1, 3, d.style.color)
      }
      return
    }

    // Non-extended: both anchors must be visible
    if (x1 === null || y1 === null || x2 === null || y2 === null) return
    makeLine(svg, x1, y1, x2, y2, d.style.color, d.style.width, d.style.dash)
    makeCircle(svg, x1, y1, 3, d.style.color)
    makeCircle(svg, x2, y2, 3, d.style.color)
  }

  // ── Horizontal Line ──
  function renderHLine(svg, d, w) {
    const price = d.points[0]?.price
    if (price === null) return
    const y = priceToPixel(price)
    if (y === null) return

    makeLine(svg, 0, y, w, y, d.style.color, d.style.width, d.style.dash)

    // Price label
    const text = makeText(svg, w - 8, y - 4, d.label || formatPrice(price), d.style.color, 9, 'end')
    const bg = makeEl(svg, 'rect')
    const bbox = text.getBBox?.() || { x: w - 60, y: y - 14, width: 52, height: 14 }
    bg.setAttribute('x', bbox.x - 3)
    bg.setAttribute('y', bbox.y - 1)
    bg.setAttribute('width', bbox.width + 6)
    bg.setAttribute('height', bbox.height + 2)
    bg.setAttribute('fill', 'var(--card, #0d1b3a)')
    bg.setAttribute('rx', 2)
    svg.insertBefore(bg, text)
  }

  // ── Fibonacci Retracement ──
  function renderFib(svg, d, w) {
    if (d.points.length < 2) return
    const p0 = d.points[0].price  // 100% (swing start)
    const p1 = d.points[1].price  // 0% (swing end)
    const range = p1 - p0
    const levels = d.levels || [0, 0.236, 0.382, 0.5, 0.618, 0.786, 1.0]
    const color = d.style.color

    const levelColors = ['#ef535020', '#f59e0b18', '#eab30818', '#22c55e18', '#06b6d418', '#8b5cf618', '#ec489918']

    for (let i = 0; i < levels.length; i++) {
      const lvl = levels[i]
      const price = p1 - range * lvl
      const y = priceToPixel(price)
      if (y === null) continue

      // Horizontal line
      makeLine(svg, 0, y, w, y, color, d.style.width, [4, 3])

      // Level label
      makeText(svg, 6, y - 3, `${lvl.toFixed(3)}  —  ${formatPrice(price)}`, color, 9, 'start')

      // Shaded zone between levels
      if (i < levels.length - 1) {
        const nextPrice = p1 - range * levels[i + 1]
        const nextY = priceToPixel(nextPrice)
        if (nextY !== null) {
          const rect = makeEl(svg, 'rect')
          rect.setAttribute('x', 0)
          rect.setAttribute('y', Math.min(y, nextY))
          rect.setAttribute('width', w)
          rect.setAttribute('height', Math.abs(nextY - y))
          rect.setAttribute('fill', levelColors[i] || `${color}10`)
        }
      }
    }

    // Anchor dots
    for (const pt of d.points) {
      const px = timeToPixel(pt.time)
      const py = priceToPixel(pt.price)
      if (px !== null && py !== null) makeCircle(svg, px, py, 4, color)
    }
  }

  // ── Rectangle Zone ──
  function renderRect(svg, d) {
    if (d.points.length < 2) return
    const x1 = timeToPixel(d.points[0].time)
    const y1 = priceToPixel(d.points[0].price)
    const x2 = timeToPixel(d.points[1].time)
    const y2 = priceToPixel(d.points[1].price)
    if (x1 === null || y1 === null || x2 === null || y2 === null) return

    const rect = makeEl(svg, 'rect')
    rect.setAttribute('x', Math.min(x1, x2))
    rect.setAttribute('y', Math.min(y1, y2))
    rect.setAttribute('width', Math.abs(x2 - x1))
    rect.setAttribute('height', Math.abs(y2 - y1))
    rect.setAttribute('fill', `${d.style.color}${Math.round((d.style.fillOpacity || 0.08) * 255).toString(16).padStart(2, '0')}`)
    rect.setAttribute('stroke', d.style.color)
    rect.setAttribute('stroke-width', d.style.width)
  }

  // ── Preview (in-progress drawing) ──
  function renderPreview(svg, w, h) {
    if (!activeTool.value || pendingPoints.value.length === 0 || !previewPoint.value) return
    const p0 = pendingPoints.value[0]
    const pm = previewPoint.value
    const ghostColor = '#ffffff80'

    if (activeTool.value === 'trendline') {
      const x1 = timeToPixel(p0.time), y1 = priceToPixel(p0.price)
      const x2 = timeToPixel(pm.time), y2 = priceToPixel(pm.price)
      if (x1 !== null && y1 !== null && x2 !== null && y2 !== null) {
        makeLine(svg, x1, y1, x2, y2, ghostColor, 1.5, [5, 5])
        makeCircle(svg, x1, y1, 3, ghostColor)
      }
    } else if (activeTool.value === 'fib') {
      const y0 = priceToPixel(p0.price)
      const ym = priceToPixel(pm.price)
      if (y0 !== null && ym !== null) {
        makeLine(svg, 0, y0, w, y0, ghostColor, 0.8, [4, 3])
        makeLine(svg, 0, ym, w, ym, ghostColor, 0.8, [4, 3])
      }
    } else if (activeTool.value === 'rect') {
      const x1 = timeToPixel(p0.time), y1 = priceToPixel(p0.price)
      const x2 = timeToPixel(pm.time), y2 = priceToPixel(pm.price)
      if (x1 !== null && y1 !== null && x2 !== null && y2 !== null) {
        const rect = makeEl(svg, 'rect')
        rect.setAttribute('x', Math.min(x1, x2))
        rect.setAttribute('y', Math.min(y1, y2))
        rect.setAttribute('width', Math.abs(x2 - x1))
        rect.setAttribute('height', Math.abs(y2 - y1))
        rect.setAttribute('fill', 'rgba(255,255,255,0.05)')
        rect.setAttribute('stroke', ghostColor)
        rect.setAttribute('stroke-width', 1)
        rect.setAttribute('stroke-dasharray', '5 5')
      }
    }
  }

  // ── SVG helpers ──
  function makeEl(svg, tag) {
    const el = document.createElementNS(NS, tag)
    svg.appendChild(el)
    return el
  }

  function makeLine(svg, x1, y1, x2, y2, color, width = 1, dash = null) {
    const line = makeEl(svg, 'line')
    line.setAttribute('x1', x1)
    line.setAttribute('y1', y1)
    line.setAttribute('x2', x2)
    line.setAttribute('y2', y2)
    line.setAttribute('stroke', color)
    line.setAttribute('stroke-width', width)
    if (dash) line.setAttribute('stroke-dasharray', Array.isArray(dash) ? dash.join(' ') : dash)
    return line
  }

  function makeCircle(svg, cx, cy, r, color) {
    const circle = makeEl(svg, 'circle')
    circle.setAttribute('cx', cx)
    circle.setAttribute('cy', cy)
    circle.setAttribute('r', r)
    circle.setAttribute('fill', color)
    return circle
  }

  function makeText(svg, x, y, text, color, size = 9, anchor = 'start') {
    const el = makeEl(svg, 'text')
    el.setAttribute('x', x)
    el.setAttribute('y', y)
    el.setAttribute('fill', color)
    el.setAttribute('font-size', size)
    el.setAttribute('font-family', "'JetBrains Mono', monospace")
    el.setAttribute('text-anchor', anchor)
    el.textContent = text
    return el
  }

  // ── Keyboard listener ──
  if (typeof window !== 'undefined') {
    window.addEventListener('keydown', onKeyDown)
    onUnmounted(() => window.removeEventListener('keydown', onKeyDown))
  }

  return {
    activeTool, isDrawingMode, drawingState,
    selectTool, cancelDrawing,
    onMouseDown, onMouseMove,
    renderDrawings,
  }
}
