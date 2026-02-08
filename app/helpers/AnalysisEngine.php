<?php

class AnalysisEngine
{
    public static function run(array $table, ?string $userRequest = null): array
    {
        $headers = $table['headers'] ?? [];
        $rows = $table['rows'] ?? [];

        $cleanHeaders = [];
        foreach ($headers as $idx => $h) {
            $h = trim((string)$h);
            $cleanHeaders[$idx] = $h !== '' ? $h : 'col_' . ($idx + 1);
        }

        $typed = self::inferColumnTypes($cleanHeaders, $rows);
        $profile = self::buildDatasetProfile($cleanHeaders, $rows, $typed);
        $context = self::inferContext($cleanHeaders, $typed, $profile);
        $analytics = self::buildAnalytics($cleanHeaders, $rows, $typed, $context);
        $charts = self::buildCharts($cleanHeaders, $rows, $typed, $context, $analytics);
        $report = self::buildReport($profile, $context, $analytics, $charts);

        return [
            'dataset_profile' => $profile,
            'inferred_context' => $context,
            'analytics' => $analytics,
            'charts' => $charts,
            'report_text' => $report,
        ];
    }

    private static function inferColumnTypes(array $headers, array $rows): array
    {
        $types = [];
        $sampleN = min(200, count($rows));

        foreach ($headers as $i => $name) {
            $n = 0;
            $numOk = 0;
            $dateOk = 0;
            $nonEmpty = 0;

            for ($r = 0; $r < $sampleN; $r++) {
                $val = isset($rows[$r][$i]) ? trim((string)$rows[$r][$i]) : '';
                $n++;
                if ($val === '') {
                    continue;
                }
                $nonEmpty++;
                if (self::parseNumber($val) !== null) {
                    $numOk++;
                }
                if (self::parseDate($val) !== null) {
                    $dateOk++;
                }
            }

            $nameLower = mb_strtolower((string)$name);
            $isDateByName = (bool)preg_match('/\b(date|data|dt|dia|mes|m[êe]s|ano|timestamp|created_at|updated_at)\b/u', $nameLower);
            $isIdByName = (bool)preg_match('/\b(id|codigo|c[oó]digo|cpf|cnpj)\b/u', $nameLower);

            if ($nonEmpty > 0 && ($dateOk / $nonEmpty) >= 0.85) {
                $types[$i] = 'temporal';
            } elseif ($isDateByName && $nonEmpty > 0 && ($dateOk / $nonEmpty) >= 0.50) {
                $types[$i] = 'temporal';
            } elseif ($nonEmpty > 0 && ($numOk / $nonEmpty) >= 0.85 && !$isIdByName) {
                $types[$i] = 'numerica';
            } else {
                $types[$i] = 'categorica';
            }
        }

        return $types;
    }

    private static function buildDatasetProfile(array $headers, array $rows, array $types): array
    {
        $colList = [];
        foreach ($headers as $i => $h) {
            $colList[] = [
                'name' => $h,
                'type' => $types[$i] ?? 'categorica',
            ];
        }

        $period = null;
        $timeCols = [];
        foreach ($types as $i => $t) {
            if ($t === 'temporal') {
                $timeCols[] = $i;
            }
        }

        if (!empty($timeCols)) {
            $min = null;
            $max = null;
            foreach ($rows as $row) {
                foreach ($timeCols as $i) {
                    $d = isset($row[$i]) ? self::parseDate((string)$row[$i]) : null;
                    if ($d === null) {
                        continue;
                    }
                    if ($min === null || $d < $min) {
                        $min = $d;
                    }
                    if ($max === null || $d > $max) {
                        $max = $d;
                    }
                }
            }
            if ($min !== null && $max !== null) {
                $period = [
                    'start' => $min->format('Y-m-d'),
                    'end' => $max->format('Y-m-d'),
                ];
            }
        }

        $sampleRows = array_slice($rows, 0, min(200, count($rows)));

        return [
            'columns' => $colList,
            'volume' => [
                'rows' => count($rows),
                'columns' => count($headers),
            ],
            'period' => $period,
            'sample_rows' => $sampleRows,
        ];
    }

    private static function inferContext(array $headers, array $types, array $profile): array
    {
        $domain = 'Operacional';
        $h = mb_strtolower(implode(' ', $headers));

        $score = [
            'Financeiro' => 0,
            'Vendas' => 0,
            'Estoque' => 0,
            'RH / Competências' => 0,
            'Operacional' => 0,
            'Performance individual' => 0,
            'Log temporal' => 0,
        ];

        $rules = [
            'Financeiro' => ['receita','fatur','revenue','lucro','profit','custo','cost','despesa','expense','margem','saldo','balan','caixa','pagamento','payment','valor','amount','r$','brl','usd'],
            'Vendas' => ['venda','sales','pedido','order','cliente','customer','produto','product','categoria','category','quantidade','qty','ticket'],
            'Estoque' => ['estoque','stock','inventory','sku','armaz','warehouse','entrada','saida','moviment'],
            'RH / Competências' => ['rh','people','funcion','employee','colaborador','cargo','role','salario','salary','compet','skill','avaliacao','score'],
            'Performance individual' => ['performance','meta','goal','kpi','resultado','ranking','produtividade'],
            'Log temporal' => ['log','evento','event','timestamp','created_at','updated_at','data','date','hora','time'],
        ];

        foreach ($rules as $dom => $keys) {
            foreach ($keys as $k) {
                if (mb_strpos($h, $k) !== false) {
                    $score[$dom] += 1;
                }
            }
        }

        $best = 'Operacional';
        $bestScore = -1;
        foreach ($score as $dom => $sc) {
            if ($sc > $bestScore) {
                $best = $dom;
                $bestScore = $sc;
            }
        }
        $domain = $bestScore > 0 ? $best : 'Operacional';

        // Seleção data-driven do eixo temporal: coluna temporal com maior amplitude (quando possível)
        $timeCol = null;
        $bestSpanDays = -1;
        foreach ($types as $i => $t) {
            if ($t !== 'temporal') {
                continue;
            }
            $min = null;
            $max = null;
            $count = 0;
            foreach (($profile['sample_rows'] ?? []) as $row) {
                $d = isset($row[$i]) ? self::parseDate((string)$row[$i]) : null;
                if ($d === null) {
                    continue;
                }
                $count++;
                if ($min === null || $d < $min) {
                    $min = $d;
                }
                if ($max === null || $d > $max) {
                    $max = $d;
                }
            }
            if ($min !== null && $max !== null && $count >= 3) {
                $span = (int)round(($max->getTimestamp() - $min->getTimestamp()) / 86400);
                if ($span > $bestSpanDays) {
                    $bestSpanDays = $span;
                    $timeCol = $headers[$i] ?? null;
                }
            } elseif ($timeCol === null) {
                $timeCol = $headers[$i] ?? null;
            }
        }

        // Seleção da métrica principal: numérica com maior variabilidade relativa (std/|mean|)
        $metricCol = null;
        $bestMetricScore = -1.0;
        foreach ($types as $i => $t) {
            if ($t !== 'numerica') {
                continue;
            }
            $vals = [];
            foreach (($profile['sample_rows'] ?? []) as $row) {
                $v = isset($row[$i]) ? self::parseNumber((string)$row[$i]) : null;
                if ($v === null) {
                    continue;
                }
                $vals[] = $v;
            }
            if (count($vals) < 5) {
                continue;
            }
            $st = self::numericSummary($vals);
            $mean = (float)($st['mean'] ?? 0);
            $std = (float)($st['stddev'] ?? 0);
            $score = $mean != 0.0 ? abs($std / $mean) : (float)$std;
            if ($score > $bestMetricScore) {
                $bestMetricScore = $score;
                $metricCol = $headers[$i] ?? null;
            }
        }
        if ($metricCol === null) {
            foreach ($types as $i => $t) {
                if ($t === 'numerica') {
                    $metricCol = $headers[$i] ?? null;
                    break;
                }
            }
        }

        // Seleção da entidade: categórica com cardinalidade "útil" (nem baixa demais, nem quase única)
        $entityCol = null;
        $bestEntityScore = -1.0;
        $sampleSize = max(1, count($profile['sample_rows'] ?? []));
        foreach ($types as $i => $t) {
            if ($t !== 'categorica') {
                continue;
            }
            $set = [];
            $nonEmpty = 0;
            foreach (($profile['sample_rows'] ?? []) as $row) {
                $v = isset($row[$i]) ? trim((string)$row[$i]) : '';
                if ($v === '') {
                    continue;
                }
                $nonEmpty++;
                $set[$v] = true;
            }
            if ($nonEmpty < 5) {
                continue;
            }
            $distinct = count($set);
            $ratio = $sampleSize > 0 ? ($distinct / $sampleSize) : 1.0;
            // score favorece ratio intermediário (ex.: 0.02 a 0.40)
            $score = 0.0;
            if ($ratio >= 0.02 && $ratio <= 0.40) {
                $score = 1.0 - abs($ratio - 0.15);
            }
            if ($score > $bestEntityScore) {
                $bestEntityScore = $score;
                $entityCol = $headers[$i] ?? null;
            }
        }
        if ($entityCol === null) {
            foreach ($types as $i => $t) {
                if ($t === 'categorica') {
                    $entityCol = $headers[$i] ?? null;
                    break;
                }
            }
        }

        return [
            'domain' => $domain,
            'main_entity' => $entityCol,
            'main_metric' => $metricCol,
            'time_axis' => $timeCol,
        ];
    }

    private static function buildAnalytics(array $headers, array $rows, array $types, array $context): array
    {
        $numericStats = [];
        $categoricalStats = [];
        $temporalStats = [];
        $comparisons = [];

        foreach ($types as $i => $t) {
            $name = $headers[$i] ?? ('col_' . ($i + 1));
            if ($t === 'numerica') {
                $vals = [];
                foreach ($rows as $row) {
                    $v = isset($row[$i]) ? self::parseNumber((string)$row[$i]) : null;
                    if ($v === null) {
                        continue;
                    }
                    $vals[] = $v;
                }
                $numericStats[$name] = self::numericSummary($vals);
            } elseif ($t === 'categorica') {
                $freq = [];
                $n = 0;
                foreach ($rows as $row) {
                    $v = isset($row[$i]) ? trim((string)$row[$i]) : '';
                    if ($v === '') {
                        continue;
                    }
                    $n++;
                    if (!isset($freq[$v])) {
                        $freq[$v] = 0;
                    }
                    $freq[$v] += 1;
                }
                arsort($freq);
                $top = array_slice($freq, 0, 20, true);
                $topShare = null;
                $top3Share = null;
                if ($n > 0 && !empty($freq)) {
                    $vals = array_values($freq);
                    $topShare = $vals[0] / $n;
                    $top3 = array_slice($vals, 0, 3);
                    $top3Share = array_sum($top3) / $n;
                }
                $categoricalStats[$name] = [
                    'distinct' => count($freq),
                    'non_empty' => $n,
                    'top' => $top,
                    'concentration' => [
                        'top1_share' => $topShare,
                        'top3_share' => $top3Share,
                    ],
                ];
            }
        }

        $timeAxis = $context['time_axis'] ?? null;
        $metric = $context['main_metric'] ?? null;
        $entity = $context['main_entity'] ?? null;

        // Comparação entidade x métrica (soma) + participação percentual
        if ($entity && $metric) {
            $entityIdx = array_search($entity, $headers, true);
            $metricIdx = array_search($metric, $headers, true);
            if ($entityIdx !== false && $metricIdx !== false) {
                $agg = [];
                foreach ($rows as $row) {
                    $k = isset($row[$entityIdx]) ? trim((string)$row[$entityIdx]) : '';
                    $v = isset($row[$metricIdx]) ? self::parseNumber((string)$row[$metricIdx]) : null;
                    if ($k === '' || $v === null) {
                        continue;
                    }
                    if (!isset($agg[$k])) {
                        $agg[$k] = 0.0;
                    }
                    $agg[$k] += (float)$v;
                }
                arsort($agg);
                $topAgg = array_slice($agg, 0, 20, true);
                $total = array_sum($agg);
                $shares = [];
                if ($total > 0) {
                    foreach ($topAgg as $k => $v) {
                        $shares[$k] = $v / $total;
                    }
                }

                $comparisons['entity_metric_sum'] = [
                    'entity' => $entity,
                    'metric' => $metric,
                    'top_sum' => $topAgg,
                    'total_sum' => $total,
                    'top_shares' => $shares,
                ];
            }
        }
        if ($timeAxis && $metric) {
            $timeIdx = array_search($timeAxis, $headers, true);
            $metricIdx = array_search($metric, $headers, true);
            if ($timeIdx !== false && $metricIdx !== false) {
                $series = [];
                $seriesMonthly = [];
                foreach ($rows as $row) {
                    $d = isset($row[$timeIdx]) ? self::parseDate((string)$row[$timeIdx]) : null;
                    $v = isset($row[$metricIdx]) ? self::parseNumber((string)$row[$metricIdx]) : null;
                    if ($d === null || $v === null) {
                        continue;
                    }
                    $key = $d->format('Y-m-d');
                    if (!isset($series[$key])) {
                        $series[$key] = 0.0;
                    }
                    $series[$key] += $v;

                    $mKey = $d->format('Y-m');
                    if (!isset($seriesMonthly[$mKey])) {
                        $seriesMonthly[$mKey] = 0.0;
                    }
                    $seriesMonthly[$mKey] += $v;
                }
                ksort($series);
                ksort($seriesMonthly);

                $temporalStats = self::temporalSummary($series);
                $temporalStats['series_monthly'] = $seriesMonthly;
            }
        }

        return [
            'numeric' => $numericStats,
            'categorical' => $categoricalStats,
            'temporal' => $temporalStats,
            'comparisons' => $comparisons,
        ];
    }

    private static function buildCharts(array $headers, array $rows, array $types, array $context, array $analytics): array
    {
        $charts = [];

        foreach ($analytics['numeric'] as $col => $stats) {
            if (($stats['count'] ?? 0) <= 0) {
                continue;
            }
            if (!empty($stats['histogram']['bins']) && !empty($stats['histogram']['counts'])) {
                $charts[] = [
                    'chart_type' => 'bar',
                    'title' => 'Distribuição: ' . $col,
                    'description' => 'Histograma baseado em bins.',
                    'labels' => $stats['histogram']['bins'],
                    'values' => $stats['histogram']['counts'],
                ];
            }

            // Boxplot best-effort (Chart.js padrão não tem boxplot nativo sem plugin)
            if (isset($stats['min'], $stats['q1'], $stats['median'], $stats['q3'], $stats['max']) && ($stats['count'] ?? 0) >= 5) {
                $iqr = (float)$stats['q3'] - (float)$stats['q1'];
                if ($iqr <= 0) {
                    continue;
                }
                $charts[] = [
                    'chart_type' => 'boxplot',
                    'title' => 'Boxplot (5 números): ' . $col,
                    'description' => 'Resumo de 5 números (min, Q1, mediana, Q3, max) para variabilidade e consistência.',
                    'labels' => ['min', 'q1', 'mediana', 'q3', 'max'],
                    'values' => [
                        (float)$stats['min'],
                        (float)$stats['q1'],
                        (float)$stats['median'],
                        (float)$stats['q3'],
                        (float)$stats['max'],
                    ],
                ];
            }
        }

        foreach ($analytics['categorical'] as $col => $info) {
            $top = $info['top'] ?? [];
            if (empty($top)) {
                continue;
            }

            // padroniza top N
            $distinct = (int)($info['distinct'] ?? count($top));
            $topN = $distinct > 50 ? 20 : 10;
            $top = array_slice($top, 0, $topN, true);
            $labels = array_keys($top);
            $values = array_values($top);

            $charts[] = [
                'chart_type' => 'bar',
                'title' => 'Top categorias: ' . $col,
                'description' => 'Ranking por contagem (top 20).',
                'labels' => $labels,
                'values' => $values,
            ];

            // Pizza: apenas quando participação faz sentido (poucas categorias e concentração relevante)
            $total = array_sum($values);
            $top1 = $values[0] ?? 0;
            $top1Share = $total > 0 ? ($top1 / $total) : 0;
            if ($total > 0 && count($labels) <= 10 && $top1Share >= 0.15) {
                $pct = [];
                foreach ($values as $v) {
                    $pct[] = round(($v / $total) * 100, 2);
                }
                $charts[] = [
                    'chart_type' => 'pie',
                    'title' => 'Participação: ' . $col,
                    'description' => 'Participação percentual das categorias (top).',
                    'labels' => $labels,
                    'values' => $pct,
                ];
            }
        }

        // Comparação entidade x métrica (soma)
        if (!empty($analytics['comparisons']['entity_metric_sum']['top_sum'])) {
            $cmp = $analytics['comparisons']['entity_metric_sum'];
            $topAgg = $cmp['top_sum'];
            $topAgg = array_slice($topAgg, 0, 20, true);
            $charts[] = [
                'chart_type' => 'bar',
                'title' => 'Top ' . ($cmp['entity'] ?? 'entidades') . ' por soma de ' . ($cmp['metric'] ?? 'métrica'),
                'description' => 'Ranking por soma da métrica (top 20).',
                'labels' => array_keys($topAgg),
                'values' => array_values($topAgg),
            ];

            // Pizza por participação de soma (apenas se <= 10)
            $shares = $cmp['top_shares'] ?? [];
            $shares = array_slice($shares, 0, 10, true);
            $shareVals = array_values($shares);
            $shareTop1 = $shareVals[0] ?? 0;
            if (!empty($shares) && count($shares) <= 10 && $shareTop1 >= 0.15) {
                $pct = [];
                foreach ($shares as $k => $s) {
                    $pct[] = round((float)$s * 100, 2);
                }
                $charts[] = [
                    'chart_type' => 'pie',
                    'title' => 'Participação (soma): ' . ($cmp['entity'] ?? 'entidade'),
                    'description' => 'Participação percentual por soma da métrica (top).',
                    'labels' => array_keys($shares),
                    'values' => $pct,
                ];
            }
        }

        if (!empty($analytics['temporal']['series'])) {
            $labels = array_keys($analytics['temporal']['series']);
            $values = array_values($analytics['temporal']['series']);
            $charts[] = [
                'chart_type' => 'line',
                'title' => 'Evolução temporal: ' . ($context['main_metric'] ?? 'métrica'),
                'description' => 'Série temporal agregada por dia.',
                'labels' => $labels,
                'values' => $values,
            ];

            if (!empty($analytics['temporal']['series_monthly'])) {
                $mLabels = array_keys($analytics['temporal']['series_monthly']);
                $mValues = array_values($analytics['temporal']['series_monthly']);
                $charts[] = [
                    'chart_type' => 'line',
                    'title' => 'Evolução mensal: ' . ($context['main_metric'] ?? 'métrica'),
                    'description' => 'Série temporal agregada por mês.',
                    'labels' => $mLabels,
                    'values' => $mValues,
                ];
            }

            // variação percentual
            if (count($values) >= 2) {
                $pctLabels = [];
                $pctValues = [];
                for ($i = 1; $i < count($values); $i++) {
                    $pctLabels[] = $labels[$i];
                    $prev = (float)$values[$i - 1];
                    $cur = (float)$values[$i];
                    $pct = $prev != 0.0 ? (($cur - $prev) / $prev) * 100.0 : null;
                    $pctValues[] = $pct === null ? 0.0 : (float)$pct;
                }
                $charts[] = [
                    'chart_type' => 'bar',
                    'title' => 'Variação percentual: ' . ($context['main_metric'] ?? 'métrica'),
                    'description' => 'Variação percentual entre períodos consecutivos (em %).',
                    'labels' => $pctLabels,
                    'values' => $pctValues,
                ];
            }

            if (count($values) >= 2) {
                $deltas = [];
                $deltaLabels = [];
                for ($i = 1; $i < count($values); $i++) {
                    $deltaLabels[] = $labels[$i];
                    $prev = (float)$values[$i - 1];
                    $cur = (float)$values[$i];
                    $deltas[] = $cur - $prev;
                }
                $charts[] = [
                    'chart_type' => 'bar',
                    'title' => 'Variação (delta): ' . ($context['main_metric'] ?? 'métrica'),
                    'description' => 'Diferença absoluta entre períodos consecutivos.',
                    'labels' => $deltaLabels,
                    'values' => $deltas,
                ];
            }

            // forecast simples se disponível
            if (!empty($analytics['temporal']['forecast'])) {
                $f = $analytics['temporal']['forecast'];
                if (!empty($f['labels']) && !empty($f['values']) && count($f['labels']) === count($f['values'])) {
                    $charts[] = [
                        'chart_type' => 'line',
                        'title' => 'Projeção simples: ' . ($context['main_metric'] ?? 'métrica'),
                        'description' => 'Extrapolação linear simples (baseada na série observada).',
                        'labels' => $f['labels'],
                        'values' => $f['values'],
                    ];
                }
            }
        }

        // Radar (perfil multi-métricas): quando há múltiplas colunas numéricas
        $numericCols = array_keys($analytics['numeric'] ?? []);
        if (count($numericCols) >= 3) {
            $radarCols = array_slice($numericCols, 0, 8);
            $labels = [];
            $values = [];
            foreach ($radarCols as $col) {
                $st = $analytics['numeric'][$col] ?? null;
                if (!$st || !isset($st['mean'])) {
                    continue;
                }
                $labels[] = $col;
                // normaliza a média para 0-100 usando min/max observados
                $min = (float)($st['min'] ?? 0);
                $max = (float)($st['max'] ?? 0);
                $mean = (float)$st['mean'];
                $norm = ($max !== $min) ? (($mean - $min) / ($max - $min)) * 100.0 : 50.0;
                $values[] = (float)$norm;
            }
            if (count($labels) >= 3) {
                $charts[] = [
                    'chart_type' => 'radar',
                    'title' => 'Perfil de métricas (radar)',
                    'description' => 'Comparação de múltiplas métricas normalizadas (0-100) com base em min/max observados.',
                    'labels' => $labels,
                    'values' => $values,
                ];
            }
        }

        // Gantt best-effort: detectar colunas de início/fim e gerar duração por entidade
        $startIdx = null;
        $endIdx = null;
        foreach ($headers as $i => $h) {
            $hl = mb_strtolower((string)$h);
            if ($types[$i] === 'temporal' && $startIdx === null && preg_match('/\b(inicio|in[ií]cio|start|data_inicio|dt_inicio)\b/u', $hl)) {
                $startIdx = $i;
            }
            if ($types[$i] === 'temporal' && $endIdx === null && preg_match('/\b(fim|end|data_fim|dt_fim|termino|t[eê]rmino)\b/u', $hl)) {
                $endIdx = $i;
            }
        }
        if ($startIdx !== null && $endIdx !== null) {
            $entity = $context['main_entity'] ?? null;
            $entityIdx = $entity ? array_search($entity, $headers, true) : null;
            if ($entityIdx === false) {
                $entityIdx = null;
            }

            $dur = [];
            foreach ($rows as $row) {
                $ds = isset($row[$startIdx]) ? self::parseDate((string)$row[$startIdx]) : null;
                $de = isset($row[$endIdx]) ? self::parseDate((string)$row[$endIdx]) : null;
                if ($ds === null || $de === null) {
                    continue;
                }
                $label = $entityIdx !== null ? trim((string)($row[$entityIdx] ?? '')) : '';
                if ($label === '') {
                    $label = $ds->format('Y-m-d') . '→' . $de->format('Y-m-d');
                }
                $days = (float)max(0, ($de->getTimestamp() - $ds->getTimestamp()) / 86400);
                if (!isset($dur[$label])) {
                    $dur[$label] = 0.0;
                }
                $dur[$label] += $days;
            }
            arsort($dur);
            $dur = array_slice($dur, 0, 20, true);
            if (!empty($dur)) {
                $charts[] = [
                    'chart_type' => 'gantt',
                    'title' => 'Gantt (duração em dias)',
                    'description' => 'Gráfico tipo Gantt (best-effort) representado como duração total (dias) por item.',
                    'labels' => array_keys($dur),
                    'values' => array_values($dur),
                ];
            }
        }

        return $charts;
    }

    private static function buildReport(array $profile, array $context, array $analytics, array $charts): string
    {
        $lines = [];

        $lines[] = '1. Resumo Executivo';
        $lines[] = 'Linhas: ' . (int)($profile['volume']['rows'] ?? 0) . ' | Colunas: ' . (int)($profile['volume']['columns'] ?? 0);
        if (!empty($profile['period'])) {
            $lines[] = 'Período: ' . ($profile['period']['start'] ?? '') . ' a ' . ($profile['period']['end'] ?? '');
        }
        $lines[] = 'Domínio inferido: ' . ($context['domain'] ?? 'Operacional');
        $lines[] = '';

        $lines[] = '2. Métricas-Chave';
        if (!empty($context['main_metric'])) {
            $m = $context['main_metric'];
            $st = $analytics['numeric'][$m] ?? null;
            if ($st) {
                $lines[] = $m . ' | média=' . self::fmt($st['mean']) . ' mediana=' . self::fmt($st['median']) . ' desvio=' . self::fmt($st['stddev']);
            }
        }
        if (!empty($context['main_entity']) && !empty($analytics['categorical'][$context['main_entity']]['concentration'])) {
            $c = $analytics['categorical'][$context['main_entity']]['concentration'];
            if (isset($c['top1_share']) && $c['top1_share'] !== null) {
                $lines[] = $context['main_entity'] . ' | concentração top1=' . self::fmt((float)$c['top1_share'] * 100) . '% top3=' . self::fmt((float)($c['top3_share'] ?? 0) * 100) . '%';
            }
        }

        if (!empty($analytics['comparisons']['entity_metric_sum']['top_sum'])) {
            $cmp = $analytics['comparisons']['entity_metric_sum'];
            $topAgg = $cmp['top_sum'];
            $firstKey = array_key_first($topAgg);
            if ($firstKey !== null) {
                $lines[] = 'Top ' . ($cmp['entity'] ?? 'entidade') . ' por soma de ' . ($cmp['metric'] ?? 'métrica') . ': ' . $firstKey . ' (' . self::fmt((float)$topAgg[$firstKey]) . ')';
            }
        }
        $lines[] = '';

        $lines[] = '3. Gráficos Gerados (todos aplicáveis)';
        foreach ($charts as $c) {
            $lines[] = '- ' . ($c['chart_type'] ?? '') . ' | ' . ($c['title'] ?? '');
        }
        $lines[] = '';

        $lines[] = '4. Leitura Técnica dos Gráficos';
        foreach ($charts as $c) {
            $lines[] = ($c['title'] ?? '');
            $lines[] = 'O que mostra: ' . ($c['description'] ?? '');
            $lines[] = 'Padrão identificado: ' . self::patternFromChart($c);
            $lines[] = 'Significado técnico: ' . self::meaningFromChart($c);
            $ev = self::evidenceFromChart($c);
            if ($ev !== null) {
                $lines[] = 'Evidência numérica: ' . $ev;
            }
            $lines[] = '';
        }

        $lines[] = '5. Análise Estatística';
        foreach (($analytics['numeric'] ?? []) as $col => $st) {
            $lines[] = $col . ' | n=' . (int)($st['count'] ?? 0) . ' média=' . self::fmt($st['mean']) . ' mediana=' . self::fmt($st['median']) . ' desvio=' . self::fmt($st['stddev']) . ' min=' . self::fmt($st['min']) . ' max=' . self::fmt($st['max']);
        }
        $lines[] = '';

        $lines[] = '6. Prós e Contras';
        $pc = self::prosCons($analytics, $context);
        $lines[] = 'Pontos Fortes:';
        foreach ($pc['pros'] as $p) {
            $lines[] = '- ' . $p;
        }
        $lines[] = 'Pontos Fracos:';
        foreach ($pc['cons'] as $c) {
            $lines[] = '- ' . $c;
        }
        $lines[] = '';

        $lines[] = '7. Tendências e Alertas';
        if (!empty($analytics['temporal']['trend'])) {
            $lines[] = $analytics['temporal']['trend'];
            if (!empty($analytics['temporal']['peak'])) {
                $p = $analytics['temporal']['peak'];
                $lines[] = 'Pico: ' . ($p['date'] ?? '') . ' | valor=' . self::fmt($p['value'] ?? 0);
            }
            if (!empty($analytics['temporal']['trough'])) {
                $t = $analytics['temporal']['trough'];
                $lines[] = 'Queda: ' . ($t['date'] ?? '') . ' | valor=' . self::fmt($t['value'] ?? 0);
            }
            if (!empty($analytics['temporal']['forecast_text'])) {
                $lines[] = $analytics['temporal']['forecast_text'];
            }
        } else {
            $lines[] = 'Sem eixo temporal utilizável para tendência.';
        }
        $lines[] = '';

        $lines[] = '8. Conclusão Técnica Final';
        $lines[] = self::conclusion($profile, $context, $analytics);

        return implode("\n", $lines);
    }

    private static function evidenceFromChart(array $c): ?string
    {
        $type = $c['chart_type'] ?? '';
        $labels = $c['labels'] ?? [];
        $values = $c['values'] ?? [];
        if (!is_array($labels) || !is_array($values) || empty($values)) {
            return null;
        }

        if ($type === 'pie') {
            $pairs = [];
            for ($i = 0; $i < min(count($labels), count($values)); $i++) {
                $pairs[] = ['k' => (string)$labels[$i], 'v' => (float)$values[$i]];
            }
            usort($pairs, fn($a, $b) => $b['v'] <=> $a['v']);
            $top1 = $pairs[0] ?? null;
            $top3 = array_slice($pairs, 0, 3);
            $top3Sum = 0.0;
            foreach ($top3 as $p) {
                $top3Sum += (float)$p['v'];
            }
            if ($top1) {
                return 'top1=' . $top1['k'] . ' (' . self::fmt($top1['v']) . '%) | top3=' . self::fmt($top3Sum) . '%.';
            }
            return null;
        }

        if ($type === 'bar') {
            $maxIdx = 0;
            $maxVal = (float)$values[0];
            for ($i = 1; $i < count($values); $i++) {
                if ((float)$values[$i] > $maxVal) {
                    $maxVal = (float)$values[$i];
                    $maxIdx = $i;
                }
            }
            $label = $labels[$maxIdx] ?? '';
            return 'pico=' . (string)$label . ' (' . self::fmt($maxVal) . ').';
        }

        if ($type === 'line') {
            $first = (float)$values[0];
            $last = (float)$values[count($values) - 1];
            $delta = $last - $first;
            $pct = $first != 0.0 ? ($delta / $first) * 100.0 : null;
            return 'início=' . self::fmt($first) . ' fim=' . self::fmt($last) . ' delta=' . self::fmt($delta) . ( $pct !== null ? (' (' . self::fmt($pct) . '%)') : '' ) . '.';
        }

        if ($type === 'boxplot') {
            if (count($values) >= 5) {
                $min = (float)$values[0];
                $q1 = (float)$values[1];
                $med = (float)$values[2];
                $q3 = (float)$values[3];
                $max = (float)$values[4];
                return 'min=' . self::fmt($min) . ' q1=' . self::fmt($q1) . ' mediana=' . self::fmt($med) . ' q3=' . self::fmt($q3) . ' max=' . self::fmt($max) . '.';
            }
            return null;
        }

        if ($type === 'radar') {
            $max = max($values);
            $min = min($values);
            return 'escala normalizada 0–100 | max=' . self::fmt((float)$max) . ' | min=' . self::fmt((float)$min) . '.';
        }

        if ($type === 'gantt') {
            $max = max($values);
            $min = min($values);
            return 'duração (dias) | max=' . self::fmt((float)$max) . ' | min=' . self::fmt((float)$min) . '.';
        }

        return null;
    }

    private static function numericSummary(array $vals): array
    {
        $n = count($vals);
        if ($n === 0) {
            return ['count' => 0];
        }
        sort($vals);
        $min = $vals[0];
        $max = $vals[$n - 1];
        $sum = array_sum($vals);
        $mean = $sum / $n;
        $median = ($n % 2 === 1) ? $vals[(int)floor($n / 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;

        $var = 0.0;
        foreach ($vals as $v) {
            $var += ($v - $mean) * ($v - $mean);
        }
        $var = $n > 1 ? $var / ($n - 1) : 0.0;
        $std = sqrt($var);

        $q1 = self::percentile($vals, 25);
        $q3 = self::percentile($vals, 75);
        $iqr = $q3 - $q1;
        $low = $q1 - 1.5 * $iqr;
        $high = $q3 + 1.5 * $iqr;
        $outliers = 0;
        foreach ($vals as $v) {
            if ($v < $low || $v > $high) {
                $outliers++;
            }
        }

        $hist = self::histogram($vals);

        return [
            'count' => $n,
            'mean' => $mean,
            'median' => $median,
            'stddev' => $std,
            'min' => $min,
            'max' => $max,
            'q1' => $q1,
            'q3' => $q3,
            'outliers_count' => $outliers,
            'histogram' => $hist,
        ];
    }

    private static function histogram(array $vals): array
    {
        $n = count($vals);
        if ($n < 2) {
            return ['bins' => [], 'counts' => []];
        }
        $min = min($vals);
        $max = max($vals);
        if ($min === $max) {
            return ['bins' => [self::fmt($min)], 'counts' => [$n]];
        }

        $binsCount = (int)max(5, min(12, round(sqrt($n))));
        $step = ($max - $min) / $binsCount;
        if ($step <= 0) {
            return ['bins' => [], 'counts' => []];
        }

        $counts = array_fill(0, $binsCount, 0);
        foreach ($vals as $v) {
            $idx = (int)floor(($v - $min) / $step);
            if ($idx < 0) {
                $idx = 0;
            }
            if ($idx >= $binsCount) {
                $idx = $binsCount - 1;
            }
            $counts[$idx] += 1;
        }

        $bins = [];
        for ($i = 0; $i < $binsCount; $i++) {
            $a = $min + $i * $step;
            $b = $min + ($i + 1) * $step;
            $bins[] = self::fmt($a) . '–' . self::fmt($b);
        }

        return ['bins' => $bins, 'counts' => $counts];
    }

    private static function temporalSummary(array $series): array
    {
        $vals = array_values($series);
        $labels = array_keys($series);
        $n = count($vals);
        $trend = null;
        $forecast = null;
        $forecastText = null;

        if ($n >= 2) {
            $first = (float)$vals[0];
            $last = (float)$vals[$n - 1];
            $delta = $last - $first;
            $direction = $delta > 0 ? 'crescente' : ($delta < 0 ? 'decrescente' : 'estável');
            $pct = null;
            if ($first != 0.0) {
                $pct = ($delta / $first) * 100.0;
            }
            $trend = 'Tendência ' . $direction . ' no período. Variação absoluta=' . self::fmt($delta) . ( $pct !== null ? (' | variação%=' . self::fmt($pct) . '%') : '' );

            // Forecast simples: regressão linear sobre os últimos pontos (até 30)
            $k = min(30, $n);
            $x = [];
            $y = [];
            for ($i = $n - $k; $i < $n; $i++) {
                $x[] = (float)($i - ($n - $k));
                $y[] = (float)$vals[$i];
            }
            $lr = self::linearRegression($x, $y);
            if ($lr) {
                $h = min(7, max(1, (int)round($k / 5)));
                $fLabels = $labels;
                $fValues = $vals;
                for ($j = 1; $j <= $h; $j++) {
                    $nextX = (float)($k - 1 + $j);
                    $pred = $lr['a'] + $lr['b'] * $nextX;
                    $lastDate = DateTime::createFromFormat('Y-m-d', $labels[$n - 1]);
                    if ($lastDate instanceof DateTime) {
                        $lastDate->modify('+' . $j . ' day');
                        $fLabels[] = $lastDate->format('Y-m-d');
                    } else {
                        $fLabels[] = 't+' . $j;
                    }
                    $fValues[] = (float)$pred;
                }
                $forecast = ['labels' => $fLabels, 'values' => $fValues];
                $forecastText = 'Projeção simples (linear): próximos ' . $h . ' períodos estimados. Inclinação=' . self::fmt($lr['b']) . '.';
            }
        }

        $maxVal = null;
        $maxLabel = null;
        $minVal = null;
        $minLabel = null;
        foreach ($series as $k => $v) {
            $v = (float)$v;
            if ($maxVal === null || $v > $maxVal) {
                $maxVal = $v;
                $maxLabel = $k;
            }
            if ($minVal === null || $v < $minVal) {
                $minVal = $v;
                $minLabel = $k;
            }
        }

        return [
            'series' => $series,
            'peak' => $maxVal === null ? null : ['date' => $maxLabel, 'value' => $maxVal],
            'trough' => $minVal === null ? null : ['date' => $minLabel, 'value' => $minVal],
            'trend' => $trend,
            'forecast' => $forecast,
            'forecast_text' => $forecastText,
        ];
    }

    private static function linearRegression(array $x, array $y): ?array
    {
        $n = count($x);
        if ($n < 2 || $n !== count($y)) {
            return null;
        }
        $sx = array_sum($x);
        $sy = array_sum($y);
        $sxx = 0.0;
        $sxy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sxx += $x[$i] * $x[$i];
            $sxy += $x[$i] * $y[$i];
        }
        $den = ($n * $sxx - $sx * $sx);
        if ($den == 0.0) {
            return null;
        }
        $b = ($n * $sxy - $sx * $sy) / $den;
        $a = ($sy - $b * $sx) / $n;
        return ['a' => $a, 'b' => $b];
    }

    private static function prosCons(array $analytics, array $context): array
    {
        $pros = [];
        $cons = [];

        foreach (($analytics['categorical'] ?? []) as $col => $info) {
            $conc = $info['concentration'] ?? null;
            if (!$conc || !isset($conc['top1_share']) || $conc['top1_share'] === null) {
                continue;
            }
            $top1 = (float)$conc['top1_share'];
            $top3 = (float)($conc['top3_share'] ?? 0);
            if ($top1 >= 0.60) {
                $cons[] = $col . ': alta concentração (top1=' . self::fmt($top1 * 100) . '%).';
            } elseif ($top1 <= 0.25 && $top3 <= 0.50) {
                $pros[] = $col . ': baixa concentração (top1=' . self::fmt($top1 * 100) . '%; top3=' . self::fmt($top3 * 100) . '%).';
            }
        }

        foreach (($analytics['numeric'] ?? []) as $col => $st) {
            if (($st['count'] ?? 0) < 5) {
                continue;
            }
            $mean = (float)($st['mean'] ?? 0);
            $std = (float)($st['stddev'] ?? 0);
            $cv = $mean != 0.0 ? abs($std / $mean) : null;

            if ($cv !== null && $cv <= 0.10) {
                $pros[] = $col . ': baixa variabilidade (CV=' . self::fmt($cv * 100) . '%).';
            }
            if ($cv !== null && $cv >= 0.50) {
                $cons[] = $col . ': alta variabilidade (CV=' . self::fmt($cv * 100) . '%).';
            }
            if (!empty($st['outliers_count']) && (int)$st['outliers_count'] > 0) {
                $cons[] = $col . ': presença de outliers (n=' . (int)$st['outliers_count'] . ').';
            }
        }

        if (!empty($analytics['temporal']['trend'])) {
            $t = $analytics['temporal']['trend'];
            if (mb_strpos($t, 'crescente') !== false) {
                $pros[] = 'Série temporal com tendência crescente.';
            } elseif (mb_strpos($t, 'decrescente') !== false) {
                $cons[] = 'Série temporal com tendência decrescente.';
            }
        }

        if (empty($pros)) {
            $pros[] = 'Sem pontos fortes estatísticos suficientes com os critérios atuais.';
        }
        if (empty($cons)) {
            $cons[] = 'Sem pontos fracos estatísticos suficientes com os critérios atuais.';
        }

        return ['pros' => $pros, 'cons' => $cons];
    }

    private static function conclusion(array $profile, array $context, array $analytics): string
    {
        $rows = (int)($profile['volume']['rows'] ?? 0);
        $cols = (int)($profile['volume']['columns'] ?? 0);
        $domain = $context['domain'] ?? 'Operacional';

        $nNum = 0;
        foreach (($analytics['numeric'] ?? []) as $st) {
            if (($st['count'] ?? 0) > 0) {
                $nNum++;
            }
        }

        $hasTime = !empty($context['time_axis']) && !empty($analytics['temporal']);

        return 'Dataset com ' . $rows . ' linhas e ' . $cols . ' colunas. Domínio inferido=' . $domain . '. Colunas numéricas analisadas=' . $nNum . '. ' . ($hasTime ? 'Há eixo temporal utilizável.' : 'Sem eixo temporal utilizável.');
    }

    private static function percentile(array $sortedVals, float $p): float
    {
        $n = count($sortedVals);
        if ($n === 0) {
            return 0.0;
        }
        $pos = ($p / 100.0) * ($n - 1);
        $low = (int)floor($pos);
        $high = (int)ceil($pos);
        if ($low === $high) {
            return (float)$sortedVals[$low];
        }
        $w = $pos - $low;
        return (float)$sortedVals[$low] * (1.0 - $w) + (float)$sortedVals[$high] * $w;
    }

    private static function parseNumber(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        $s = preg_replace('/\s+/', '', $s);
        $s = str_replace(['R$', '$', '€', '£'], '', $s);

        $hasComma = strpos($s, ',') !== false;
        $hasDot = strpos($s, '.') !== false;

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($s, ',');
            $lastDot = strrpos($s, '.');
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma && !$hasDot) {
            $s = str_replace(',', '.', $s);
        }

        if (!is_numeric($s)) {
            return null;
        }
        return (float)$s;
    }

    private static function parseDate(string $s): ?DateTime
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        $fmts = [
            'Y-m-d',
            'Y/m/d',
            'd/m/Y',
            'd-m-Y',
            'm/d/Y',
            'Y-m-d H:i:s',
            'Y/m/d H:i:s',
            'd/m/Y H:i:s',
            'd-m-Y H:i:s',
        ];
        foreach ($fmts as $f) {
            $dt = DateTime::createFromFormat($f, $s);
            if ($dt instanceof DateTime) {
                return $dt;
            }
        }

        if (is_numeric($s) && strlen($s) >= 10) {
            $ts = (int)$s;
            if ($ts > 0) {
                $dt = new DateTime('@' . $ts);
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                return $dt;
            }
        }

        return null;
    }

    private static function fmt($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_nan((float)$v) || is_infinite((float)$v)) {
            return '0';
        }
        $x = (float)$v;
        if (abs($x) >= 1000) {
            return number_format($x, 2, '.', '');
        }
        return rtrim(rtrim(number_format($x, 4, '.', ''), '0'), '.');
    }

    private static function patternFromChart(array $c): string
    {
        $type = $c['chart_type'] ?? '';
        $values = $c['values'] ?? [];
        if (!is_array($values) || empty($values)) {
            return 'Sem dados numéricos suficientes.';
        }

        if ($type === 'line') {
            $first = (float)$values[0];
            $last = (float)$values[count($values) - 1];
            $delta = $last - $first;
            return $delta > 0 ? 'Crescimento no período.' : ($delta < 0 ? 'Queda no período.' : 'Estabilidade no período.');
        }

        if ($type === 'boxplot') {
            if (count($values) >= 5) {
                $min = (float)$values[0];
                $q1 = (float)$values[1];
                $med = (float)$values[2];
                $q3 = (float)$values[3];
                $max = (float)$values[4];
                $iqr = $q3 - $q1;
                return 'IQR=' . self::fmt($iqr) . ' | mediana=' . self::fmt($med) . ' | amplitude=' . self::fmt($max - $min) . '.';
            }
            return 'Resumo de 5 números disponível.';
        }

        if ($type === 'radar') {
            $max = max($values);
            $min = min($values);
            return 'Dispersão entre métricas (max-min)=' . self::fmt((float)$max - (float)$min) . '.';
        }

        if ($type === 'gantt') {
            $max = max($values);
            $min = min($values);
            return 'Duração (dias) | max=' . self::fmt($max) . ' | min=' . self::fmt($min) . '.';
        }

        $max = max($values);
        $min = min($values);
        return 'Amplitude=' . self::fmt((float)$max - (float)$min) . ' | pico=' . self::fmt($max) . ' | mínimo=' . self::fmt($min) . '.';
    }

    private static function meaningFromChart(array $c): string
    {
        $type = $c['chart_type'] ?? '';
        if ($type === 'pie') {
            return 'Indica concentração/distribuição percentual entre categorias.';
        }
        if ($type === 'bar') {
            return 'Permite comparação direta e ranking entre categorias/intervalos.';
        }
        if ($type === 'line') {
            return 'Evidencia tendência temporal, variações e pontos de pico/queda.';
        }
        if ($type === 'radar') {
            return 'Compara múltiplas métricas simultaneamente em um perfil relativo.';
        }
        if ($type === 'boxplot') {
            return 'Resume variabilidade e consistência via min/Q1/mediana/Q3/max (best-effort).';
        }
        if ($type === 'gantt') {
            return 'Representa duração por item (best-effort), útil para análise de fases/etapas com início/fim.';
        }
        return 'Visualização comparativa.';
    }
}
