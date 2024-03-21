<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Clientes extends Model
{
    use HasFactory;

    public function formatarCnpjCpfDoClienteAttribute($value)
    {
        $cnpjCpf = $value;

        if (strlen($cnpjCpf) == 11) {
            $cnpjCpf = substr($cnpjCpf, 0, 3) . '.' . substr($cnpjCpf, 3, 3) . '.' . substr($cnpjCpf, 6, 3) . '-' . substr($cnpjCpf, 9, 2);
        } else {
            $cnpjCpf = substr($cnpjCpf, 0, 2) . '.' . substr($cnpjCpf, 2, 3) . '.' . substr($cnpjCpf, 5, 3) . '/' . substr($cnpjCpf, 8, 4) . '-' . substr($cnpjCpf, 12, 2);
        }

        return $cnpjCpf;
    }

}
