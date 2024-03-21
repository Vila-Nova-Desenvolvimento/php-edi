<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientesAlvo extends Model
{

    protected $connection = 'sqlite';
    protected $table = 'clientes_alvo';

    use HasFactory;

}
