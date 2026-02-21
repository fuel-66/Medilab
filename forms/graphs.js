// graphs.js - simple helper to fetch stats and render a line chart using Chart.js
async function renderVaccinationsChart(canvasId) {
  const resp = await fetch('stats_api.php');
  const json = await resp.json();
  const data = json.data || [];
  const labels = data.map(r => r.day);
  const values = data.map(r => parseInt(r.vaccinations));

  const ctx = document.getElementById(canvasId).getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Vaccinations',
        data: values,
        fill: true,
        tension: 0.3,
        backgroundColor: 'rgba(124,58,237,0.12)',
        borderColor: 'rgba(124,58,237,1)',
        pointRadius: 3
      }]
    },
    options: {
      responsive: true,
      scales: {
        x: { display: true },
        y: { display: true, beginAtZero: true }
      }
    }
  });
}
