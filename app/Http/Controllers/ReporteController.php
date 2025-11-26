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
        // 1. Recepción de Parámetros
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

        // 2. Detección de condiciones
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

        /**
         * CASO 1: Sub-Categoría Específica (Ej: "Desgravamen")
         * Prioridad Alta: Si el usuario pide un sub-ramo, filtramos SOLO por ese ID.
         * Aplicamos la división /1000 para mantener la consistencia con los montos grandes de la BD.
         */
        if (!empty($subCategory) && $isNetPremium) {
            
            $subSection = Section::where('name', 'like', "%$subCategory%")->first();
            // Si no encuentra la sección, usamos 0 para devolver vacío
            $targetId = $subSection ? $subSection->id : 0; 
            $columnToSum = $inUSD ? 'usd' : 'value';

            $sql = "
                SELECT 
                    CAST(year AS UNSIGNED) AS year,
                    CAST(month AS UNSIGNED) AS month,
                    ROUND(SUM({$columnToSum}) / 1000, 2) AS value, 
                    ROUND(SUM(usd), 2) AS usd
                FROM insurance_premium
                WHERE id_company = 21 
                AND id_premium_type = 1
                AND year BETWEEN ? AND ?
                AND id_section = ?
            ";

            $params = [$startYear, $endYear, $targetId];

            if (!empty($months)) {
                $placeholders = implode(',', array_fill(0, count($months), '?'));
                $sql .= " AND month IN ($placeholders)";
                $params = array_merge($params, $months);
            }

            $sql .= " GROUP BY year, month ORDER BY year ASC, month ASC";
            $filteredData = DB::select($sql, $params);

        } 
        /**
         * CASO 2: Categoría Padre Compleja (Ej: "Seguros de Vida")
         * Si no hay subcategoría, pero es un ramo complejo, sumamos TODOS sus hijos
         * para evitar la duplicación del padre y aplicamos escala /1000.
         */
        elseif ($isComplexCategory && $isNetPremium) {
            
            $columnToSum = $inUSD ? 'usd' : 'value';

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
                AND T1.id_section IN (
                    SELECT T2.id_child FROM hierarchy T2 
                    WHERE T2.id_parent IN (SELECT id FROM section WHERE name LIKE ?)
                )
            ";

            $params = [$startYear, $endYear, "%$category%"];

            if (!empty($months)) {
                $placeholders = implode(',', array_fill(0, count($months), '?'));
                $sql .= " AND T1.month IN ($placeholders)";
                $params = array_merge($params, $months);
            }

            $sql .= " GROUP BY T1.year, T1.month ORDER BY T1.year ASC, T1.month ASC";
            $filteredData = DB::select($sql, $params);

        } 
        /**
         * CASO 3: Lógica Estándar (Siniestralidad u otros casos)
         */
        else {
            
            $query = Insurance_premium::where('id_company', 21)
                ->whereBetween('year', [$startYear, $endYear]);

            if ($isNetPremium) {
                $query->where('id_premium_type', 1);
            } elseif (stripos($type, 'Siniestro') !== false) {
                $query->where('id_premium_type', 2);
            }

            $sectionIds = collect();
            
            if (!empty($subCategory)) {
                $subSection = Section::where('name', 'like', "%$subCategory%")->first();
                if ($subSection) $sectionIds = collect([$subSection->id]);
            } elseif (!empty($category)) {
                $categorySection = Section::where('name', 'like', "%$category%")->first();
                if ($categorySection) {
                    $childIds = Hierarchy::where('id_parent', $categorySection->id)->pluck('id_child');
                    $sectionIds = $childIds->isNotEmpty() ? $childIds : collect([$categorySection->id]);
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
                        $processedData->push([
                            'year' => (int)$year, 'month' => (int)$month,
                            'value' => round($ratio, 2), 'usd' => 0
                        ]);
                    }
                }
                $filteredData = $byMonth 
                    ? $processedData->sortBy([['year', 'asc'], ['month', 'asc']])->values()
                    : $processedData->groupBy('year')->map(fn($items, $y) => ['year' => (int)$y, 'value' => round($items->avg('value'), 2), 'usd' => 0])->values();

            } else {
                $data = $query->select('year', 'month', 'value', 'usd')->get();
                $mapFunc = function ($items, $y, $m = null) use ($colValue) {
                    return ['year' => (int)$y, 'month' => $m ? (int)$m : null, 'value' => round($items->sum($colValue), 2), 'usd' => round($items->sum('usd'), 2)];
                };
                $filteredData = $byMonth 
                    ? $data->groupBy(['year', 'month'])->map(fn($ms, $y) => collect($ms)->map(fn($is, $m) => $mapFunc($is, $y, $m))->values())->flatten(1)->sortBy([['year', 'asc'], ['month', 'asc']])->values()
                    : $data->groupBy('year')->map(fn($is, $y) => $mapFunc($is, $y))->values();
            }
        }

        // 3. Indicadores y Categorías (Sin Cambios)
        // ... (Mismo código de siempre para Cards laterales y menú) ...
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
