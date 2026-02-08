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

        // ETAPA 1: Perfil do dataset + mapeamento canônico de colunas + leitura do prompt
        $typed = self::inferColumnTypes($cleanHeaders, $rows);
        $profile = self::buildDatasetProfile($cleanHeaders, $rows, $typed);
        $columnMap = self::mapColumns($cleanHeaders, $typed, $profile);
        $requestPlan = self::interpretUserRequest($cleanHeaders, $typed, $profile, $userRequest, $columnMap);
        $context = self::inferContext($cleanHeaders, $typed, $profile, $requestPlan, $columnMap);

        // ETAPA 2: O que é relevante (insights/indicadores) para o pedido
        $analytics = self::buildAnalytics($cleanHeaders, $rows, $typed, $context, $requestPlan);
        $dashboardPlan = self::buildDashboardPlan($profile, $context, $analytics, $requestPlan);

        // ETAPA 3: Criação dos gráficos específicos (apenas o que faz sentido para a solicitação)
        $charts = self::buildCharts($cleanHeaders, $rows, $typed, $context, $analytics, $requestPlan, $dashboardPlan, $columnMap);

        // ETAPA 4: Relatório final (formatado / "bonito")
        $reportHtml = self::buildReportHtml($profile, $context, $analytics, $charts, $dashboardPlan, $userRequest);
        $reportText = self::buildReport($profile, $context, $analytics, $charts);

        return [
            'dataset_profile' => $profile,
            'inferred_context' => $context,
            'column_map' => $columnMap,
            'request_plan' => $requestPlan,
            'dashboard_plan' => $dashboardPlan,
            'analytics' => $analytics,
            'charts' => $charts,
            'report_text' => $reportText,
            'report_html' => $reportHtml,
            'stages' => [
                '1' => [
                    'title' => 'Análise do arquivo e alinhamento do objetivo',
                    'context' => $context,
                    'column_map' => $columnMap,
                    'request_plan' => $requestPlan,
                    'dataset_profile' => [
                        'columns' => $profile['columns'] ?? [],
                        'volume' => $profile['volume'] ?? null,
                        'period' => $profile['period'] ?? null,
                    ],
                ],
                '2' => [
                    'title' => 'Seleção de informações relevantes e recomendações',
                    'dashboard_plan' => $dashboardPlan,
                ],
                '3' => [
                    'title' => 'Criação dos gráficos específicos',
                    'charts' => $charts,
                ],
                '4' => [
                    'title' => 'Relatório final',
                    'report_html' => $reportHtml,
                ],
            ],
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

    private static function inferContext(array $headers, array $types, array $profile, array $requestPlan, array $columnMap): array
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

        $forcedMetric = $requestPlan['metric'] ?? null;
        $forcedEntity = $requestPlan['entity'] ?? null;
        $forcedTime = $requestPlan['time_axis'] ?? null;

        // Defaults guiados pelo mapeamento canônico (antes de heurísticas genéricas)
        $mappedMetric = $columnMap['amount']['column'] ?? null;
        $mappedEntity = $columnMap['category']['column'] ?? null;
        $mappedTime = $columnMap['date']['column'] ?? null;

        if ($metricCol === null && is_string($mappedMetric) && in_array($mappedMetric, $headers, true)) {
            $metricCol = $mappedMetric;
        }
        if ($entityCol === null && is_string($mappedEntity) && in_array($mappedEntity, $headers, true)) {
            $entityCol = $mappedEntity;
        }
        if ($timeCol === null && is_string($mappedTime) && in_array($mappedTime, $headers, true)) {
            $timeCol = $mappedTime;
        }

        if (is_string($forcedMetric) && $forcedMetric !== '' && in_array($forcedMetric, $headers, true)) {
            $metricCol = $forcedMetric;
        }
        if (is_string($forcedEntity) && $forcedEntity !== '' && in_array($forcedEntity, $headers, true)) {
            $entityCol = $forcedEntity;
        }
        if (is_string($forcedTime) && $forcedTime !== '' && in_array($forcedTime, $headers, true)) {
            $timeCol = $forcedTime;
        }

        return [
            'domain' => $domain,
            'main_entity' => $entityCol,
            'main_metric' => $metricCol,
            'time_axis' => $timeCol,
        ];
    }

    private static function interpretUserRequest(array $headers, array $types, array $profile, ?string $userRequest, array $columnMap): array
    {
        $req = trim((string)$userRequest);
        if ($req === '') {
            return [
                'intents' => ['auto'],
                'raw' => '',
                'wants_detail' => false,
                'metric' => null,
                'entity' => null,
                'time_axis' => null,
                'limit' => null,
                'agg' => null,
            ];
        }

        $reqLower = mb_strtolower($req);

        $intents = [];
        $wantsDetail = (bool)preg_match('/\b(detalh|detalhar|detalhando|dentro\s+de\s+cada|por\s+categoria\s+e\s+sub|subcategoria|sub\s*categoria|itens|lan[cç]amentos)\b/u', $reqLower);
        // contexto financeiro (gastos/despesas) costuma pedir comparação e participação
        $mentionsSpend = (bool)preg_match('/\b(gasto|gastos|despesa|despesas|custo|custos|pagamento|pagar|sa[ií]da|saidas)\b/u', $reqLower);
        if (preg_match('/\b(tend[êe]ncia|evolu[cç][aã]o|ao longo|over time|time series|s[ée]rie temporal|timeline|mensal|di[aá]rio|semanal|por dia|por m[êe]s|por ano)\b/u', $reqLower)) {
            $intents[] = 'time_series';
        }
        if (preg_match('/\b(compar|comparar|vs\b|versus|ranking|top\s*\d+|maiores|melhores|piores|por\s+[\p{L}_]+)\b/u', $reqLower)) {
            $intents[] = 'comparison';
        }
        if (preg_match('/\b(distribui[cç][aã]o|histograma|frequ[êe]ncia|boxplot|quartil|vari[aâ]ncia)\b/u', $reqLower)) {
            $intents[] = 'distribution';
        }
        if (preg_match('/\b(participa[cç][aã]o|share|percentual|%|pizza|pie|market\s*share|propor[cç][aã]o)\b/u', $reqLower)) {
            $intents[] = 'share';
        }

        if ($mentionsSpend) {
            if (!in_array('comparison', $intents, true)) {
                $intents[] = 'comparison';
            }
            if (!in_array('share', $intents, true)) {
                $intents[] = 'share';
            }
        }

        if (empty($intents)) {
            $intents[] = 'auto';
        }

        $limit = null;
        if (preg_match('/\btop\s*(\d{1,3})\b/u', $reqLower, $m)) {
            $limit = (int)$m[1];
            if ($limit <= 0) {
                $limit = null;
            }
        }

        $metric = null;
        $entity = null;
        $timeAxis = null;

        $headersLower = [];
        foreach ($headers as $h) {
            $headersLower[] = mb_strtolower((string)$h);
        }

        foreach ($headers as $i => $hName) {
            $hLower = $headersLower[$i] ?? mb_strtolower((string)$hName);
            if ($hLower === '') {
                continue;
            }
            if (mb_strpos($reqLower, $hLower) !== false) {
                $t = $types[$i] ?? 'categorica';
                if ($t === 'numerica' && $metric === null) {
                    $metric = $hName;
                } elseif ($t === 'temporal' && $timeAxis === null) {
                    $timeAxis = $hName;
                } elseif ($t === 'categorica' && $entity === null) {
                    $entity = $hName;
                }
            }
        }

        // fallback: usa mapa canônico quando o prompt não cita colunas explicitamente
        if ($metric === null && !empty($columnMap['amount']['column'])) {
            $metric = $columnMap['amount']['column'];
        }
        if ($entity === null && !empty($columnMap['category']['column'])) {
            $entity = $columnMap['category']['column'];
        }
        if ($timeAxis === null && !empty($columnMap['date']['column'])) {
            $timeAxis = $columnMap['date']['column'];
        }

        if ($timeAxis === null) {
            foreach ($headers as $i => $hName) {
                if (($types[$i] ?? null) === 'temporal') {
                    if (preg_match('/\b(' . preg_quote(mb_strtolower((string)$hName), '/') . ')\b/u', $reqLower)) {
                        $timeAxis = $hName;
                        break;
                    }
                }
            }
        }

        $agg = null;
        if (preg_match('/\b(m[ée]dia|average|avg)\b/u', $reqLower)) {
            $agg = 'avg';
        } elseif (preg_match('/\b(soma|sum|total)\b/u', $reqLower)) {
            $agg = 'sum';
        } elseif (preg_match('/\b(contagem|count|quantidade|qtd)\b/u', $reqLower)) {
            $agg = 'count';
        }

        return [
            'intents' => $intents,
            'raw' => $req,
            'wants_detail' => $wantsDetail,
            'metric' => $metric,
            'entity' => $entity,
            'time_axis' => $timeAxis,
            'limit' => $limit,
            'agg' => $agg,
        ];
    }

    private static function mapColumns(array $headers, array $types, array $profile): array
    {
        // Mapeamento "IA" best-effort (heurístico): escolhe colunas canônicas com score/confiança.
        // Funciona para qualquer arquivo desde que exista headers/rows (inclui PDF via ingestPdfBestEffort).
        $candidates = [];
        foreach ($headers as $i => $h) {
            $name = (string)$h;
            $lower = mb_strtolower($name);
            $t = $types[$i] ?? 'categorica';
            $candidates[] = ['idx' => $i, 'name' => $name, 'lower' => $lower, 'type' => $t];
        }

        $pick = function(string $role, callable $scoreFn) use ($candidates): array {
            $best = null;
            $bestScore = -1.0;
            foreach ($candidates as $c) {
                $s = (float)$scoreFn($c);
                if ($s > $bestScore) {
                    $bestScore = $s;
                    $best = $c;
                }
            }
            if ($best === null || $bestScore <= 0) {
                return ['column' => null, 'confidence' => 0.0];
            }
            // confiança normalizada para 0-1
            $conf = min(1.0, max(0.0, $bestScore));
            return ['column' => $best['name'], 'confidence' => $conf];
        };

        $amount = $pick('amount', function($c) {
            if (($c['type'] ?? '') !== 'numerica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $score = 0.20;
            if (preg_match('/\b(valor|amount|total|pre[cç]o|price|receita|revenue|gasto|despesa|custo|pagamento|pago|a\s*pagar|a\s*receber|saldo)\b/u', $h)) {
                $score += 0.70;
            }
            if (preg_match('/\b(qtd|quantidade|quantity)\b/u', $h)) {
                $score += 0.15;
            }
            if (preg_match('/\b(id|cpf|cnpj|codigo|c[oó]digo)\b/u', $h)) {
                $score -= 0.40;
            }
            return max(0.0, $score);
        });

        $date = $pick('date', function($c) {
            if (($c['type'] ?? '') !== 'temporal') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $score = 0.30;
            if (preg_match('/\b(data|date|dt|dia|mes|m[êe]s|ano|timestamp|created_at|updated_at|venc|vencimento|emiss[aã]o)\b/u', $h)) {
                $score += 0.70;
            }
            return max(0.0, $score);
        });

        $category = $pick('category', function($c) {
            if (($c['type'] ?? '') !== 'categorica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $score = 0.15;
            if (preg_match('/\b(categoria|category|grupo|group|tipo|type|centro\s*de\s*custo|cc|natureza|conta|account)\b/u', $h)) {
                $score += 0.75;
            }
            if (preg_match('/\b(descri[cç][aã]o|hist[oó]rico|descricao|memo|observa[cç][aã]o|detalhe|item|lan[cç]amento)\b/u', $h)) {
                $score += 0.20;
            }
            if (preg_match('/\b(id|cpf|cnpj|codigo|c[oó]digo)\b/u', $h)) {
                $score -= 0.35;
            }
            return max(0.0, $score);
        });

        $subCategory = $pick('sub_category', function($c) {
            if (($c['type'] ?? '') !== 'categorica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $score = 0.10;
            if (preg_match('/\b(subcategoria|sub\s*categoria|item|lan[cç]amento|descri[cç][aã]o|descricao|hist[oó]rico|fornecedor|vendor|benefici[aá]rio|cliente|product|produto|servi[cç]o)\b/u', $h)) {
                $score += 0.85;
            }
            if (preg_match('/\b(categoria|category|grupo|group|tipo|type)\b/u', $h)) {
                $score += 0.15;
            }
            if (preg_match('/\b(id|cpf|cnpj|codigo|c[oó]digo)\b/u', $h)) {
                $score -= 0.35;
            }
            return max(0.0, $score);
        });

        // evita duplicar category/sub_category com a mesma coluna
        if (!empty($category['column']) && !empty($subCategory['column']) && $category['column'] === $subCategory['column']) {
            $subCategory = ['column' => null, 'confidence' => 0.0];
        }

        return [
            'amount' => $amount,
            'date' => $date,
            'category' => $category,
            'sub_category' => $subCategory,
        ];
    }

    private static function pickSecondaryCategory(array $headers, array $types, array $profile, ?string $primary): ?string
    {
        $best = null;
        $bestScore = -1.0;
        $sample = $profile['sample_rows'] ?? [];
        $sampleSize = max(1, is_array($sample) ? count($sample) : 1);

        foreach ($types as $i => $t) {
            if ($t !== 'categorica') {
                continue;
            }
            $name = $headers[$i] ?? null;
            if (!is_string($name) || $name === '' || $name === $primary) {
                continue;
            }
            $set = [];
            $nonEmpty = 0;
            foreach ($sample as $row) {
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
            // favorece subcategoria com granularidade maior que a entidade principal, mas não quase-única
            $score = 0.0;
            if ($ratio >= 0.05 && $ratio <= 0.80) {
                $score = 1.0 - abs($ratio - 0.35);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $name;
            }
        }

        return $best;
    }

    private static function buildDashboardPlan(array $profile, array $context, array $analytics, array $requestPlan): array
    {
        $intents = $requestPlan['intents'] ?? ['auto'];
        if (!is_array($intents) || empty($intents)) {
            $intents = ['auto'];
        }

        $metric = $context['main_metric'] ?? null;
        $entity = $context['main_entity'] ?? null;
        $timeAxis = $context['time_axis'] ?? null;

        $kpis = [];
        if (is_string($metric) && $metric !== '' && !empty($analytics['numeric'][$metric])) {
            $st = $analytics['numeric'][$metric];
            $kpis[] = ['label' => 'Métrica principal', 'value' => (string)$metric];
            if (isset($st['sum'])) {
                $kpis[] = ['label' => 'Total (soma)', 'value' => self::fmt((float)$st['sum'])];
            }
            if (isset($st['mean'])) {
                $kpis[] = ['label' => 'Média', 'value' => self::fmt((float)$st['mean'])];
            }
            if (isset($st['median'])) {
                $kpis[] = ['label' => 'Mediana', 'value' => self::fmt((float)$st['median'])];
            }
        }

        if (is_string($entity) && $entity !== '' && !empty($analytics['categorical'][$entity])) {
            $c = $analytics['categorical'][$entity];
            $kpis[] = ['label' => 'Dimensão principal', 'value' => (string)$entity];
            if (isset($c['distinct'])) {
                $kpis[] = ['label' => 'Categorias distintas', 'value' => (string)(int)$c['distinct']];
            }
        }

        if (is_string($timeAxis) && $timeAxis !== '' && !empty($profile['period'])) {
            $kpis[] = ['label' => 'Eixo temporal', 'value' => (string)$timeAxis];
            $kpis[] = ['label' => 'Período', 'value' => (string)($profile['period']['start'] ?? '') . ' a ' . (string)($profile['period']['end'] ?? '')];
        }

        $recommended = [];
        if (in_array('comparison', $intents, true) || in_array('auto', $intents, true)) {
            $recommended[] = 'Ranking (Top N) por soma da métrica';
        }
        if (in_array('share', $intents, true) || in_array('auto', $intents, true)) {
            $recommended[] = 'Participação (%) por categoria (quando aplicável)';
        }
        if (in_array('time_series', $intents, true) || in_array('auto', $intents, true)) {
            $recommended[] = 'Evolução temporal (dia/mês)';
        }

        return [
            'goal' => trim((string)($requestPlan['raw'] ?? '')),
            'intents' => $intents,
            'focus' => [
                'metric' => $metric,
                'entity' => $entity,
                'time_axis' => $timeAxis,
            ],
            'kpis' => $kpis,
            'recommended_outputs' => $recommended,
        ];
    }

    private static function buildAnalytics(array $headers, array $rows, array $types, array $context, array $requestPlan): array
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
        $aggPref = $requestPlan['agg'] ?? null;

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

    private static function buildCharts(array $headers, array $rows, array $types, array $context, array $analytics, array $requestPlan, array $dashboardPlan = [], array $columnMap = []): array
    {
        $charts = [];

        $intents = $requestPlan['intents'] ?? ['auto'];
        if (!is_array($intents) || empty($intents)) {
            $intents = ['auto'];
        }

        $wantTime = in_array('time_series', $intents, true) || in_array('auto', $intents, true);
        $wantComparison = in_array('comparison', $intents, true) || in_array('auto', $intents, true);
        $wantDistribution = in_array('distribution', $intents, true) || in_array('auto', $intents, true);
        $wantShare = in_array('share', $intents, true) || in_array('auto', $intents, true);

        foreach (($analytics['numeric'] ?? []) as $col => $stats) {
            if (!$wantDistribution) {
                continue;
            }
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

        foreach (($analytics['categorical'] ?? []) as $col => $info) {
            if (!$wantDistribution && !$wantShare) {
                continue;
            }

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

            if ($wantDistribution) {
                $charts[] = [
                    'chart_type' => 'bar',
                    'title' => 'Top categorias: ' . $col,
                    'description' => 'Ranking por contagem (top 20).',
                    'labels' => $labels,
                    'values' => $values,
                ];
            }

            // Pizza: apenas quando participação faz sentido (poucas categorias e concentração relevante)
            if ($wantShare) {
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
        }

        // Comparação entidade x métrica (soma)
        if ($wantComparison && !empty($analytics['comparisons']['entity_metric_sum']['top_sum'])) {
            $cmp = $analytics['comparisons']['entity_metric_sum'];
            $topAgg = $cmp['top_sum'];

            $limit = (int)($requestPlan['limit'] ?? 20);
            if ($limit <= 0) {
                $limit = 20;
            }
            $topAgg = array_slice($topAgg, 0, min(50, $limit), true);

            $charts[] = [
                'chart_type' => 'bar',
                'title' => 'Top ' . ($cmp['entity'] ?? 'entidades') . ' por soma de ' . ($cmp['metric'] ?? 'métrica'),
                'description' => 'Ranking por soma da métrica (top 20).',
                'labels' => array_keys($topAgg),
                'values' => array_values($topAgg),
            ];

            // Drilldown: detalhar dentro de cada categoria (quando pedido)
            $wantsDetail = (bool)($requestPlan['wants_detail'] ?? false);
            $primary = $cmp['entity'] ?? null;
            $metric = $cmp['metric'] ?? null;
            if ($wantsDetail && is_string($primary) && is_string($metric)) {
                $secondary = null;
                if (!empty($columnMap['sub_category']['column']) && is_string($columnMap['sub_category']['column'])) {
                    $secondary = $columnMap['sub_category']['column'];
                }
                if ($secondary === null || $secondary === '' || $secondary === $primary) {
                    $secondary = self::pickSecondaryCategory($headers, $types, ['sample_rows' => array_slice($rows, 0, 200)], $primary);
                }

                $pIdx = array_search($primary, $headers, true);
                $sIdx = $secondary ? array_search($secondary, $headers, true) : false;
                $mIdx = array_search($metric, $headers, true);
                if ($pIdx !== false && $mIdx !== false && $sIdx !== false) {
                    $topPrimary = array_keys($topAgg);
                    $maxCats = min(5, count($topPrimary));
                    for ($ci = 0; $ci < $maxCats; $ci++) {
                        $cat = $topPrimary[$ci];
                        $subAgg = [];
                        foreach ($rows as $row) {
                            $pv = isset($row[$pIdx]) ? trim((string)$row[$pIdx]) : '';
                            if ($pv !== $cat) {
                                continue;
                            }
                            $sv = isset($row[$sIdx]) ? trim((string)$row[$sIdx]) : '';
                            $mv = isset($row[$mIdx]) ? self::parseNumber((string)$row[$mIdx]) : null;
                            if ($sv === '' || $mv === null) {
                                continue;
                            }
                            if (!isset($subAgg[$sv])) {
                                $subAgg[$sv] = 0.0;
                            }
                            $subAgg[$sv] += (float)$mv;
                        }
                        if (empty($subAgg)) {
                            continue;
                        }
                        arsort($subAgg);
                        $subTop = array_slice($subAgg, 0, 12, true);
                        $charts[] = [
                            'chart_type' => 'bar',
                            'title' => 'Detalhamento: ' . $cat . ' (' . $secondary . ')',
                            'description' => 'Top itens/subcategorias dentro da categoria selecionada, por soma da métrica.',
                            'labels' => array_keys($subTop),
                            'values' => array_values($subTop),
                        ];
                    }
                }
            }

            if ($wantShare) {
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
        }

        if ($wantTime && !empty($analytics['temporal']['series'])) {
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

        // Radar foi removido do padrão para não poluir o dashboard analítico.

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

    private static function buildReportHtml(array $profile, array $context, array $analytics, array $charts, array $dashboardPlan, ?string $userRequest): string
    {
        $title = 'Relatório Analítico';
        $goal = trim((string)$userRequest);

        $kpis = $dashboardPlan['kpis'] ?? [];
        if (!is_array($kpis)) {
            $kpis = [];
        }

        $chartsList = '';
        foreach ($charts as $c) {
            $chartsList .= '<li><strong>' . htmlspecialchars((string)($c['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong><br><span style="color:#6b7280;font-size:12px;">' . htmlspecialchars((string)($c['description'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span></li>';
        }
        if ($chartsList === '') {
            $chartsList = '<li>Nenhum gráfico aplicável encontrado para o pedido.</li>';
        }

        $kpiHtml = '';
        foreach ($kpis as $k) {
            $kpiHtml .= '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;min-width:180px;flex:1;">
                <div style="font-size:12px;color:#6b7280;text-transform:uppercase;">' . htmlspecialchars((string)($k['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>
                <div style="font-size:16px;font-weight:600;color:#0f172a;">' . htmlspecialchars((string)($k['value'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>
            </div>';
        }
        if ($kpiHtml === '') {
            $kpiHtml = '<div style="color:#6b7280;font-size:13px;">KPIs não disponíveis para este dataset/pedido.</div>';
        }

        $domain = htmlspecialchars((string)($context['domain'] ?? 'Operacional'), ENT_QUOTES, 'UTF-8');
        $metric = htmlspecialchars((string)($context['main_metric'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $entity = htmlspecialchars((string)($context['main_entity'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $timeAxis = htmlspecialchars((string)($context['time_axis'] ?? '-'), ENT_QUOTES, 'UTF-8');

        $period = '';
        if (!empty($profile['period'])) {
            $period = htmlspecialchars((string)($profile['period']['start'] ?? ''), ENT_QUOTES, 'UTF-8') . ' a ' . htmlspecialchars((string)($profile['period']['end'] ?? ''), ENT_QUOTES, 'UTF-8');
        } else {
            $period = '-';
        }

        $goalHtml = $goal !== '' ? '<div style="margin-top:6px;color:#111827;font-size:14px;"><strong>Objetivo:</strong> ' . htmlspecialchars($goal, ENT_QUOTES, 'UTF-8') . '</div>' : '';

        return '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <div style="font-size:20px;font-weight:700;color:#0f172a;">' . $title . '</div>
                    <div style="font-size:13px;color:#6b7280;">Domínio inferido: <strong>' . $domain . '</strong></div>
                    ' . $goalHtml . '
                </div>
                <div style="font-size:12px;color:#6b7280;">Período: <strong>' . $period . '</strong></div>
            </div>

            <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">' . $kpiHtml . '</div>

            <div style="margin-top:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:14px;background:#ffffff;">
                <div style="font-weight:700;color:#0f172a;margin-bottom:6px;">Contexto de análise</div>
                <div style="font-size:13px;color:#374151;line-height:1.5;">
                    <div><strong>Métrica:</strong> ' . $metric . '</div>
                    <div><strong>Dimensão:</strong> ' . $entity . '</div>
                    <div><strong>Eixo temporal:</strong> ' . $timeAxis . '</div>
                </div>
            </div>

            <div style="margin-top:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:14px;background:#ffffff;">
                <div style="font-weight:700;color:#0f172a;margin-bottom:6px;">Saídas geradas (gráficos e focos)</div>
                <ol style="margin:0;padding-left:18px;color:#111827;font-size:13px;line-height:1.6;">' . $chartsList . '</ol>
            </div>
        </div>';
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
