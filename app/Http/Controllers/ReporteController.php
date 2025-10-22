<?php

namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\Section;
use App\Models\Insurance_premiun_type;

class ReporteController extends Controller
{
    public function getCompanys()
    {
        $companys = Company::orderBy('id')->get();
        return response()->json($companys);
    }
    public function getSections()
    {
        $sections = Section::orderBy('id')->get();
        return response()->json($sections);
    }
    public function getInsurancePremiumsType()
    {
        $insurancePremiumsType = Insurance_premiun_type::orderBy('id')->get();
        return response()->json($insurancePremiumsType);
    }
}
