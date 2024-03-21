<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps(['vendas','total']);

</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Diageo | Arquivo de Vendas</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h5>Total: {{ total }}</h5>
                    </div>
                    <div class="p-6 text-gray-900">
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto">
                                <thead>
                                <tr>
                                    <th class="px-4 py-2">Documento</th>
                                    <th class="px-4 py-2">Data</th>
                                    <th class="px-4 py-2 text-center">Tipo</th>
                                    <th class="px-4 py-2 text-right">Total</th>
                                    <th class="px-4 py-2 text-right">Detalhes</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr v-for="venda in vendas" :key="venda.id">
                                    <td class="px-4 py-2">{{ venda.numero_documento }}</td>
                                    <td class="px-4 py-2">{{ venda.data_transacao }}</td>
                                    <td class="px-4 py-2 text-center">{{ venda.tipo_do_registro }}</td>
                                    <td class="px-4 py-2 text-right">R$ {{ venda.total }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <a :href="'/venda/' + venda.numero_documento" class="text-indigo-600 hover:text-indigo-900">Detalhes</a>
                                    </td>
                                </tr>
                                </tbody>
                            </table>

                            <div class="text-sm text-gray-500 mt-2 text-center">
                                <p class="text-indigo-600 inline-block">D</p> = Devolução &nbsp;&nbsp;&nbsp; <p class="text-indigo-600 inline-block">V</p> = Venda
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
