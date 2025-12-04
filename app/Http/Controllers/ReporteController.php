<?php

namespace App\Http\Controllers;

use App\Models\Insurance_premium;
use App\Models\Hierarchy;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    public function resumen(Request $request)
    {
        $category = trim($request->query('category', ''));
        $subCategory = trim($request->query('subCategory', ''));
        $yearRange = explode(',', $request->query('yearRange', now()->year . ',' . now()->year));
        $months = $request->query('months') ? explode(',', $request->query('months')) : [];
        $type = trim($request->query('type', 'Primas de Seguros Netas'));
        
        $byMonth = filter_var($request->query('byMonth'), FILTER_VALIDATE_BOOLEAN);
        $inUSD = filter_var($request->query('inUSD'), FILTER_VALIDATE_BOOLEAN);

        $startYear = (int) ($yearRange[0] ?? now()->year);
        $endYear = (int) ($yearRange[1] ?? now()->year);
        $colValue = $inUSD ? 'usd' : 'value';

        $filteredData = [];

        $isNetPremium = stripos($type, 'Neta') !== false;
        
        $complexCategories = [
            'Seguros de Vida',
            'Seguros del Sistema Privado de Pensiones',
            'Generales',
            'Accidentes y Enfermedades'
        ];

        $isComplexCategory = false;
        foreach ($complexCategories as $complex) {
            if (stripos($category, $complex) !== false) {
                $isComplexCategory = true;
                break;
            }
        }

        if ($isNetPremium && (!empty($subCategory) || $isComplexCategory)) {
            
            $columnToSum = $inUSD ? 'usd' : 'value';
            $params = [];
            $sqlWhere = "";

            if (!empty($subCategory)) {
                $subSection = Section::where('name', 'like', "%$subCategory%")->first();
                $targetId = $subSection ? $subSection->id : 0;
                
                $sqlWhere = "AND T1.id_section = ?";
                $params = [$startYear, $endYear, $targetId];
            } else {
                $sqlWhere = "AND T1.id_section IN (
                    SELECT T2.id_child FROM hierarchy T2 
                    WHERE T2.id_parent IN (SELECT id FROM section WHERE name LIKE ?)
                )";
                $params = [$startYear, $endYear, "%$category%"];
            }

            if ($byMonth) {
                $sql = "
                    SELECT 
                        CAST(T1.year AS UNSIGNED) AS year,
                        CAST(T1.month AS UNSIGNED) AS month,
                        ROUND(SUM(T1.{$columnToSum}) / 1000, 2) AS value, 
                        ROUND(SUM(T1.usd), 2) AS usd
                    FROM insurance_premium T1
                    WHERE T1.id_company = 21 
                    AND T1.id_premium_type = 1
                    AND T1.year BETWEEN ? AND ?
                    $sqlWhere
                ";

                if (!empty($months)) {
                    $placeholders = implode(',', array_fill(0, count($months), '?'));
                    $sql .= " AND T1.month IN ($placeholders)";
                    $params = array_merge($params, $months);
                }

                $sql .= " GROUP BY T1.year, T1.month ORDER BY T1.year ASC, T1.month ASC";
            } else {
                $sql = "
                    SELECT 
                        CAST(T1.year AS UNSIGNED) AS year,
                        ROUND(SUM(T1.{$columnToSum}) / 1000, 2) AS value, 
                        ROUND(SUM(T1.usd), 2) AS usd
                    FROM insurance_premium T1
                    WHERE T1.id_company = 21 
                    AND T1.id_premium_type = 1
                    AND T1.year BETWEEN ? AND ?
                    $sqlWhere
                ";

                if (!empty($months)) {
                    $placeholders = implode(',', array_fill(0, count($months), '?'));
                    $sql .= " AND T1.month IN ($placeholders)";
                    $params = array_merge($params, $months);
                }

                $sql .= " GROUP BY T1.year ORDER BY T1.year ASC";
            }
            
            $filteredData = DB::select($sql, $params);

        } else {
            $query = Insurance_premium::where('id_company', 21)
                ->whereBetween('year', [$startYear, $endYear]);

            if (stripos($type, 'Siniestro') !== false) {
                $query->where('id_premium_type', 2);
            } else {
                $query->where('id_premium_type', 1);
            }

            $sectionIds = collect();
            if (!empty($category)) {
                $categorySection = Section::where('name', 'like', "%$category%")->first();
                if ($categorySection) {
                    $childIds = Hierarchy::where('id_parent', $categorySection->id)->pluck('id_child');
                    $sectionIds->push($categorySection->id);
                    $sectionIds = $sectionIds->merge($childIds);
                }
            }
            if (!empty($subCategory)) {
                $subSection = Section::where('name', 'like', "%$subCategory%")->first();
                if ($subSection) {
                    $sectionIds = collect([$subSection->id]);
                }
            }

            if ($sectionIds->isNotEmpty()) {
                $query->whereIn('id_section', $sectionIds->unique());
            }
            if (!empty($months)) {
                $query->whereIn('month', $months);
            }

            if (stripos($type, 'Siniestralidad') !== false) {
                $netas = (clone $query)->where('id_premium_type', 1)->get()->groupBy(['year', 'month']);
                $siniestros = (clone $query)->where('id_premium_type', 2)->get()->groupBy(['year', 'month']);
                
                $processedData = collect();
                foreach ($netas as $year => $meses) {
                    foreach ($meses as $month => $items) {
                        $valorNetas = $items->sum($colValue);
                        $valorSiniestros = $siniestros[$year][$month]->sum($colValue) ?? 0;
                        $ratio = $valorNetas > 0 ? ($valorSiniestros / $valorNetas) * 100 : 0;
                        
                        if ($byMonth) {
                            $processedData->push([
                                'year' => (int)$year,
                                'month' => (int)$month,
                                'value' => round($ratio, 2),
                                'usd' => 0
                            ]);
                        } else {
                            $processedData->push([
                                'year' => (int)$year,
                                'value' => round($ratio, 2),
                                'usd' => 0
                            ]);
                        }
                    }
                }
                
                $filteredData = $byMonth 
                    ? $processedData->sortBy([['year', 'asc'], ['month', 'asc']])->values()
                    : $processedData->groupBy('year')->map(fn($items) => [
                        'year' => $items->first()['year'],
                        'value' => round($items->avg('value'), 2),
                        'usd' => 0
                    ])->values();

            } else {
                $data = $query->select('year', 'month', 'value', 'usd')->get();
                
                if ($byMonth) {
                    $filteredData = $data->groupBy(['year', 'month'])->map(function ($monthsData, $year) use ($colValue) {
                        return collect($monthsData)->map(function ($items, $month) use ($colValue, $year) {
                            return [
                                'year' => (int) $year,
                                'month' => (int) $month,
                                'value' => round($items->sum($colValue), 2),
                                'usd' => round($items->sum('usd'), 2)
                            ];
                        })->values();
                    })->flatten(1)->sortBy([['year', 'asc'], ['month', 'asc']])->values();
                } else {
                    $filteredData = $data->groupBy('year')->map(function ($items, $year) use ($colValue) {
                        return [
                            'year' => (int) $year,
                            'value' => round($items->sum($colValue), 2),
                            'usd' => round($items->sum('usd'), 2)
                        ];
                    })->values();
                }
            }
        }

        $netPremiums = Insurance_premium::where('id_company', 21)->where('id_premium_type', 1)->whereBetween('year', [$startYear, $endYear])->get();
        $sinisterPremiums = Insurance_premium::where('id_company', 21)->where('id_premium_type', 2)->whereBetween('year', [$startYear, $endYear])->get();

        $yearly_net_premium = $netPremiums->groupBy('year')->map(fn($items, $year) => [
            'year' => (int)$year, 'value' => round($items->sum($colValue), 2), 'usd' => round($items->sum('usd'), 2)
        ])->values();

        $yearly_sinister_premium = $sinisterPremiums->groupBy('year')->map(fn($items, $year) => [
            'year' => (int)$year, 'value' => round($items->sum($colValue), 2), 'usd' => round($items->sum('usd'), 2)
        ])->values();

        $yearly_percent_premium = $yearly_net_premium->map(function ($netItem) use ($yearly_sinister_premium) {
            $sinisterItem = $yearly_sinister_premium->firstWhere('year', $netItem['year']);
            $percent = ($netItem['value'] > 0 && $sinisterItem) ? ($sinisterItem['value'] / $netItem['value']) * 100 : 0;
            return ['year' => $netItem['year'], 'value' => round($percent, 2)];
        });

        $hierarchy = Hierarchy::all();
        $sections = Section::all()->keyBy('id');
        $categoriesArr = [];
        foreach ($hierarchy->groupBy('id_parent') as $parentId => $children) {
            $parentName = $sections[$parentId]->name ?? "Sin nombre";
            $categoriesArr[$parentName] = [];
            foreach ($children as $child) {
                $childName = $sections[$child->id_child]->name ?? null;
                if ($childName) $categoriesArr[$parentName][] = $childName;
            }
        }

        return response()->json([
            'GlosaryTerms' => [
                ['name' => 'Primas de Seguros Netas', 'desc' => 'Primas de seguros, deducidas de anulaciones.'],
                ['name' => 'Siniestros de Primas de Seguros Netos', 'desc' => 'Siniestros de primas de seguros, deducidos de anulaciones.'],
                ['name' => 'Siniestralidad', 'desc' => 'Siniestros de primas de seguros netos/primas de seguros netas.']
            ],
            'yearly_net_premium' => $yearly_net_premium,
            'yearly_sinister_premium' => $yearly_sinister_premium,
            'yearly_percent_premium' => $yearly_percent_premium,
            'categories' => $categoriesArr,
            'filtered_data' => $filteredData,
            'last_year' => now()->year,
            'last_month' => now()->month,
        ]);
    }
}