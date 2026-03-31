/**
 * Wave Projectile System — renders projected wave paths, probability cones,
 * fib targets, time markers, and next-wave projections on the chart SVG.
 *
 * Shows: completed waves → current wave projected path → next wave (corrective/impulse) path
 * Data source: chartStore.overlays { waveLabels, nextTargets, timeEstimate }
 */

const NS = 'http://www.w3.org/2000/svg'

function el(tag, attrs) {
  const e = document.createElementNS(NS, tag)
  for (const [k, v] of Object.entries(attrs)) {
    e.setAttribute(k.replace(/([A-Z])/g, '-$1').toLowerCase(), String(v))
  }
  return e
}

function add(parent, tag, attrs) {
  const e = el(tag, attrs)
  parent.appendChild(e)
  return e
}

function addAnim(parent, attrName, values, dur, repeat = 'indefinite') {
  const a = el('animate', { attributeName: attrName, values, dur, repeatCount: repeat })
  parent.appendChild(a)
  return a
}

/**
 * Create a projectile renderer bound to chart coordinate helpers.
 * @param {Function} getX - timestamp → pixel x
 * @param {Function} getY - price → pixel y
 * @param {Function} toUnix - ISO/raw timestamp → unix epoch for chart
 */
export function createProjectileRenderer(getX, getY, toUnix) {

  /**
   * Ensure gradient/filter defs exist in the SVG (idempotent).
   */
  function ensureDefs(svg) {
    if (svg.querySelector('#proj-defs')) return
    const defs = el('defs', { id: 'proj-defs' })
    // Cone gradient — current wave (purple)
    const cg1 = el('linearGradient', { id: 'projCone1', x1: 0, y1: 0, x2: 1, y2: 1 })
    add(cg1, 'stop', { offset: '0%', stopColor: '#8b5cf6', stopOpacity: '0.12' })
    add(cg1, 'stop', { offset: '100%', stopColor: '#8b5cf6', stopOpacity: '0.02' })
    defs.appendChild(cg1)
    // Cone gradient — next wave (amber)
    const cg2 = el('linearGradient', { id: 'projCone2', x1: 0, y1: 0, x2: 1, y2: 1 })
    add(cg2, 'stop', { offset: '0%', stopColor: '#fbbf24', stopOpacity: '0.08' })
    add(cg2, 'stop', { offset: '100%', stopColor: '#fbbf24', stopOpacity: '0.01' })
    defs.appendChild(cg2)
    // Invalidation gradient
    const ig = el('linearGradient', { id: 'projInvG', x1: 0, y1: 0, x2: 0, y2: 1 })
    add(ig, 'stop', { offset: '0%', stopColor: '#ef5350', stopOpacity: '0.06' })
    add(ig, 'stop', { offset: '100%', stopColor: '#ef5350', stopOpacity: '0' })
    defs.appendChild(ig)
    // Progress gradient
    const pg = el('linearGradient', { id: 'projProgG', x1: 0, y1: 0, x2: 1, y2: 0 })
    add(pg, 'stop', { offset: '0%', stopColor: '#8b5cf6' })
    add(pg, 'stop', { offset: '50%', stopColor: '#22d3ee' })
    add(pg, 'stop', { offset: '100%', stopColor: '#34d399' })
    defs.appendChild(pg)
    svg.insertBefore(defs, svg.firstChild)
  }

  /**
   * Main render function — appends projectile elements to the shared overlay SVG.
   */
  function render(svg, w, h, overlays) {
    const waveLabels = overlays.waveLabels || []
    const nextTargets = overlays.nextTargets || {}
    const timeEstimate = overlays.timeEstimate || {}

    if (waveLabels.length < 3) return // Need at least 3 pivots to project

    // Map wave pivots to pixel coordinates
    const pivots = []
    for (const wl of waveLabels) {
      const x = getX(toUnix(wl.timestamp))
      const y = getY(parseFloat(wl.price))
      if (x !== null && y !== null) {
        pivots.push({
          label: wl.label,
          price: parseFloat(wl.price),
          x, y,
          isHigh: wl.type === 'high',
          isCorrection: wl.isCorrection,
        })
      }
    }
    if (pivots.length < 2) return

    const lastPivot = pivots[pivots.length - 1] // Current price point
    const prevPivot = pivots[pivots.length - 2] // Wave start

    // Current wave metadata
    const currentWave = timeEstimate.currentWave || nextTargets.nextWave
    const isImpulse = ['1', '3', '5'].includes(currentWave)

    // Targets
    const targets = nextTargets.targets || []
    const primaryTarget = targets.find(t => t.type === 'primary')
    const extTarget = targets.find(t => t.type !== 'primary' && t.price)
    const invalidation = nextTargets.invalidation

    if (!primaryTarget) return // Can't project without at least a primary target

    const targetPrice = parseFloat(primaryTarget.price)
    const targetY = getY(targetPrice)
    if (targetY === null) return

    // ── Estimate target X position from wave progress ──
    const est = timeEstimate.estimate || {}
    const progressPct = Math.max(8, est.progressPct || 50)
    const remaining = est.remaining || 0

    const elapsedWidth = Math.abs(lastPivot.x - prevPivot.x)
    const estTotalWidth = elapsedWidth / (progressPct / 100)
    const remainingWidth = Math.max(estTotalWidth - elapsedWidth, 25)
    const targetX = Math.min(lastPivot.x + remainingWidth, w - 85)

    // ── Next wave sequence labels ──
    const isCorrectiveLabel = ['A', 'B', 'C'].includes(currentWave)
    const waveSequence = isCorrectiveLabel
      ? { w2Label: 'C' === currentWave ? '1' : currentWave === 'A' ? 'B' : 'C',
          w3Label: 'C' === currentWave ? '2' : currentWave === 'A' ? 'C' : '1' }
      : { w2Label: isImpulse ? 'A' : String(Math.min(parseInt(currentWave || '0') + 1, 5)),
          w3Label: isImpulse ? 'B' : (['4'].includes(currentWave) ? 'A' : String(Math.min(parseInt(currentWave || '0') + 2, 5))) }

    // ── Next wave (2nd projected) — retracement of the current projected move ──
    const projectedRange = targetPrice - lastPivot.price // Range of current wave projection
    const w2_382 = targetPrice - projectedRange * 0.382
    const w2_618 = targetPrice - projectedRange * 0.618
    const w2Y382 = getY(w2_382)
    const w2Y618 = getY(w2_618)
    const w2Price = w2_382 // Use 0.382 as the primary target for wave 2

    // Wave 2 position — next wave ~55% duration of current, minimum 40px
    const w2Width = Math.max(estTotalWidth * 0.55, 40)
    const w2EndX = Math.min(targetX + w2Width, w - 55)

    // ── 3rd projected wave — continues from wave 2 end ──
    // If current is impulse end (5) → next is A (down) → then B (up bounce, 0.382-0.618 of A)
    // If current is corrective (C) → next is 1 (up) → then 2 (pullback, 0.382-0.618 of 1)
    const w2Range = Math.abs(targetPrice - w2Price)
    const goingUp = targetPrice > lastPivot.price
    const w2GoingUp = !goingUp // 2nd wave goes opposite
    const w3GoingUp = goingUp  // 3rd wave goes same as current (alternating)

    // Wave 3 target: 0.382-0.618 retracement of wave 2
    const w3Price = w3GoingUp
      ? w2Price + w2Range * 0.5
      : w2Price - w2Range * 0.5
    const w3Y = getY(w3Price)

    // Wave 3 position — ~45% duration of current, minimum 35px
    const w3Width = Math.max(estTotalWidth * 0.4, 35)
    const w3EndX = Math.min(w2EndX + w3Width, w - 20)

    ensureDefs(svg)

    // Projectile group
    const g = el('g', { class: 'wave-projectile' })

    // ════════════════════════════════════════
    // 1. INVALIDATION ZONE
    // ════════════════════════════════════════
    if (invalidation) {
      const invPrice = parseFloat(invalidation.price)
      const invY = getY(invPrice)
      if (invY !== null && invY > 0 && invY < h) {
        const zoneBottom = Math.min(invY + 80, h)
        add(g, 'rect', {
          x: 0, y: invY, width: w - 70, height: zoneBottom - invY,
          fill: 'url(#projInvG)',
        })
        add(g, 'line', {
          x1: 0, y1: invY, x2: w - 70, y2: invY,
          stroke: '#ef5350', strokeWidth: '1', strokeDasharray: '8 5', opacity: '0.4',
        })
        add(g, 'text', {
          x: 5, y: invY - 4, fill: '#ef5350', fontSize: '7', fontWeight: '700', opacity: '0.6',
        }).textContent = `⚠ INVALID: ${invalidation.rule || ''}`

        // Price badge
        add(g, 'rect', {
          x: w - 140, y: invY - 8, width: 64, height: 16, rx: 3,
          fill: 'rgba(239,83,80,0.15)', stroke: 'rgba(239,83,80,0.35)', strokeWidth: '0.5',
        })
        add(g, 'text', {
          x: w - 108, y: invY + 4, textAnchor: 'middle',
          fill: '#ef5350', fontSize: '9', fontWeight: '700', fontFamily: 'monospace',
        }).textContent = Math.round(invPrice).toLocaleString()
      }
    }

    // ════════════════════════════════════════
    // 2. PROBABILITY CONES
    // ════════════════════════════════════════
    {
      // Current wave cone — widens from lastPivot toward target
      const spread = Math.abs(targetY - lastPivot.y) * 0.35
      const coneTopY = goingUp
        ? Math.min(targetY - spread, targetY - 15)
        : Math.max(targetY + spread, targetY + 15)
      const coneBotY = goingUp
        ? Math.max(targetY + spread * 1.2, targetY + 15)
        : Math.min(targetY - spread * 1.2, targetY - 15)

      const cone1 = add(g, 'path', {
        d: `M${lastPivot.x},${lastPivot.y} L${targetX},${coneTopY} L${targetX},${coneBotY} Z`,
        fill: 'url(#projCone1)', stroke: 'rgba(139,92,246,0.15)', strokeWidth: '0.5',
      })
      addAnim(cone1, 'opacity', '0.7;1;0.7', '3s')

      // 2nd wave cone — from target toward wave 2
      if (w2Y382 !== null && w2EndX > targetX + 10) {
        const aSpread = Math.abs((w2Y382 || targetY) - targetY) * 0.4
        const aTop = Math.min(targetY, w2Y382 || targetY) - aSpread * 0.4
        const aBot = Math.max(targetY, w2Y618 || w2Y382 || targetY) + aSpread * 0.5

        const cone2 = add(g, 'path', {
          d: `M${targetX},${targetY} L${w2EndX},${aTop} L${w2EndX},${aBot} Z`,
          fill: 'url(#projCone2)', stroke: 'rgba(251,191,36,0.1)', strokeWidth: '0.5',
        })
        addAnim(cone2, 'opacity', '0.6;1;0.6', '3.5s')
      }

      // 3rd wave cone — from wave 2 end toward wave 3 (fainter)
      if (w3Y !== null && w2Y382 !== null && w3EndX > w2EndX + 10) {
        const w3Spread = Math.abs((w3Y || w2Y382) - w2Y382) * 0.5
        const w3Top = Math.min(w2Y382, w3Y || w2Y382) - w3Spread * 0.5
        const w3Bot = Math.max(w2Y382, w3Y || w2Y382) + w3Spread * 0.6

        const cone3 = add(g, 'path', {
          d: `M${w2EndX},${w2Y382} L${w3EndX},${w3Top} L${w3EndX},${w3Bot} Z`,
          fill: 'rgba(96,165,250,0.04)', stroke: 'rgba(96,165,250,0.08)', strokeWidth: '0.5',
        })
        addAnim(cone3, 'opacity', '0.5;0.9;0.5', '4s')
      }
    }

    // ════════════════════════════════════════
    // 3. FIB TARGET ZONES
    // ════════════════════════════════════════
    {
      const fibs = [
        { price: targetPrice, label: primaryTarget.label || '1.000', color: '#34d399', primary: true },
      ]
      if (extTarget) {
        fibs.push({ price: parseFloat(extTarget.price), label: extTarget.label || '1.618', color: '#a78bfa', primary: false })
      }

      for (const f of fibs) {
        const fy = getY(f.price)
        if (fy === null || fy < 0 || fy > h) continue
        const zh = f.primary ? 14 : 10
        const startX = targetX - 40

        // Zone rect
        const rect = add(g, 'rect', {
          x: startX, y: fy - zh / 2, width: w - 70 - startX, height: zh, rx: 2,
          fill: f.color, opacity: f.primary ? '0.08' : '0.04',
        })
        if (f.primary) addAnim(rect, 'opacity', '0.06;0.14;0.06', '2.5s')

        // Dashed line
        add(g, 'line', {
          x1: startX, y1: fy, x2: w - 70, y2: fy,
          stroke: f.color, strokeWidth: f.primary ? '1.2' : '0.7',
          strokeDasharray: '5 3', opacity: f.primary ? '0.5' : '0.25',
        })

        // Price badge
        add(g, 'rect', {
          x: w - 140, y: fy - 8, width: 64, height: 16, rx: 3,
          fill: f.color, opacity: '0.15', stroke: f.color, strokeWidth: '0.5', strokeOpacity: '0.3',
        })
        add(g, 'text', {
          x: w - 108, y: fy + 3.5, textAnchor: 'middle',
          fill: f.color, fontSize: '9', fontWeight: '700', fontFamily: 'monospace',
        }).textContent = Math.round(f.price).toLocaleString()

        // Fib label
        add(g, 'text', {
          x: startX + 2, y: fy - zh / 2 - 2,
          fill: f.color, fontSize: '7', fontWeight: '600', opacity: '0.6',
        }).textContent = f.label
      }

      // Wave 2 retracement levels
      if (w2Y382 !== null && w2EndX > targetX + 10) {
        const retLevels = [
          { price: w2_382, y: w2Y382, label: '0.382 ret' },
          { price: w2_618, y: w2Y618, label: '0.618 ret' },
        ]
        for (const r of retLevels) {
          if (r.y === null || r.y < 0 || r.y > h) continue
          add(g, 'line', {
            x1: targetX + 10, y1: r.y, x2: w2EndX + 30, y2: r.y,
            stroke: '#fbbf24', strokeWidth: '0.6', strokeDasharray: '3 3', opacity: '0.3',
          })
          add(g, 'text', {
            x: targetX + 12, y: r.y - 3,
            fill: '#fbbf24', fontSize: '6.5', fontWeight: '600', opacity: '0.5',
          }).textContent = r.label
        }
      }
    }

    // ════════════════════════════════════════
    // 4. TIME MARKERS
    // ════════════════════════════════════════
    {
      // Current wave ETA
      if (remaining > 0) {
        const etaLine = add(g, 'line', {
          x1: targetX, y1: 0, x2: targetX, y2: h - 30,
          stroke: '#22d3ee', strokeWidth: '0.8', strokeDasharray: '3 5', opacity: '0.15',
        })
        addAnim(etaLine, 'opacity', '0.1;0.25;0.1', '3s')

        // Bottom badge
        const etaText = remaining < 60 ? `~${remaining}m` : `~${Math.floor(remaining / 60)}h ${remaining % 60}m`
        add(g, 'rect', {
          x: targetX - 30, y: h - 28, width: 60, height: 18, rx: 5,
          fill: 'rgba(12,18,33,0.95)', stroke: '#22d3ee', strokeWidth: '0.7', strokeOpacity: '0.4',
        })
        add(g, 'text', {
          x: targetX, y: h - 16, textAnchor: 'middle',
          fill: '#22d3ee', fontSize: '8', fontWeight: '700', fontFamily: 'monospace',
        }).textContent = etaText

        // Diamond at intersection
        const diam = add(g, 'polygon', {
          points: `${targetX},${targetY - 5} ${targetX + 4},${targetY} ${targetX},${targetY + 5} ${targetX - 4},${targetY}`,
          fill: '#22d3ee', opacity: '0.5',
        })
        addAnim(diam, 'opacity', '0.3;0.7;0.3', '2s')
      }

      // Wave 2 ETA
      if (w2EndX > targetX + 10 && w2EndX < w - 50) {
        const w2Minutes = Math.round(remaining * 1.55)
        const w2Text = w2Minutes < 60 ? `~${w2Minutes}m` : `~${Math.floor(w2Minutes / 60)}h ${w2Minutes % 60}m`

        const w2Line = add(g, 'line', {
          x1: w2EndX, y1: 0, x2: w2EndX, y2: h - 30,
          stroke: '#fbbf24', strokeWidth: '0.6', strokeDasharray: '3 5', opacity: '0.1',
        })
        addAnim(w2Line, 'opacity', '0.06;0.18;0.06', '3.5s')

        add(g, 'rect', {
          x: w2EndX - 30, y: h - 28, width: 60, height: 18, rx: 5,
          fill: 'rgba(12,18,33,0.95)', stroke: '#fbbf24', strokeWidth: '0.6', strokeOpacity: '0.3',
        })
        add(g, 'text', {
          x: w2EndX, y: h - 16, textAnchor: 'middle',
          fill: '#fbbf24', fontSize: '8', fontWeight: '700', fontFamily: 'monospace',
        }).textContent = w2Text
      }

      // Wave 3 ETA
      if (w3EndX > w2EndX + 10 && w3EndX < w - 20) {
        const w3Minutes = Math.round(remaining * 1.95)
        const w3Text = w3Minutes < 60 ? `~${w3Minutes}m` : `~${Math.floor(w3Minutes / 60)}h ${w3Minutes % 60}m`

        const w3Line = add(g, 'line', {
          x1: w3EndX, y1: 0, x2: w3EndX, y2: h - 30,
          stroke: '#60a5fa', strokeWidth: '0.5', strokeDasharray: '3 5', opacity: '0.08',
        })
        addAnim(w3Line, 'opacity', '0.05;0.14;0.05', '4s')

        add(g, 'rect', {
          x: w3EndX - 30, y: h - 28, width: 60, height: 18, rx: 5,
          fill: 'rgba(12,18,33,0.95)', stroke: '#60a5fa', strokeWidth: '0.5', strokeOpacity: '0.25',
        })
        add(g, 'text', {
          x: w3EndX, y: h - 16, textAnchor: 'middle',
          fill: '#60a5fa', fontSize: '8', fontWeight: '700', fontFamily: 'monospace',
        }).textContent = w3Text
      }

      // Progress bar (current wave)
      if (prevPivot && progressPct > 0) {
        const progStartX = prevPivot.x
        const progW = targetX - progStartX
        const progY = h - 36

        add(g, 'rect', { x: progStartX, y: progY, width: progW, height: 2.5, rx: 1.2, fill: '#111827' })
        const fillRect = add(g, 'rect', {
          x: progStartX, y: progY,
          width: progW * Math.min(progressPct / 100, 1), height: 2.5, rx: 1.2,
          fill: 'url(#projProgG)', opacity: '0.7',
        })
        addAnim(fillRect, 'opacity', '0.5;0.9;0.5', '2s')

        // Current position pip
        const pipX = progStartX + progW * Math.min(progressPct / 100, 1)
        add(g, 'circle', { cx: pipX, cy: progY + 1.2, r: 3, fill: '#22d3ee', stroke: '#0a0e1a', strokeWidth: '1.2' })
      }
    }

    // ════════════════════════════════════════
    // 5. PROJECTED PATHS
    // ════════════════════════════════════════
    {
      // ── Current wave: curved path from lastPivot → target ──
      const cp1x = lastPivot.x + (targetX - lastPivot.x) * 0.35
      const cp1y = lastPivot.y + (targetY - lastPivot.y) * 0.15
      const cp2x = lastPivot.x + (targetX - lastPivot.x) * 0.7
      const cp2y = targetY - (targetY - lastPivot.y) * 0.05
      const curvePath = `M${lastPivot.x},${lastPivot.y} C${cp1x},${cp1y} ${cp2x},${cp2y} ${targetX},${targetY}`

      // Glow
      add(g, 'path', {
        d: curvePath, fill: 'none', stroke: '#34d399', strokeWidth: '5', opacity: '0.06',
      })
      // Main animated path
      const mainPath = add(g, 'path', {
        d: curvePath, fill: 'none', stroke: '#34d399', strokeWidth: '2',
        strokeDasharray: '8 4', opacity: '0.8',
      })
      addAnim(mainPath, 'stroke-dashoffset', '0;-24', '1.5s')

      // Target pulse
      const pulse = add(g, 'circle', { cx: targetX, cy: targetY, r: 4.5, fill: '#34d399', opacity: '0.2' })
      addAnim(pulse, 'r', '4.5;10;4.5', '2s')
      addAnim(pulse, 'opacity', '0.3;0.06;0.3', '2s')
      add(g, 'circle', { cx: targetX, cy: targetY, r: 3.5, fill: 'none', stroke: '#34d399', strokeWidth: '1.5', opacity: '0.8' })

      // Projected label for current wave end (dashed circle)
      const projLabelY = goingUp ? targetY - 22 : targetY + 22
      add(g, 'line', {
        x1: targetX, y1: targetY, x2: targetX, y2: projLabelY + (goingUp ? 8 : -8),
        stroke: '#34d399', strokeWidth: '0.6', opacity: '0.35',
      })
      add(g, 'circle', {
        cx: targetX, cy: projLabelY, r: 10,
        fill: '#0c1221', stroke: '#34d399', strokeWidth: '1', strokeDasharray: '3 2',
      })
      add(g, 'text', {
        x: targetX, y: projLabelY + 3.5, textAnchor: 'middle',
        fill: '#34d399', fontSize: '10', fontWeight: '800', fontFamily: "'JetBrains Mono',monospace", opacity: '0.8',
      }).textContent = currentWave || '?'

      // ── 2nd projected wave: curved path from target → wave 2 ──
      if (w2Y382 !== null && w2EndX > targetX + 15) {
        const acp1x = targetX + (w2EndX - targetX) * 0.3
        const acp1y = targetY + (w2Y382 - targetY) * 0.12
        const acp2x = targetX + (w2EndX - targetX) * 0.65
        const acp2y = w2Y382 + (targetY - w2Y382) * 0.05
        const w2Curve = `M${targetX},${targetY} C${acp1x},${acp1y} ${acp2x},${acp2y} ${w2EndX},${w2Y382}`

        // Glow
        add(g, 'path', { d: w2Curve, fill: 'none', stroke: '#fbbf24', strokeWidth: '4', opacity: '0.04' })
        // Main path
        const w2Path = add(g, 'path', {
          d: w2Curve, fill: 'none', stroke: '#fbbf24', strokeWidth: '1.8',
          strokeDasharray: '6 4', opacity: '0.6',
        })
        addAnim(w2Path, 'stroke-dashoffset', '0;-20', '2s')

        // Deeper alternate path (0.618 retracement)
        if (w2Y618 !== null) {
          const dcp1x = targetX + (w2EndX - targetX) * 0.3
          const dcp1y = targetY + (w2Y618 - targetY) * 0.08
          const dcp2x = targetX + (w2EndX - targetX) * 0.7
          const dcp2y = w2Y618 + (targetY - w2Y618) * 0.04
          const deepCurve = `M${targetX},${targetY} C${dcp1x},${dcp1y} ${dcp2x},${dcp2y} ${w2EndX},${w2Y618}`

          const deepPath = add(g, 'path', {
            d: deepCurve, fill: 'none', stroke: '#fbbf24', strokeWidth: '1',
            strokeDasharray: '4 4', opacity: '0.28',
          })
          addAnim(deepPath, 'stroke-dashoffset', '0;-16', '2.5s')
        }

        // Wave 2 end dot (pulse)
        const w2Dot = add(g, 'circle', { cx: w2EndX, cy: w2Y382, r: 3.5, fill: 'none', stroke: '#fbbf24', strokeWidth: '1.3', opacity: '0.6' })
        addAnim(w2Dot, 'r', '3.5;7;3.5', '2.5s')
        addAnim(w2Dot, 'opacity', '0.5;0.12;0.5', '2.5s')

        // Projected label for wave 2 (dashed circle)
        const w2LabelY = w2GoingUp ? w2Y382 - 22 : w2Y382 + 22
        add(g, 'line', {
          x1: w2EndX, y1: w2Y382, x2: w2EndX, y2: w2LabelY + (w2GoingUp ? 8 : -8),
          stroke: '#fbbf24', strokeWidth: '0.6', opacity: '0.3',
        })
        add(g, 'circle', {
          cx: w2EndX, cy: w2LabelY, r: 10,
          fill: '#0c1221', stroke: '#fbbf24', strokeWidth: '1', strokeDasharray: '3 2',
        })
        add(g, 'text', {
          x: w2EndX, y: w2LabelY + 3.5, textAnchor: 'middle',
          fill: '#fbbf24', fontSize: '10', fontWeight: '800', fontFamily: "'JetBrains Mono',monospace", opacity: '0.7',
        }).textContent = waveSequence.w2Label

        // ── 3rd projected wave: curved path from wave 2 → wave 3 ──
        if (w3Y !== null && w3EndX > w2EndX + 15) {
          const w3cp1x = w2EndX + (w3EndX - w2EndX) * 0.3
          const w3cp1y = w2Y382 + (w3Y - w2Y382) * 0.1
          const w3cp2x = w2EndX + (w3EndX - w2EndX) * 0.65
          const w3cp2y = w3Y + (w2Y382 - w3Y) * 0.05
          const w3Curve = `M${w2EndX},${w2Y382} C${w3cp1x},${w3cp1y} ${w3cp2x},${w3cp2y} ${w3EndX},${w3Y}`

          // Glow
          add(g, 'path', { d: w3Curve, fill: 'none', stroke: '#60a5fa', strokeWidth: '3', opacity: '0.03' })
          // Main path (fainter — more uncertain)
          const w3Path = add(g, 'path', {
            d: w3Curve, fill: 'none', stroke: '#60a5fa', strokeWidth: '1.4',
            strokeDasharray: '5 5', opacity: '0.4',
          })
          addAnim(w3Path, 'stroke-dashoffset', '0;-20', '2.5s')

          // Wave 3 end dot
          const w3Dot = add(g, 'circle', { cx: w3EndX, cy: w3Y, r: 3, fill: 'none', stroke: '#60a5fa', strokeWidth: '1', opacity: '0.4' })
          addAnim(w3Dot, 'r', '3;6;3', '3s')
          addAnim(w3Dot, 'opacity', '0.35;0.1;0.35', '3s')

          // Projected label for wave 3 (dashed circle, faintest)
          const w3LabelY = w3GoingUp ? w3Y - 22 : w3Y + 22
          add(g, 'line', {
            x1: w3EndX, y1: w3Y, x2: w3EndX, y2: w3LabelY + (w3GoingUp ? 8 : -8),
            stroke: '#60a5fa', strokeWidth: '0.5', opacity: '0.25',
          })
          add(g, 'circle', {
            cx: w3EndX, cy: w3LabelY, r: 10,
            fill: '#0c1221', stroke: '#60a5fa', strokeWidth: '0.8', strokeDasharray: '3 2',
          })
          add(g, 'text', {
            x: w3EndX, y: w3LabelY + 3.5, textAnchor: 'middle',
            fill: '#60a5fa', fontSize: '10', fontWeight: '800', fontFamily: "'JetBrains Mono',monospace", opacity: '0.5',
          }).textContent = waveSequence.w3Label
        }
      }
    }

    svg.appendChild(g)
  }

  return { render }
}
