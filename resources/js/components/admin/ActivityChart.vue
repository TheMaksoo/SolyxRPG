<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import Chart from 'chart.js/auto';

const props = defineProps({
  type: { type: String, default: 'line' }, // 'line' | 'bar' | 'doughnut'
  labels: { type: Array, required: true },
  data: { type: Array, required: true },
  color: { type: String, default: '#e8482f' },
  height: { type: Number, default: 160 },
});

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
  const colors = isSeries ? props.color : palette(props.data.length);

  chart = new Chart(canvasEl.value, {
    type: props.type,
    data: {
      labels: props.labels,
      datasets: [
        {
          data: props.data,
          borderColor: isSeries ? props.color : undefined,
          backgroundColor: props.type === 'line' ? `${props.color}22` : colors,
          fill: props.type === 'line',
          tension: 0.35,
          pointRadius: 0,
          borderWidth: 2,
          borderRadius: props.type === 'bar' ? 4 : 0,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales:
        props.type === 'doughnut'
          ? {}
          : {
              x: { grid: { display: false }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 10 } } },
              y: { grid: { color: 'rgba(255,255,255,0.06)' }, ticks: { color: 'rgba(255,255,255,0.35)', font: { size: 10 } }, beginAtZero: true },
            },
      cutout: props.type === 'doughnut' ? '65%' : undefined,
    },
  });
}

onMounted(build);
onUnmounted(() => { if (chart) chart.destroy(); });
watch(() => [props.labels, props.data], build, { deep: true });
</script>

<template>
  <div :style="{ height: `${height}px` }">
    <canvas ref="canvasEl"></canvas>
  </div>
</template>
