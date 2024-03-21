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
                        <h5>Total: {{total}}</h5>
                    </div>
                    <div class="p-6 text-gray-900">
                        <table class="table-auto">
                            <thead>
                            <tr>
                                <th>Foto</th>
                                <th>Código</th>
                                <th>Quantidade</th>
                                <th>Preço de Venda</th>
                                <th>Subtotal</th>
                                <th>CEP</th>
                                <th>Tipo</th>
                            </tr>
                            </thead>
                            <tbody>


                            <tr v-for="venda in vendas" :key="venda.id">
                                <td class="px-6"><img :src="'http://www.eanpictures.com.br:9000/api/gtin/' + venda.codigo_do_produto" class="w-7"></td>
                                <td class="px-6"> {{ venda.codigo_do_produto }}</td>
                                <td class="px-6"> {{ venda.quantidade }}</td>
                                <td class="px-6">R$ {{ venda.preco_de_venda }}</td>
                                <td class="px-6">R$ {{ venda.subtotal }}</td>
                                <td class="px-6"> {{ venda.cep }}</td>
                                <td class="px-6"> {{ venda.tipo_documento }}</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>



                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
