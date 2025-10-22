<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Insurance_premium extends Model
{
    protected $table = 'insurance_premium';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'name'
    ];
}
