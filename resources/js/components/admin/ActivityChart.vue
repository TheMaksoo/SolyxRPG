<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import Chart from 'chart.js/auto';

const props = defineProps({
  type: { type: String, default: 'line' }, // 'line' | 'bar' | 'doughnut'
  labels: { type: Array, required: true },
  data: { type: Array, default: null }, // ignored when `datasets` is set
  color: { type: String, default: '#e8482f' },
  height: { type: Number, default: 160 },
  // One entry per data point — when set, the hover tooltip shows this text instead of the raw
  // label/value (e.g. the actual unlock names at a level, not just "Lv.20: 3"). Each entry can be a
  // string or an array of strings (Chart.js renders one tooltip line per array item).
  tooltipLabels: { type: Array, default: null },
  // Multi-series bar/line — [{ label, data, color, tooltipLabels? }]. When set, overrides `data`/`color`
  // and renders one dataset per entry (e.g. one color per unlock type) instead of a single series.
  datasets: { type: Array, default: null },
  // Stacks multi-series bars into one bar per label instead of side-by-side clusters.
  stacked: { type: Boolean, default: false },
  // Logarithmic y-axis — for series that span multiple orders of magnitude (e.g. an XP curve with
  // hard walls) where a linear scale would flatten everything except the very top of the range.
  logScale: { type: Boolean, default: false },
});

function compactNumber(n) {
  if (n >= 1e9) return (n / 1e9).toFixed(n % 1e9 === 0 ? 0 : 1) + 'B';
  if (n >= 1e6) return (n / 1e6).toFixed(n % 1e6 === 0 ? 0 : 1) + 'M';
  if (n >= 1e3) return (n / 1e3).toFixed(n % 1e3 === 0 ? 0 : 1) + 'K';
  return String(n);
}

const canvasEl = ref(null);
let chart = null;

function palette(n) {
  const base = ['#e8482f', '#5cc7f5', '#4ade80', '#eab308', '#a78bfa', '#f472b6'];
  return Array.from({ length: n }, (_, i) => base[i % base.length]);
}

function build() {
  if (chart) chart.destroy();
  if (!canvasEl.value) return;

  const isSeries = props.type === 'line' || props.type === 'bar';
  const isMulti = !!props.datasets;
  const colors = !isMulti && !isSeries ? palette(props.data.length) : undefined;

  const datasets = isMulti
    ? props.datasets.map((d) => ({
        label: d.label,
        data: d.data,
        borderColor: d.color,
        backgroundColor: props.type === 'line' ? `${d.color}22` : d.color,
        fill: d.fill ?? (props.type === 'line'),
        tension: 0.35,
        pointRadius: d.pointRadius ?? 0,
        showLine: d.showLine ?? true,
        borderWidth: props.type === 'line' ? 2 : 0,
        borderRadius: props.type === 'bar' ? 4 : 0,
        tooltipLabels: d.tooltipLabels ?? null,
      }))
    : [
        {
          data: props.data,
          borderColor: isSeries ? props.color : undefined,
          backgroundColor: props.type === 'line' ? `${props.color}22` : colors ?? props.color,
          fill: props.type === 'line',
          tension: 0.35,
          pointRadius: 0,
          borderWidth: 2,
          borderRadius: props.type === 'bar' ? 4 : 0,
        },
      ];

  chart = new Chart(canvasEl.value, {
    type: props.type,
    data: { labels: props.labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: isMulti, labels: { color: 'rgba(255,255,255,0.55)', font: { size: 10.5 }, boxWidth: 10 } },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const ownTooltips = ctx.dataset.tooltipLabels;
              if (ownTooltips) return ownTooltips[ctx.dataIndex] ?? `${ctx.dataset.label}: ${ctx.formattedValue}`;
              if (props.tooltipLabels) return props.tooltipLabels[ctx.dataIndex] ?? ctx.formattedValue;
              return isMulti ? `${ctx.dataset.label}: ${ctx.formattedValue}` : ctx.formattedValue;
            },
          },
        },
      },
      scales:
        props.type === 'doughnut'
          ? {}
          : {
              x: { stacked: props.stacked, grid: { display: false }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 10 } } },
              y: props.logScale
                ? { type: 'logarithmic', grid: { color: 'rgba(255,255,255,0.06)' }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 10 }, callback: compactNumber } }
                : { stacked: props.stacked, grid: { color: 'rgba(255,255,255,0.06)' }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 10 } }, beginAtZero: true },
            },
      cutout: props.type === 'doughnut' ? '65%' : undefined,
    },
  });
}

onMounted(build);
onUnmounted(() => { if (chart) chart.destroy(); });
watch(() => [props.labels, props.data, props.tooltipLabels, props.datasets, props.stacked, props.logScale], build, { deep: true });
</script>

<template>
  <div :style="{ height: `${height}px` }">
    <canvas ref="canvasEl"></canvas>
  </div>
</template>
