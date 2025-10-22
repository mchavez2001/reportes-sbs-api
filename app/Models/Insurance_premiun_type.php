<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Insurance_premiun_type extends Model
{
    protected $table = 'insurance_premiun_type';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'name'
    ];
}
