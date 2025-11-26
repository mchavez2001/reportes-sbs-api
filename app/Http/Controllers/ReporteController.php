<?php

namespace App\Http\Controllers;

use App\Models\Insurance_premium;
use App\Models\Hierarchy;
use App\Models\Section;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function resumen(Request $request)
    {
        $category = $request->query('category', '');
        $subCategory = $request->query('subCategory', '');
        $yearRange = explode(',', $request->query('yearRange', now()->year . ',' . now()->year));
        $months = $request->query('months') ? explode(',', $request->query('months')) : [];
        $type = $request->query('type', 'Primas de Seguros Netas');
        $byMonth = filter_var($request->query('byMonth'), FILTER_VALIDATE_BOOLEAN);
        $inUSD = filter_var($request->query('inUSD'), FILTER_VALIDATE_BOOLEAN);

        $startYear = (int) ($yearRange[0] ?? now()->year);
        $endYear = (int) ($yearRange[1] ?? now()->year);

        // Determinar tipo de prima
        $premiumTypeId = null;
        if (stripos($type, 'Neta') !== false) {
            $premiumTypeId = 1;
        } elseif (stripos($type, 'Siniestro') !== false) {
            $premiumTypeId = 2;
        }

        // Construcción de la consulta base
        $query = Insurance_premium::where('id_company', 21)
            ->whereBetween('year', [$startYear, $endYear]);

        if ($premiumTypeId) {
            $query->where('id_premium_type', $premiumTypeId);
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

        $data = $query->select('year', 'month', 'value', 'usd')->get();

        // Moneda
        $colValue = $inUSD ? 'usd' : 'value';

        // Agrupación base
        $groupedYear = $data->groupBy('year')->map(function ($items, $year) use ($colValue) {
            return [
                'year' => (int) $year,
                'value' => round($items->sum($colValue), 2),
                'usd' => round($items->sum('usd'), 2),
            ];
        })->values();

        $groupedMonth = $data->groupBy(['year', 'month'])->map(function ($months, $year) use ($colValue) {
            return collect($months)->map(function ($items, $month) use ($year, $colValue) {
                return [
                    'year' => (int) $year,
                    'month' => (int) $month,
                    'value' => round($items->sum($colValue), 2),
                    'usd' => round($items->sum('usd'), 2),
                ];
            })->values();
        })->flatten(1)->sortBy([['year', 'asc'], ['month', 'asc']])->values();

        // Si byMonth es false → devolver solo por año
        $filteredData = $byMonth ? $groupedMonth : $groupedYear;

        // Siniestralidad
        if (stripos($type, 'Siniestralidad') !== false) {
            $netas = Insurance_premium::where('id_company', 21)
                ->where('id_premium_type', 1)
                ->whereBetween('year', [$startYear, $endYear])
                ->get()
                ->groupBy(['year', 'month']);

            $siniestros = Insurance_premium::where('id_company', 21)
                ->where('id_premium_type', 2)
                ->whereBetween('year', [$startYear, $endYear])
                ->get()
                ->groupBy(['year', 'month']);

            $groupedMonth = collect();

            foreach ($netas as $year => $meses) {
                foreach ($meses as $month => $items) {
                    $valorNetas = $items->sum($colValue);
                    $valorSiniestros = $siniestros[$year][$month]->sum($colValue) ?? 0;
                    $ratio = $valorNetas > 0 ? ($valorSiniestros / $valorNetas) * 100 : 0;
                    $groupedMonth->push([
                        'year' => (int)$year,
                        'month' => (int)$month,
                        'value' => round($ratio, 2),
                        'usd' => 0
                    ]);
                }
            }

            $groupedYear = $groupedMonth->groupBy('year')->map(function ($items, $year) {
                return [
                    'year' => (int)$year,
                    'value' => round($items->avg('value'), 2),
                    'usd' => 0
                ];
            })->values();

            $filteredData = $byMonth ? $groupedMonth : $groupedYear;
        }

        // Calcular indicadores anuales (respetando moneda seleccionada)
        $netPremiums = Insurance_premium::where('id_company', 21)
            ->where('id_premium_type', 1)
            ->whereBetween('year', [$startYear, $endYear])
            ->get();

        $sinisterPremiums = Insurance_premium::where('id_company', 21)
            ->where('id_premium_type', 2)
            ->whereBetween('year', [$startYear, $endYear])
            ->get();

        $yearly_net_premium = $netPremiums->groupBy('year')->map(fn($items, $year) => [
            'year' => (int)$year,
            'value' => round($items->sum($colValue), 2),
            'usd' => round($items->sum('usd'), 2)
        ])->values();

        $yearly_sinister_premium = $sinisterPremiums->groupBy('year')->map(fn($items, $year) => [
            'year' => (int)$year,
            'value' => round($items->sum($colValue), 2),
            'usd' => round($items->sum('usd'), 2)
        ])->values();

        $yearly_percent_premium = $yearly_net_premium->map(function ($netItem) use ($yearly_sinister_premium) {
            $sinisterItem = $yearly_sinister_premium->firstWhere('year', $netItem['year']);
            $percent = ($netItem['value'] > 0 && $sinisterItem)
                ? ($sinisterItem['value'] / $netItem['value']) * 100
                : 0;
            return [
                'year' => $netItem['year'],
                'value' => round($percent, 2)
            ];
        });

        // Categorías jerárquicas
        $hierarchy = Hierarchy::all();
        $sections = Section::all()->keyBy('id');
        $categoriesArr = [];
        foreach ($hierarchy->groupBy('id_parent') as $parentId => $children) {
            $parentName = $sections[$parentId]->name ?? "Sin nombre";
            $categoriesArr[$parentName] = [];
            foreach ($children as $child) {
                $childName = $sections[$child->id_child]->name ?? null;
                if ($childName) { 
                    $categoriesArr[$parentName][] = $childName;
                }
            }
        }

        // Respuesta final
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
