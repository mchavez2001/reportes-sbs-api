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
        'year',
        'value',
        'usd',
        'id_company',
        'id_type',
        'id_section',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'id_company');
    }

    public function type()
    {
        return $this->belongsTo(Insurance_premiun_type::class, 'id_type');
    }

    public function section()
    {
        return $this->belongsTo(Section::class, 'id_section');
    }
}
