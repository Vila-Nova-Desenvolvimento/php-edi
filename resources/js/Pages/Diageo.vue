<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Grafico from '@/Pages/Grafico.vue'; // Importe o componente Grafico.vue

const props = defineProps([
    'clientes',
    'grafico'
]);

</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">EDI Diageo | Clientes</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <h2 class="text-xl font-semibold mb-4 pt-4 text-center">Vendas por cliente</h2>
                    <div class="p-6 text-gray-900">
                        <div class="overflow-x-auto"> <!-- Adicione um contêiner de rolagem horizontal -->
                            <table class="table-auto w-full">
                                <thead>
                                <tr>
                                    <th class="px-6">ID</th>
                                    <th class="px-6">CNPJ</th>
                                    <th class="px-6 text-left">Razão Social</th>
                                    <th class="px-6"></th>
                                    <th class="px-6 text-center">Estado</th>
                                    <th class="px-6 text-right">% Vendas</th>
                                    <th class="px-6 text-right">Detalhes</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr v-for="cliente in clientes" :key="cliente.id">
                                    <td class="px-6"> {{ cliente.id }}</td>
                                    <td class="px-6"> {{ cliente.cnj_formatado }}</td>
                                    <td class="px-6"> {{ cliente.razao_social_do_cliente }}</td>
                                    <td class="px-6"><img :src="'/flags/' + cliente.estado + '.png'" class="w-10"></td>
                                    <td class="px-6 text-center"> {{ cliente.estado }}</td>
                                    <td class="px-6 text-right"> {{ parseFloat(cliente.representatividade.replace(',', '.')) }}</td>
                                    <td class="px-6 text-right">
                                        <a :href="'/cnpj/' + cliente.cnpj_cpf_do_cliente" class="text-indigo-600 hover:text-indigo-900">Detalhes</a>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h2 class="text-xl font-semibold mb-4 text-center">Representatividade por UF</h2>
                        <div class="flex justify-center">
                            <Grafico :grafico="grafico" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>



</template>
