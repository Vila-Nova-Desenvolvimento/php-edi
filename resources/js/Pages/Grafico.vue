<template>

    <Doughnut :data="graficoData" :options="options"/>

</template>

<script>
import {Chart as ChartJS, ArcElement, Tooltip, Legend} from 'chart.js';
import {Doughnut} from 'vue-chartjs';

ChartJS.register(ArcElement, Tooltip, Legend);

export default {
    name: 'App',
    components: {
        Doughnut
    },
    props: {
        grafico: Array
    },
    computed: {
        graficoData() {
            // Construir dados para o gráfico a partir dos dados fornecidos pela controller
            const labels = this.grafico.map(item => item.estado);
            const data = this.grafico.map(item => item.representatividade);

            return {
                labels: labels,
                datasets: [{
                    backgroundColor: ['#41B883', '#E46651', '#00D8FF', '#DD1B16'], // Cores personalizadas
                    data: data
                }]
            };
        },
        options() {
            return {
                responsive: true,
                maintainAspectRatio: false
            };
        }
    }
};
</script>
