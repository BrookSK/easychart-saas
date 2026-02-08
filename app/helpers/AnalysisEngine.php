<?php

class AnalysisEngine
{
    public static function run(array $table, ?string $userRequest = null, array $overrides = []): array
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

        // Overrides (respostas do usuário) podem travar colunas e evitar ambiguidades.
        if (!empty($overrides) && is_array($overrides)) {
            self::applyOverrides($cleanHeaders, $typed, $profile, $overrides, $columnMap, $requestPlan, $context);
        }

        // ETAPA 4/6: Motor de consistência (score global + ambiguidades + fallbacks governados)
        $consistency = self::consistencyEngine($cleanHeaders, $typed, $profile, $columnMap, $requestPlan, $context);
        self::applyAutoSelection($consistency, $columnMap, $requestPlan, $context);
        // Recalcula após autopick para atualizar confiança global e bloqueios
        $consistency = self::consistencyEngine($cleanHeaders, $typed, $profile, $columnMap, $requestPlan, $context);
        if (!empty($consistency['force_conservative'])) {
            $requestPlan['force_conservative'] = true;
        }
        if (!empty($consistency['disable_blocks']) && is_array($consistency['disable_blocks'])) {
            $requestPlan['disable_blocks'] = $consistency['disable_blocks'];
        }

        // Gate de confiabilidade: se houver ambiguidade relevante, perguntamos antes de gerar gráficos.
        $clarification = self::buildClarificationQuestions($cleanHeaders, $typed, $profile, $columnMap, $requestPlan, $context, $consistency);

        $decisionLog = self::buildDecisionLog($cleanHeaders, $typed, $profile, $columnMap, $requestPlan, $context, $clarification, $consistency);
        if (!empty($clarification['needs_clarification'])) {
            return [
                'needs_clarification' => true,
                'questions' => $clarification['questions'] ?? [],
                'decision_log' => $decisionLog,
                'dataset_profile' => $profile,
                'inferred_context' => $context,
                'column_map' => $columnMap,
                'request_plan' => $requestPlan,
                'analytic_plan' => null,
                'dashboard_plan' => null,
                'analytics' => null,
                'charts' => [],
                'report_text' => null,
                'report_html' => null,
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
                    'clarification' => [
                        'title' => 'Desambiguação (necessária)',
                        'questions' => $clarification['questions'] ?? [],
                    ],
                ],
            ];
        }

        // ETAPA 8/9: Plano analítico executável + validação anti-lixo
        $analyticPlan = self::buildAnalyticPlan($cleanHeaders, $typed, $profile, $requestPlan, $context, $columnMap);
        $planValidation = self::validateAnalyticPlan($analyticPlan, $profile);
        $analyticPlan['validation'] = $planValidation;
        $requestPlan['analytic_plan'] = $analyticPlan;

        // Fallback automático: se group_by tiver cardinalidade alta demais, degradamos o plano.
        $issues = $planValidation['issues'] ?? [];
        if (is_array($issues)) {
            foreach ($issues as $it) {
                $t = (string)($it['type'] ?? '');
                if ($t === 'group_by_too_high_cardinality') {
                    $analyticPlan['group_by'] = null;
                    if (!isset($analyticPlan['validation']) || !is_array($analyticPlan['validation'])) {
                        $analyticPlan['validation'] = [];
                    }
                    $analyticPlan['validation']['fallback_applied'] = 'removed_group_by_high_cardinality';
                    $requestPlan['analytic_plan'] = $analyticPlan;

                    $requestPlan['force_conservative'] = true;
                    if (!isset($requestPlan['disable_blocks']) || !is_array($requestPlan['disable_blocks'])) {
                        $requestPlan['disable_blocks'] = [];
                    }
                    $requestPlan['disable_blocks']['time_series'] = true;
                    $requestPlan['disable_blocks']['forecast'] = true;
                    $requestPlan['disable_blocks']['finance'] = true;
                    $requestPlan['disable_blocks']['gantt'] = true;
                    break;
                }
            }
        }

        // Governança anti-lixo: se o plano não é executável com segurança, degradamos automaticamente.
        if (empty($planValidation['ok'])) {
            $requestPlan['force_conservative'] = true;
            if (!isset($requestPlan['disable_blocks']) || !is_array($requestPlan['disable_blocks'])) {
                $requestPlan['disable_blocks'] = [];
            }
            $requestPlan['disable_blocks']['time_series'] = true;
            $requestPlan['disable_blocks']['forecast'] = true;
            $requestPlan['disable_blocks']['finance'] = true;
            $requestPlan['disable_blocks']['gantt'] = true;
        }
        if (!empty($analyticPlan['metric_op'])) {
            $requestPlan['agg'] = $analyticPlan['metric_op'];
        }
        if (!empty($analyticPlan['limit'])) {
            $requestPlan['limit'] = (int)$analyticPlan['limit'];
        }
        if (!empty($analyticPlan['group_by'])) {
            $context['main_entity'] = $analyticPlan['group_by'];
        }
        if (!empty($analyticPlan['metric_column'])) {
            $context['main_metric'] = $analyticPlan['metric_column'];
        }
        if (!empty($analyticPlan['time_axis'])) {
            $context['time_axis'] = $analyticPlan['time_axis'];
        }

        // ETAPA 2: O que é relevante (insights/indicadores) para o pedido
        $analytics = self::buildAnalytics($cleanHeaders, $rows, $typed, $context, $requestPlan, $columnMap);
        $dashboardPlan = self::buildDashboardPlan($profile, $context, $analytics, $requestPlan, $columnMap);

        // ETAPA 3: Criação dos gráficos específicos (apenas o que faz sentido para a solicitação)
        $charts = self::buildCharts($cleanHeaders, $rows, $typed, $context, $analytics, $requestPlan, $dashboardPlan, $columnMap);

        // ETAPA 4: Relatório final (formatado / "bonito")
        $reportHtml = self::buildReportHtml($profile, $context, $analytics, $charts, $dashboardPlan, $userRequest);
        $reportText = self::buildReport($profile, $context, $analytics, $charts);

        return [
            'needs_clarification' => false,
            'questions' => [],
            'decision_log' => $decisionLog,
            'dataset_profile' => $profile,
            'inferred_context' => $context,
            'column_map' => $columnMap,
            'request_plan' => $requestPlan,
            'analytic_plan' => $analyticPlan,
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
                'plan' => [
                    'title' => 'Plano analítico (executável) + validação',
                    'analytic_plan' => $analyticPlan,
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

    private static function applyOverrides(array $headers, array $types, array $profile, array $overrides, array &$columnMap, array &$requestPlan, array &$context): void
    {
        $forceConservative = (bool)($overrides['force_conservative'] ?? false);
        if (!empty($overrides['skip_clarification'])) {
            $forceConservative = true;
        }

        $resolveHeader = function($v) use ($headers): ?string {
            $s = trim((string)$v);
            if ($s === '') {
                return null;
            }
            foreach ($headers as $h) {
                if ((string)$h === $s) {
                    return (string)$h;
                }
            }
            return null;
        };

        if (!isset($requestPlan['locked_roles']) || !is_array($requestPlan['locked_roles'])) {
            $requestPlan['locked_roles'] = [];
        }

        $amount = $resolveHeader($overrides['amount_column'] ?? null);
        if ($amount !== null) {
            $columnMap['amount'] = ['column' => $amount, 'confidence' => 1.0];
            $context['main_metric'] = $amount;
            $requestPlan['locked_roles']['amount'] = true;
            if (!isset($requestPlan['role_origin']) || !is_array($requestPlan['role_origin'])) {
                $requestPlan['role_origin'] = [];
            }
            $requestPlan['role_origin']['amount'] = 'user_override';
        }

        $date = $resolveHeader($overrides['date_column'] ?? null);
        if ($date !== null) {
            $columnMap['date'] = ['column' => $date, 'confidence' => 1.0];
            $context['time_axis'] = $date;
            $requestPlan['locked_roles']['date'] = true;
            if (!isset($requestPlan['role_origin']) || !is_array($requestPlan['role_origin'])) {
                $requestPlan['role_origin'] = [];
            }
            $requestPlan['role_origin']['date'] = 'user_override';
        }

        $category = $resolveHeader($overrides['category_column'] ?? null);
        if ($category !== null) {
            $columnMap['category'] = ['column' => $category, 'confidence' => 1.0];
            $context['main_entity'] = $category;
            $requestPlan['locked_roles']['category'] = true;
            if (!isset($requestPlan['role_origin']) || !is_array($requestPlan['role_origin'])) {
                $requestPlan['role_origin'] = [];
            }
            $requestPlan['role_origin']['category'] = 'user_override';
        }

        if ($forceConservative) {
            $requestPlan['force_conservative'] = true;
        }

        // Ajuste opcional do "modo" financeiro (despesa/receita/cashflow)
        $mode = trim((string)($overrides['finance_mode'] ?? ''));
        if ($mode !== '') {
            $requestPlan['finance_mode'] = $mode;
        }
    }

    private static function buildClarificationQuestions(array $headers, array $types, array $profile, array $columnMap, array $requestPlan, array $context, array $consistency): array
    {
        if ((bool)($requestPlan['force_conservative'] ?? false)) {
            return ['needs_clarification' => false, 'questions' => []];
        }

        $questions = [];

        $hyp = $consistency['hypotheses'] ?? [];
        if (!is_array($hyp)) {
            $hyp = [];
        }
        $topScore = function(string $role) use ($hyp): float {
            $list = $hyp[$role] ?? [];
            if (!is_array($list) || empty($list)) {
                return 0.0;
            }
            return (float)($list[0]['score'] ?? 0.0);
        };

        $amountCol = $columnMap['amount']['column'] ?? null;
        $amountConf = max((float)($columnMap['amount']['confidence'] ?? 0.0), $topScore('amount'));
        $dateCol = $columnMap['date']['column'] ?? null;
        $dateConf = max((float)($columnMap['date']['confidence'] ?? 0.0), $topScore('date'));
        $catCol = $columnMap['category']['column'] ?? null;
        $catConf = max((float)($columnMap['category']['confidence'] ?? 0.0), $topScore('category'));

        $intents = $requestPlan['intents'] ?? ['auto'];
        if (!is_array($intents) || empty($intents)) {
            $intents = ['auto'];
        }
        $wantsTime = in_array('time_series', $intents, true);

        $numericCols = [];
        $temporalCols = [];
        $categoricalCols = [];
        foreach ($headers as $i => $h) {
            $t = $types[$i] ?? 'categorica';
            if ($t === 'numerica') {
                $numericCols[] = (string)$h;
            } elseif ($t === 'temporal') {
                $temporalCols[] = (string)$h;
            } else {
                $categoricalCols[] = (string)$h;
            }
        }

        $ambiguities = $consistency['ambiguities'] ?? [];
        if (!is_array($ambiguities)) {
            $ambiguities = [];
        }

        $impactRank = [
            'amount' => 3,
            'date' => 2,
            'category' => 1,
        ];
        usort($ambiguities, function($a, $b) use ($impactRank){
            $ra = $impactRank[(string)($a['role'] ?? '')] ?? 0;
            $rb = $impactRank[(string)($b['role'] ?? '')] ?? 0;
            return $rb <=> $ra;
        });

        $asked = [];
        foreach ($ambiguities as $amb) {
            $role = (string)($amb['role'] ?? '');
            if ($role === '' || isset($asked[$role])) {
                continue;
            }
            $amountOpts = [];
            if (!empty($hyp['amount']) && is_array($hyp['amount'])) {
                foreach ($hyp['amount'] as $h) {
                    if (!empty($h['column'])) {
                        $amountOpts[] = (string)$h['column'];
                    }
                }
            }
            if (empty($amountOpts)) {
                $amountOpts = $numericCols;
            }

            $dateOpts = [];
            if (!empty($hyp['date']) && is_array($hyp['date'])) {
                foreach ($hyp['date'] as $h) {
                    if (!empty($h['column'])) {
                        $dateOpts[] = (string)$h['column'];
                    }
                }
            }
            if (empty($dateOpts)) {
                $dateOpts = $temporalCols;
            }

            $catOpts = [];
            if (!empty($hyp['category']) && is_array($hyp['category'])) {
                foreach ($hyp['category'] as $h) {
                    if (!empty($h['column'])) {
                        $catOpts[] = (string)$h['column'];
                    }
                }
            }
            if (empty($catOpts)) {
                $catOpts = array_slice($categoricalCols, 0, 40);
            }

            if ($role === 'amount' && !empty($amountOpts) && ($amountCol === null || $amountCol === '' || $amountConf < 0.85)) {
                $questions[] = [
                    'id' => 'amount_column',
                    'type' => 'select',
                    'label' => 'Qual coluna representa o VALOR principal?',
                    'why' => 'Isso muda rankings, ganhos/perdas e análises financeiras.',
                    'options' => array_values(array_unique($amountOpts)),
                    'default' => is_string($amountCol) ? $amountCol : null,
                ];
                $asked[$role] = true;
            }
            if ($role === 'date' && $wantsTime && !empty($dateOpts) && ($dateCol === null || $dateCol === '' || $dateConf < 0.85)) {
                $questions[] = [
                    'id' => 'date_column',
                    'type' => 'select',
                    'label' => 'Qual coluna representa a DATA principal?',
                    'why' => 'Isso muda linha do tempo e previsões.',
                    'options' => array_values(array_unique($dateOpts)),
                    'default' => is_string($dateCol) ? $dateCol : null,
                ];
                $asked[$role] = true;
            }
            if ($role === 'category' && !empty($catOpts) && ($catCol === null || $catCol === '' || $catConf < 0.70)) {
                $questions[] = [
                    'id' => 'category_column',
                    'type' => 'select',
                    'label' => 'Qual coluna devo usar como CATEGORIA/DIMENSÃO para agrupar?',
                    'why' => 'Isso muda completamente rankings e participação.',
                    'options' => array_values(array_unique($catOpts)),
                    'default' => is_string($catCol) ? $catCol : null,
                ];
                $asked[$role] = true;
            }

            if (count($questions) >= 2) {
                break;
            }
        }

        return [
            'needs_clarification' => !empty($questions),
            'questions' => $questions,
        ];
    }

    private static function buildDecisionLog(array $headers, array $types, array $profile, array $columnMap, array $requestPlan, array $context, array $clarification, array $consistency): array
    {
        $checks = [];

        $colIndex = function(?string $name) use ($headers) {
            if (!is_string($name) || $name === '') {
                return false;
            }
            return array_search($name, $headers, true);
        };

        $sampleRows = $profile['sample_rows'] ?? [];
        if (!is_array($sampleRows)) {
            $sampleRows = [];
        }

        $columnQuality = function(int $idx, string $expectedType) use ($sampleRows): array {
            $n = 0;
            $nonEmpty = 0;
            $ok = 0;
            foreach ($sampleRows as $row) {
                $n++;
                $v = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
                if ($v === '') {
                    continue;
                }
                $nonEmpty++;
                if ($expectedType === 'numerica') {
                    if (self::parseNumber($v) !== null) {
                        $ok++;
                    }
                } elseif ($expectedType === 'temporal') {
                    if (self::parseDate($v) !== null) {
                        $ok++;
                    }
                } else {
                    $ok++;
                }
            }

            $completeness = $n > 0 ? ($nonEmpty / $n) : 0.0;
            $validRatio = $nonEmpty > 0 ? ($ok / $nonEmpty) : 0.0;
            return [
                'sample_n' => $n,
                'non_empty' => $nonEmpty,
                'completeness' => round($completeness, 3),
                'valid_ratio' => round($validRatio, 3),
            ];
        };

        $amountCol = $columnMap['amount']['column'] ?? null;
        $dateCol = $columnMap['date']['column'] ?? null;
        $catCol = $columnMap['category']['column'] ?? null;

        $origin = $requestPlan['role_origin'] ?? [];
        if (!is_array($origin)) {
            $origin = [];
        }
        $originDefault = function(string $role) use ($origin): string {
            $v = $origin[$role] ?? '';
            if ($v === 'user_override' || $v === 'autopick') {
                return $v;
            }
            return 'heuristic';
        };

        $amountIdx = $colIndex(is_string($amountCol) ? $amountCol : null);
        if ($amountIdx !== false) {
            $checks['amount_quality'] = $columnQuality((int)$amountIdx, 'numerica');
        }
        $dateIdx = $colIndex(is_string($dateCol) ? $dateCol : null);
        if ($dateIdx !== false) {
            $checks['date_quality'] = $columnQuality((int)$dateIdx, 'temporal');
        }
        $catIdx = $colIndex(is_string($catCol) ? $catCol : null);
        if ($catIdx !== false) {
            $checks['category_quality'] = $columnQuality((int)$catIdx, 'categorica');
        }

        $intents = $requestPlan['intents'] ?? [];
        if (!is_array($intents)) {
            $intents = [];
        }

        return [
            'dataset_quality' => [
                'quality_score' => $profile['quality_score'] ?? null,
                'structure' => $profile['structure'] ?? null,
            ],
            'consistency' => [
                'confidence_global' => $consistency['confidence_global'] ?? null,
                'disable_blocks' => $consistency['disable_blocks'] ?? null,
                'ambiguities' => $consistency['ambiguities'] ?? null,
                'notes' => $consistency['notes'] ?? null,
            ],
            'analytic_plan' => $requestPlan['analytic_plan'] ?? null,
            'mode' => [
                'force_conservative' => (bool)($requestPlan['force_conservative'] ?? false),
            ],
            'selected' => [
                'amount' => [
                    'column' => $amountCol,
                    'confidence' => (float)($columnMap['amount']['confidence'] ?? 0.0),
                    'origin' => $originDefault('amount'),
                ],
                'date' => [
                    'column' => $dateCol,
                    'confidence' => (float)($columnMap['date']['confidence'] ?? 0.0),
                    'origin' => $originDefault('date'),
                ],
                'category' => [
                    'column' => $catCol,
                    'confidence' => (float)($columnMap['category']['confidence'] ?? 0.0),
                    'origin' => $originDefault('category'),
                ],
            ],
            'context' => [
                'main_metric' => $context['main_metric'] ?? null,
                'main_entity' => $context['main_entity'] ?? null,
                'time_axis' => $context['time_axis'] ?? null,
                'intents' => $intents,
            ],
            'checks' => $checks,
            'clarification' => [
                'needs_clarification' => (bool)($clarification['needs_clarification'] ?? false),
                'question_ids' => array_values(array_filter(array_map(function($q){
                    return is_array($q) ? (string)($q['id'] ?? '') : '';
                }, $clarification['questions'] ?? []))),
            ],
        ];
    }

    private static function consistencyEngine(array $headers, array $types, array $profile, array $columnMap, array $requestPlan, array $context): array
    {
        $qualityScore = (float)($profile['quality_score'] ?? 0.0);
        $columnProfiles = $profile['column_profiles'] ?? [];
        if (!is_array($columnProfiles)) {
            $columnProfiles = [];
        }

        $hypotheses = [
            'amount' => self::rankRoleHypotheses('amount', $headers, $columnProfiles),
            'date' => self::rankRoleHypotheses('date', $headers, $columnProfiles),
            'category' => self::rankRoleHypotheses('category', $headers, $columnProfiles),
        ];

        $roleScore = function(string $role) use ($hypotheses): float {
            $list = $hypotheses[$role] ?? [];
            if (!is_array($list) || empty($list)) {
                return 0.0;
            }
            return (float)($list[0]['score'] ?? 0.0);
        };

        $isClose = function(string $role, float $margin) use ($hypotheses): bool {
            $list = $hypotheses[$role] ?? [];
            if (!is_array($list) || count($list) < 2) {
                return false;
            }
            $a = (float)($list[0]['score'] ?? 0.0);
            $b = (float)($list[1]['score'] ?? 0.0);
            return ($a > 0.0) && (($a - $b) <= $margin);
        };

        $amountCol = $columnMap['amount']['column'] ?? null;
        $amountConf = (float)($columnMap['amount']['confidence'] ?? 0.0);
        $dateCol = $columnMap['date']['column'] ?? null;
        $dateConf = (float)($columnMap['date']['confidence'] ?? 0.0);
        $catCol = $columnMap['category']['column'] ?? null;
        $catConf = (float)($columnMap['category']['confidence'] ?? 0.0);

        $amountOk = 0.0;
        if (is_string($amountCol) && isset($columnProfiles[$amountCol])) {
            $p = $columnProfiles[$amountCol];
            $amountOk = min(1.0, (float)($p['numeric_ratio'] ?? 0.0)) * min(1.0, (float)($p['completeness'] ?? 0.0));
        }
        $dateOk = 0.0;
        if (is_string($dateCol) && isset($columnProfiles[$dateCol])) {
            $p = $columnProfiles[$dateCol];
            $dateOk = min(1.0, (float)($p['date_ratio'] ?? 0.0)) * min(1.0, (float)($p['completeness'] ?? 0.0));
        }
        $catOk = 0.0;
        if (is_string($catCol) && isset($columnProfiles[$catCol])) {
            $p = $columnProfiles[$catCol];
            // categoria útil raramente é quase única; penaliza unique_ratio muito alto
            $uniq = (float)($p['unique_ratio'] ?? 0.0);
            $pen = $uniq >= 0.85 ? 0.55 : ($uniq >= 0.70 ? 0.75 : 1.0);
            $catOk = min(1.0, (float)($p['completeness'] ?? 0.0)) * $pen;
        }

        $confidenceGlobal = (0.35 * $qualityScore)
            + (0.30 * min(1.0, $amountConf) * $amountOk)
            + (0.20 * min(1.0, $dateConf) * $dateOk)
            + (0.15 * min(1.0, $catConf) * $catOk);
        $confidenceGlobal = max(0.0, min(1.0, $confidenceGlobal));

        // Penalização explícita por ambiguidade: top2 muito próximo => alto risco semântico.
        $ambiguityPenalty = 1.0;
        $closeAmount = $isClose('amount', 0.08);
        $closeDate = $isClose('date', 0.08);
        $closeCategory = $isClose('category', 0.08);
        if ($closeAmount) {
            $ambiguityPenalty *= 0.88;
        }
        if ($closeDate) {
            $ambiguityPenalty *= 0.92;
        }
        if ($closeCategory) {
            $ambiguityPenalty *= 0.94;
        }
        $confidenceGlobal = $confidenceGlobal * $ambiguityPenalty;
        $confidenceGlobal = max(0.0, min(1.0, $confidenceGlobal));
        $confidenceGlobal = round($confidenceGlobal, 3);

        $disable = [
            'time_series' => false,
            'forecast' => false,
            'finance' => false,
            'gantt' => false,
        ];

        $ambiguities = [];
        if ($amountConf < 0.75 || $amountOk < 0.75) {
            $ambiguities[] = ['role' => 'amount', 'reason' => 'low_confidence_or_quality'];
        } elseif ($isClose('amount', 0.08)) {
            $ambiguities[] = ['role' => 'amount', 'reason' => 'top2_close'];
        }
        if ($dateConf < 0.75 || $dateOk < 0.75) {
            $ambiguities[] = ['role' => 'date', 'reason' => 'low_confidence_or_quality'];
        } elseif ($isClose('date', 0.08)) {
            $ambiguities[] = ['role' => 'date', 'reason' => 'top2_close'];
        }
        if ($catConf < 0.60 || $catOk < 0.60) {
            $ambiguities[] = ['role' => 'category', 'reason' => 'low_confidence_or_quality'];
        } elseif ($isClose('category', 0.08)) {
            $ambiguities[] = ['role' => 'category', 'reason' => 'top2_close'];
        }

        // Governança por risco (sem perguntar)
        if ($qualityScore < 0.60) {
            $disable['time_series'] = true;
            $disable['forecast'] = true;
            $disable['gantt'] = true;
        }

        // Se houver ambiguidade em papéis críticos, degradamos blocos de alto custo de erro
        if ($closeAmount) {
            $disable['finance'] = true;
        }
        if ($closeDate) {
            $disable['forecast'] = true;
        }
        if (($dateConf < 0.80 || $dateOk < 0.80) && !empty($requestPlan['intents']) && is_array($requestPlan['intents']) && in_array('time_series', $requestPlan['intents'], true)) {
            $disable['time_series'] = true;
            $disable['forecast'] = true;
        }
        if ($dateConf < 0.85 || $dateOk < 0.85) {
            $disable['forecast'] = true;
        }
        if ($amountConf < 0.85 || $amountOk < 0.85) {
            $disable['finance'] = true;
        }
        if ($confidenceGlobal < 0.60) {
            $disable['time_series'] = true;
            $disable['forecast'] = true;
            $disable['finance'] = true;
            $disable['gantt'] = true;
        }

        $forceConservative = false;
        if ($confidenceGlobal < 0.60) {
            $forceConservative = true;
        }

        $notes = [];
        if ($forceConservative) {
            $notes[] = 'force_conservative_low_confidence';
        }
        if ($qualityScore < 0.60) {
            $notes[] = 'low_dataset_quality';
        }
        if ($ambiguityPenalty < 1.0) {
            $notes[] = 'ambiguity_penalty_applied';
        }
        if ($closeAmount) {
            $notes[] = 'ambiguous_amount_top2_close';
        }
        if ($closeDate) {
            $notes[] = 'ambiguous_date_top2_close';
        }
        if ($closeCategory) {
            $notes[] = 'ambiguous_category_top2_close';
        }

        if (!empty($requestPlan['autopick']) && is_array($requestPlan['autopick'])) {
            $notes[] = 'autopick_applied';
        }

        return [
            'confidence_global' => $confidenceGlobal,
            'ambiguities' => $ambiguities,
            'hypotheses' => $hypotheses,
            'ambiguity_penalty' => round($ambiguityPenalty, 3),
            'disable_blocks' => $disable,
            'force_conservative' => $forceConservative,
            'notes' => $notes,
        ];
    }

    private static function applyAutoSelection(array $consistency, array &$columnMap, array &$requestPlan, array &$context): void
    {
        $locked = $requestPlan['locked_roles'] ?? [];
        if (!is_array($locked)) {
            $locked = [];
        }

        $hyp = $consistency['hypotheses'] ?? [];
        if (!is_array($hyp)) {
            return;
        }

        $applyRole = function(string $role, string $contextKey) use (&$columnMap, &$requestPlan, &$context, $locked, $hyp): void {
            if (!empty($locked[$role])) {
                return;
            }
            $list = $hyp[$role] ?? [];
            if (!is_array($list) || empty($list)) {
                return;
            }

            $best = $list[0];
            $bestCol = (string)($best['column'] ?? '');
            $bestScore = (float)($best['score'] ?? 0.0);
            if ($bestCol === '' || $bestScore <= 0.0) {
                return;
            }

            $curCol = $columnMap[$role]['column'] ?? null;
            $curConf = (float)($columnMap[$role]['confidence'] ?? 0.0);

            // Só auto-seleciona quando for claramente superior e com score mínimo
            if ($bestScore >= 0.60 && ($curCol === null || $curCol === '' || $bestScore >= ($curConf + 0.10))) {
                $columnMap[$role] = ['column' => $bestCol, 'confidence' => max($curConf, min(1.0, $bestScore))];
                $context[$contextKey] = $bestCol;
                if (!isset($requestPlan['autopick']) || !is_array($requestPlan['autopick'])) {
                    $requestPlan['autopick'] = [];
                }
                $requestPlan['autopick'][$role] = [
                    'from' => $curCol,
                    'to' => $bestCol,
                    'score' => round($bestScore, 3),
                ];
                if (!isset($requestPlan['role_origin']) || !is_array($requestPlan['role_origin'])) {
                    $requestPlan['role_origin'] = [];
                }
                if (empty($requestPlan['role_origin'][$role])) {
                    $requestPlan['role_origin'][$role] = 'autopick';
                }
            }
        };

        $applyRole('amount', 'main_metric');
        $applyRole('date', 'time_axis');
        $applyRole('category', 'main_entity');
    }

    private static function buildAnalyticPlan(array $headers, array $types, array $profile, array $requestPlan, array $context, array $columnMap): array
    {
        $intents = $requestPlan['intents'] ?? ['auto'];
        if (!is_array($intents) || empty($intents)) {
            $intents = ['auto'];
        }

        $metricOp = $requestPlan['agg'] ?? null;
        if (!is_string($metricOp) || $metricOp === '') {
            $metricOp = 'sum';
        }
        if (!in_array($metricOp, ['sum', 'avg', 'count'], true)) {
            $metricOp = 'sum';
        }

        $groupBy = $requestPlan['entity'] ?? ($context['main_entity'] ?? null);
        if (!is_string($groupBy) || $groupBy === '') {
            $groupBy = null;
        }

        $metricColumn = $requestPlan['metric'] ?? ($context['main_metric'] ?? null);
        if (!is_string($metricColumn) || $metricColumn === '') {
            $metricColumn = $columnMap['amount']['column'] ?? null;
        }
        if (!is_string($metricColumn) || $metricColumn === '') {
            $metricColumn = null;
        }
        if ($metricOp === 'count') {
            $metricColumn = null;
        }

        $timeAxis = $requestPlan['time_axis'] ?? ($context['time_axis'] ?? null);
        if (!is_string($timeAxis) || $timeAxis === '') {
            $timeAxis = null;
        }

        $limit = $requestPlan['limit'] ?? null;
        $limit = is_numeric($limit) ? (int)$limit : null;
        if ($limit !== null && $limit <= 0) {
            $limit = null;
        }
        if ($limit === null) {
            $limit = 20;
        }
        $limit = min(50, max(5, $limit));

        $order = 'desc';
        if (preg_match('/\b(menores|piores|ascendente|menor\s+para\s+maior)\b/u', mb_strtolower((string)($requestPlan['raw'] ?? '')))) {
            $order = 'asc';
        }

        $bucket = null;
        if ($timeAxis !== null && (in_array('time_series', $intents, true) || in_array('auto', $intents, true))) {
            $bucket = 'day';
            if (preg_match('/\b(mensal|por\s*m[êe]s|monthly)\b/u', mb_strtolower((string)($requestPlan['raw'] ?? '')))) {
                $bucket = 'month';
            }
        }

        return [
            'group_by' => $groupBy,
            'metric_op' => $metricOp,
            'metric_column' => $metricColumn,
            'time_axis' => $timeAxis,
            'time_bucket' => $bucket,
            'order' => $order,
            'limit' => $limit,
        ];
    }

    private static function validateAnalyticPlan(array $analyticPlan, array $profile): array
    {
        $issues = [];
        $ok = true;

        $columnProfiles = $profile['column_profiles'] ?? [];
        if (!is_array($columnProfiles)) {
            $columnProfiles = [];
        }

        $groupBy = $analyticPlan['group_by'] ?? null;
        if (is_string($groupBy) && isset($columnProfiles[$groupBy])) {
            $p = $columnProfiles[$groupBy];
            $uniq = (float)($p['unique_ratio'] ?? 0.0);
            $comp = (float)($p['completeness'] ?? 0.0);
            if ($comp < 0.50) {
                $ok = false;
                $issues[] = ['type' => 'group_by_low_completeness', 'column' => $groupBy, 'value' => $comp];
            }
            if ($uniq > 0.90) {
                $issues[] = ['type' => 'group_by_too_high_cardinality', 'column' => $groupBy, 'value' => $uniq];
            }
        }

        $metricOp = (string)($analyticPlan['metric_op'] ?? 'sum');
        $metricColumn = $analyticPlan['metric_column'] ?? null;
        if ($metricOp !== 'count') {
            if (!is_string($metricColumn) || $metricColumn === '') {
                $ok = false;
                $issues[] = ['type' => 'missing_metric_column', 'value' => null];
            } elseif (isset($columnProfiles[$metricColumn])) {
                $p = $columnProfiles[$metricColumn];
                $num = (float)($p['numeric_ratio'] ?? 0.0);
                $comp = (float)($p['completeness'] ?? 0.0);
                if ($num < 0.70) {
                    $ok = false;
                    $issues[] = ['type' => 'metric_not_numeric_enough', 'column' => $metricColumn, 'value' => $num];
                }
                if ($comp < 0.50) {
                    $issues[] = ['type' => 'metric_low_completeness', 'column' => $metricColumn, 'value' => $comp];
                }
            }
        }

        $timeAxis = $analyticPlan['time_axis'] ?? null;
        if (is_string($timeAxis) && isset($columnProfiles[$timeAxis])) {
            $p = $columnProfiles[$timeAxis];
            $dr = (float)($p['date_ratio'] ?? 0.0);
            $comp = (float)($p['completeness'] ?? 0.0);
            if ($comp < 0.40) {
                $issues[] = ['type' => 'time_axis_low_completeness', 'column' => $timeAxis, 'value' => $comp];
            }
            if ($dr < 0.70) {
                $issues[] = ['type' => 'time_axis_low_date_ratio', 'column' => $timeAxis, 'value' => $dr];
            }
        }

        return [
            'ok' => $ok,
            'issues' => $issues,
        ];
    }

    private static function rankRoleHypotheses(string $role, array $headers, array $columnProfiles): array
    {
        $out = [];
        foreach ($headers as $h) {
            $name = (string)$h;
            $p = $columnProfiles[$name] ?? null;
            if (!is_array($p)) {
                continue;
            }
            $score = 0.0;
            $signals = [];

            $lower = mb_strtolower($name);

            if ($role === 'amount') {
                $num = (float)($p['numeric_ratio'] ?? 0.0);
                $comp = (float)($p['completeness'] ?? 0.0);
                $uniq = (float)($p['unique_ratio'] ?? 0.0);
                $cur = (int)(($p['signals']['currency'] ?? 0));
                $score = (0.55 * $num) + (0.25 * $comp);
                if ($cur > 0) {
                    $score += 0.10;
                    $signals[] = 'currency_signal';
                }
                if ($uniq >= 0.95) {
                    $score -= 0.25;
                    $signals[] = 'very_high_unique_ratio';
                }
                if (preg_match('/\b(valor|amount|total|pre[cç]o|price|receita|revenue|gasto|despesa|custo|pagamento|pago|saldo)\b/u', $lower)) {
                    $score += 0.20;
                    $signals[] = 'header_match_money';
                }
            } elseif ($role === 'date') {
                $dr = (float)($p['date_ratio'] ?? 0.0);
                $comp = (float)($p['completeness'] ?? 0.0);
                $score = (0.70 * $dr) + (0.20 * $comp);
                if (preg_match('/\b(data|date|dt|dia|mes|m[êe]s|ano|timestamp|created_at|updated_at|venc|vencimento|emiss[aã]o|pagamento)\b/u', $lower)) {
                    $score += 0.10;
                    $signals[] = 'header_match_date';
                }
            } elseif ($role === 'category') {
                $comp = (float)($p['completeness'] ?? 0.0);
                $uniq = (float)($p['unique_ratio'] ?? 0.0);
                $len = (float)($p['avg_len'] ?? 0.0);
                // categoria útil: não pode ser quase única; e texto não muito longo
                $pen = $uniq >= 0.90 ? 0.35 : ($uniq >= 0.75 ? 0.70 : 1.0);
                $lenPen = $len >= 60 ? 0.70 : 1.0;
                $score = (0.55 * $comp) * $pen * $lenPen;
                if (preg_match('/\b(categoria|category|grupo|group|tipo|type|centro\s*de\s*custo|cc|natureza|conta|account|fornecedor|cliente)\b/u', $lower)) {
                    $score += 0.25;
                    $signals[] = 'header_match_category';
                }
                if (preg_match('/\b(descri[cç][aã]o|descricao|hist[oó]rico|memo|observa[cç][aã]o|detalhe|item|lan[cç]amento)\b/u', $lower)) {
                    $score += 0.05;
                    $signals[] = 'header_match_description';
                }
            }

            $score = max(0.0, min(1.0, $score));
            if ($score <= 0.0) {
                continue;
            }

            $out[] = [
                'column' => $name,
                'score' => round($score, 3),
                'signals' => $signals,
            ];
        }

        usort($out, function($a, $b){
            return ((float)($b['score'] ?? 0.0)) <=> ((float)($a['score'] ?? 0.0));
        });

        return array_slice($out, 0, 5);
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

        $structure = self::profileStructure($headers, $rows);
        $columnProfiles = self::profileColumnsNeutral($headers, $rows);
        $qualityScore = self::computeDatasetQualityScore($structure, $columnProfiles);

        return [
            'columns' => $colList,
            'volume' => [
                'rows' => count($rows),
                'columns' => count($headers),
            ],
            'period' => $period,
            'structure' => $structure,
            'column_profiles' => $columnProfiles,
            'quality_score' => $qualityScore,
            'sample_rows' => $sampleRows,
        ];
    }

    private static function profileStructure(array $headers, array $rows): array
    {
        $expectedCols = count($headers);
        $n = min(500, count($rows));
        $ok = 0;
        $minCols = null;
        $maxCols = null;
        for ($i = 0; $i < $n; $i++) {
            $c = is_array($rows[$i] ?? null) ? count($rows[$i]) : 0;
            if ($minCols === null || $c < $minCols) {
                $minCols = $c;
            }
            if ($maxCols === null || $c > $maxCols) {
                $maxCols = $c;
            }
            if ($expectedCols > 0 && $c === $expectedCols) {
                $ok++;
            }
        }
        $ratio = $n > 0 ? ($ok / $n) : 0.0;
        return [
            'expected_columns' => $expectedCols,
            'sample_rows' => $n,
            'row_width_min' => $minCols,
            'row_width_max' => $maxCols,
            'row_width_consistency' => round($ratio, 3),
        ];
    }

    private static function profileColumnsNeutral(array $headers, array $rows): array
    {
        $n = min(500, count($rows));
        $out = [];

        for ($i = 0; $i < count($headers); $i++) {
            $total = 0;
            $nonEmpty = 0;
            $unique = [];
            $numericOk = 0;
            $dateOk = 0;
            $lenSum = 0;

            $signals = [
                'email' => 0,
                'cpf_cnpj' => 0,
                'phone' => 0,
                'currency' => 0,
                'url' => 0,
            ];

            for ($r = 0; $r < $n; $r++) {
                $total++;
                $v = isset($rows[$r][$i]) ? trim((string)$rows[$r][$i]) : '';
                if ($v === '') {
                    continue;
                }
                $nonEmpty++;
                $low = mb_strtolower($v);
                $unique[$low] = true;
                $lenSum += mb_strlen($v);

                if (self::parseNumber($v) !== null) {
                    $numericOk++;
                }
                if (self::parseDate($v) !== null) {
                    $dateOk++;
                }
                if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $v)) {
                    $signals['email']++;
                }
                if (preg_match('/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b|\b\d{11}\b|\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b|\b\d{14}\b/', $v)) {
                    $signals['cpf_cnpj']++;
                }
                if (preg_match('/\b\+?\d{1,3}\s*\(?\d{2}\)?\s*\d{4,5}[-\s]?\d{4}\b/', $v)) {
                    $signals['phone']++;
                }
                if (preg_match('/(R\$|\$|€|£)\s*[-+]?\d/u', $v)) {
                    $signals['currency']++;
                }
                if (preg_match('/\bhttps?:\/\//i', $v)) {
                    $signals['url']++;
                }
            }

            $completeness = $total > 0 ? ($nonEmpty / $total) : 0.0;
            $uniqueRatio = $nonEmpty > 0 ? (count($unique) / $nonEmpty) : 0.0;
            $numericRatio = $nonEmpty > 0 ? ($numericOk / $nonEmpty) : 0.0;
            $dateRatio = $nonEmpty > 0 ? ($dateOk / $nonEmpty) : 0.0;
            $avgLen = $nonEmpty > 0 ? ($lenSum / $nonEmpty) : 0.0;

            $out[(string)$headers[$i]] = [
                'completeness' => round($completeness, 3),
                'unique_ratio' => round($uniqueRatio, 3),
                'numeric_ratio' => round($numericRatio, 3),
                'date_ratio' => round($dateRatio, 3),
                'avg_len' => round($avgLen, 1),
                'signals' => $signals,
            ];
        }

        return $out;
    }

    private static function computeDatasetQualityScore(array $structure, array $columnProfiles): float
    {
        $rowConsistency = (float)($structure['row_width_consistency'] ?? 0.0);
        $expectedCols = (int)($structure['expected_columns'] ?? 0);
        $completenessAvg = 0.0;
        $n = 0;
        foreach ($columnProfiles as $p) {
            $completenessAvg += (float)($p['completeness'] ?? 0.0);
            $n++;
        }
        $completenessAvg = $n > 0 ? ($completenessAvg / $n) : 0.0;

        $headerQuality = 0.0;
        if ($expectedCols > 0) {
            $nonEmpty = 0;
            foreach (array_keys($columnProfiles) as $h) {
                if (trim((string)$h) !== '') {
                    $nonEmpty++;
                }
            }
            $headerQuality = $expectedCols > 0 ? ($nonEmpty / $expectedCols) : 0.0;
        }

        $score = (0.55 * $rowConsistency) + (0.30 * $completenessAvg) + (0.15 * $headerQuality);
        $score = max(0.0, min(1.0, $score));
        return round($score, 3);
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

        $sampleRows = $profile['sample_rows'] ?? [];
        if (!is_array($sampleRows)) {
            $sampleRows = [];
        }

        $colSample = function(int $idx) use ($sampleRows): array {
            $out = [];
            foreach ($sampleRows as $row) {
                $out[] = isset($row[$idx]) ? (string)$row[$idx] : '';
            }
            return $out;
        };

        $numericProfile = function(array $vals): array {
            $n = 0;
            $neg = 0;
            $zero = 0;
            $sumAbs = 0.0;
            $sum = 0.0;
            $currencyLike = 0;
            foreach ($vals as $s) {
                $st = trim((string)$s);
                if ($st === '') {
                    continue;
                }
                if (preg_match('/(R\$|\$|€|£)/u', $st)) {
                    $currencyLike++;
                }
                $v = self::parseNumber($st);
                if ($v === null) {
                    continue;
                }
                $n++;
                $sum += (float)$v;
                $sumAbs += abs((float)$v);
                if ($v < 0) {
                    $neg++;
                }
                if ($v == 0.0) {
                    $zero++;
                }
            }
            return [
                'n' => $n,
                'neg_ratio' => $n > 0 ? ($neg / $n) : 0.0,
                'zero_ratio' => $n > 0 ? ($zero / $n) : 0.0,
                'mean_abs' => $n > 0 ? ($sumAbs / $n) : 0.0,
                'mean' => $n > 0 ? ($sum / $n) : 0.0,
                'currency_ratio' => count($vals) > 0 ? ($currencyLike / max(1, count($vals))) : 0.0,
            ];
        };

        $textProfile = function(array $vals): array {
            $nonEmpty = 0;
            $sumLen = 0;
            $distinct = [];
            $keywords = [
                'receita' => 0,
                'despesa' => 0,
                'custo' => 0,
                'beneficio' => 0,
                'ganho' => 0,
                'perda' => 0,
            ];
            foreach ($vals as $s) {
                $st = trim((string)$s);
                if ($st === '') {
                    continue;
                }
                $nonEmpty++;
                $sumLen += mb_strlen($st);
                $distinct[mb_strtolower($st)] = true;
                $low = mb_strtolower($st);
                if (strpos($low, 'receita') !== false || strpos($low, 'credito') !== false || strpos($low, 'crédito') !== false) {
                    $keywords['receita']++;
                }
                if (strpos($low, 'despesa') !== false || strpos($low, 'debito') !== false || strpos($low, 'débito') !== false) {
                    $keywords['despesa']++;
                }
                if (strpos($low, 'custo') !== false) {
                    $keywords['custo']++;
                }
                if (strpos($low, 'benef') !== false) {
                    $keywords['beneficio']++;
                }
                if (strpos($low, 'ganh') !== false) {
                    $keywords['ganho']++;
                }
                if (strpos($low, 'perd') !== false) {
                    $keywords['perda']++;
                }
            }
            $avgLen = $nonEmpty > 0 ? ($sumLen / $nonEmpty) : 0.0;
            $distinctCount = count($distinct);
            $ratio = $nonEmpty > 0 ? ($distinctCount / $nonEmpty) : 0.0;
            return [
                'non_empty' => $nonEmpty,
                'avg_len' => $avgLen,
                'distinct_ratio' => $ratio,
                'keywords' => $keywords,
            ];
        };

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

        $amount = $pick('amount', function($c) use ($colSample, $numericProfile) {
            if (($c['type'] ?? '') !== 'numerica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $vals = $colSample((int)$c['idx']);
            $np = $numericProfile($vals);
            $score = 0.15;
            if (preg_match('/\b(valor|amount|total|pre[cç]o|price|receita|revenue|gasto|despesa|custo|pagamento|pago|a\s*pagar|a\s*receber|saldo)\b/u', $h)) {
                $score += 0.70;
            }
            if (preg_match('/\b(qtd|quantidade|quantity)\b/u', $h)) {
                $score += 0.15;
            }
            if (preg_match('/\b(id|cpf|cnpj|codigo|c[oó]digo)\b/u', $h)) {
                $score -= 0.40;
            }
            if (($np['n'] ?? 0) >= 10) {
                // valores monetários tendem a ter média absoluta relevante e/ou símbolo de moeda
                if (($np['mean_abs'] ?? 0.0) > 1.0) {
                    $score += 0.25;
                }
                if (($np['currency_ratio'] ?? 0.0) >= 0.10) {
                    $score += 0.25;
                }
            }
            return max(0.0, $score);
        });

        $cost = $pick('cost', function($c) use ($colSample, $numericProfile) {
            if (($c['type'] ?? '') !== 'numerica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $vals = $colSample((int)$c['idx']);
            $np = $numericProfile($vals);
            $score = 0.05;
            if (preg_match('/\b(custo|cost|despesa|expense|gasto|spend)\b/u', $h)) {
                $score += 0.75;
            }
            if (($np['neg_ratio'] ?? 0.0) > 0.05) {
                $score += 0.10;
            }
            if (($np['mean_abs'] ?? 0.0) > 1.0) {
                $score += 0.10;
            }
            return max(0.0, $score);
        });

        $benefit = $pick('benefit', function($c) use ($colSample, $numericProfile) {
            if (($c['type'] ?? '') !== 'numerica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $vals = $colSample((int)$c['idx']);
            $np = $numericProfile($vals);
            $score = 0.05;
            if (preg_match('/\b(benef[ií]cio|benefit|ganho|gain|lucro|profit|receita|revenue)\b/u', $h)) {
                $score += 0.75;
            }
            if (($np['mean_abs'] ?? 0.0) > 1.0) {
                $score += 0.10;
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

        $category = $pick('category', function($c) use ($colSample, $textProfile) {
            if (($c['type'] ?? '') !== 'categorica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $vals = $colSample((int)$c['idx']);
            $tp = $textProfile($vals);
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
            // categoria tende a ter texto mais curto e repetição (distinct_ratio menor)
            if (($tp['non_empty'] ?? 0) >= 10) {
                if (($tp['avg_len'] ?? 0.0) > 0 && ($tp['avg_len'] ?? 0.0) <= 28) {
                    $score += 0.15;
                }
                if (($tp['distinct_ratio'] ?? 1.0) > 0.0 && ($tp['distinct_ratio'] ?? 1.0) <= 0.60) {
                    $score += 0.15;
                }
            }
            return max(0.0, $score);
        });

        $subCategory = $pick('sub_category', function($c) use ($colSample, $textProfile) {
            if (($c['type'] ?? '') !== 'categorica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $vals = $colSample((int)$c['idx']);
            $tp = $textProfile($vals);
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
            // descrição/subcategoria tende a ser mais longa e mais diversa
            if (($tp['non_empty'] ?? 0) >= 10) {
                if (($tp['avg_len'] ?? 0.0) >= 18) {
                    $score += 0.15;
                }
                if (($tp['distinct_ratio'] ?? 0.0) >= 0.55) {
                    $score += 0.15;
                }
            }
            return max(0.0, $score);
        });

        $direction = $pick('direction', function($c) use ($colSample, $textProfile) {
            if (($c['type'] ?? '') !== 'categorica') {
                return 0.0;
            }
            $h = $c['lower'] ?? '';
            $vals = $colSample((int)$c['idx']);
            $tp = $textProfile($vals);
            $score = 0.05;
            if (preg_match('/\b(tipo|type|natureza|movimento|opera[cç][aã]o|entrada|sa[ií]da|credito|cr[eé]dito|debito|d[eé]bito|ganho|perda)\b/u', $h)) {
                $score += 0.75;
            }
            $kw = $tp['keywords'] ?? [];
            $hits = (int)($kw['receita'] ?? 0) + (int)($kw['despesa'] ?? 0) + (int)($kw['ganho'] ?? 0) + (int)($kw['perda'] ?? 0);
            if (($tp['non_empty'] ?? 0) > 0) {
                $ratio = $hits / max(1, (int)$tp['non_empty']);
                if ($ratio >= 0.10) {
                    $score += 0.25;
                }
            }
            return max(0.0, $score);
        });

        // evita duplicar category/sub_category com a mesma coluna
        if (!empty($category['column']) && !empty($subCategory['column']) && $category['column'] === $subCategory['column']) {
            $subCategory = ['column' => null, 'confidence' => 0.0];
        }

        return [
            'amount' => $amount,
            'cost' => $cost,
            'benefit' => $benefit,
            'date' => $date,
            'category' => $category,
            'sub_category' => $subCategory,
            'direction' => $direction,
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

    private static function buildDashboardPlan(array $profile, array $context, array $analytics, array $requestPlan, array $columnMap): array
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
            'column_map' => $columnMap,
            'analytic_plan' => $requestPlan['analytic_plan'] ?? null,
            'governance' => [
                'force_conservative' => (bool)($requestPlan['force_conservative'] ?? false),
                'disable_blocks' => $requestPlan['disable_blocks'] ?? null,
            ],
            'kpis' => $kpis,
            'recommended_outputs' => $recommended,
        ];
    }

    private static function buildAnalytics(array $headers, array $rows, array $types, array $context, array $requestPlan, array $columnMap): array
    {
        $numericStats = [];
        $categoricalStats = [];
        $temporalStats = [];
        $comparisons = [];
        $finance = [];

        $disable = $requestPlan['disable_blocks'] ?? [];
        if (!is_array($disable)) {
            $disable = [];
        }
        $disableFinance = !empty($disable['finance']);
        $disableTimeSeries = !empty($disable['time_series']);

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

        $plan = $requestPlan['analytic_plan'] ?? [];
        if (!is_array($plan)) {
            $plan = [];
        }
        $metricOp = (string)($plan['metric_op'] ?? ($aggPref ?: 'sum'));
        $metricColumn = $plan['metric_column'] ?? $metric;
        if ($metricOp === 'count') {
            $metricColumn = null;
        }

        if (!$disableFinance) {
            // Ganhos x Perdas: tenta usar coluna amount (mapeada) e sinais (+/-)
            $amountCol = $columnMap['amount']['column'] ?? null;
            if (is_string($amountCol) && $amountCol !== '') {
                $aIdx = array_search($amountCol, $headers, true);
                if ($aIdx !== false) {
                    $sumPos = 0.0;
                    $sumNeg = 0.0;
                    $countPos = 0;
                    $countNeg = 0;
                    foreach ($rows as $row) {
                        $v = isset($row[$aIdx]) ? self::parseNumber((string)$row[$aIdx]) : null;
                        if ($v === null) {
                            continue;
                        }
                        if ($v >= 0) {
                            $sumPos += (float)$v;
                            $countPos++;
                        } else {
                            $sumNeg += (float)$v;
                            $countNeg++;
                        }
                    }

                    if (($countPos + $countNeg) > 0) {
                        $finance['cashflow'] = [
                            'amount_column' => $amountCol,
                            'sum_positive' => $sumPos,
                            'sum_negative' => $sumNeg,
                            'net' => $sumPos + $sumNeg,
                            'count_positive' => $countPos,
                            'count_negative' => $countNeg,
                        ];
                    }
                }
            }

            $entityIdx = false;
            if (is_string($entity) && $entity !== '') {
                $entityIdx = array_search($entity, $headers, true);
            }
            if ($entityIdx !== false) {
                $agg = [];
                $counts = [];
                foreach ($rows as $row) {
                    $k = isset($row[$entityIdx]) ? trim((string)$row[$entityIdx]) : '';
                    if ($k === '') {
                        continue;
                    }

                    if ($metricOp === 'count') {
                        if (!isset($agg[$k])) {
                            $agg[$k] = 0.0;
                        }
                        $agg[$k] += 1.0;
                        continue;
                    }

                    if ($metricIdx === null || $metricIdx === false) {
                        continue;
                    }
                    $v = isset($row[$metricIdx]) ? self::parseNumber((string)$row[$metricIdx]) : null;
                    if ($v === null) {
                        continue;
                    }
                    if (!isset($agg[$k])) {
                        $agg[$k] = 0.0;
                        $counts[$k] = 0;
                    }
                    $agg[$k] += (float)$v;
                    $counts[$k] += 1;
                }

                if (!empty($agg)) {
                    if ($metricOp === 'avg') {
                        foreach ($agg as $k => $sum) {
                            $c = (int)($counts[$k] ?? 0);
                            $agg[$k] = $c > 0 ? ((float)$sum / $c) : 0.0;
                        }
                    }
                    $order = (string)($plan['order'] ?? 'desc');
                    if ($order === 'asc') {
                        asort($agg);
                    } else {
                        arsort($agg);
                    }
                    $total = array_sum($agg);
                    $topAgg = array_slice($agg, 0, 50, true);
                    $shares = [];
                    if ($total > 0) {
                        foreach ($topAgg as $k => $v) {
                            $shares[$k] = (float)$v / (float)$total;
                        }
                    }
                    $comparisons['entity_metric_sum'] = [
                        'entity' => $entity,
                        'metric' => $metricOp === 'count' ? 'count' : (string)$metricColumn,
                        'op' => $metricOp,
                        'top_sum' => $topAgg,
                        'total_sum' => $total,
                        'top_shares' => $shares,
                    ];
                }
            }
        }

        if (!$disableTimeSeries && $timeAxis && ($metricOp === 'count' || $metricColumn)) {
            $timeIdx = array_search($timeAxis, $headers, true);
            $metricIdx = null;
            if ($metricOp !== 'count' && is_string($metricColumn) && $metricColumn !== '') {
                $metricIdx = array_search($metricColumn, $headers, true);
            }
            if ($timeIdx !== false && ($metricOp === 'count' || $metricIdx !== false)) {
                $series = [];
                $seriesMonthly = [];
                foreach ($rows as $row) {
                    $d = isset($row[$timeIdx]) ? self::parseDate((string)$row[$timeIdx]) : null;
                    $v = null;
                    if ($metricOp === 'count') {
                        $v = 1.0;
                    } else {
                        $v = ($metricIdx === null || $metricIdx === false) ? null : self::parseNumber((string)($row[$metricIdx] ?? ''));
                    }
                    if ($d === null || $v === null) {
                        continue;
                    }
                    $key = $d->format('Y-m-d');
                    if (!isset($series[$key])) {
                        $series[$key] = 0.0;
                    }
                    $series[$key] += (float)$v;

                    $mKey = $d->format('Y-m');
                    if (!isset($seriesMonthly[$mKey])) {
                        $seriesMonthly[$mKey] = 0.0;
                    }
                    $seriesMonthly[$mKey] += (float)$v;
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
            'finance' => $finance,
        ];
    }

    private static function buildCharts(array $headers, array $rows, array $types, array $context, array $analytics, array $requestPlan, array $dashboardPlan = [], array $columnMap = []): array
    {
        $charts = [];

        $push = function(array $chart) use (&$charts): void {
            $charts[] = $chart;
        };

        $intents = $requestPlan['intents'] ?? ['auto'];
        if (!is_array($intents) || empty($intents)) {
            $intents = ['auto'];
        }

        $wantTime = in_array('time_series', $intents, true) || in_array('auto', $intents, true);
        $wantComparison = in_array('comparison', $intents, true) || in_array('auto', $intents, true);
        $wantDistribution = in_array('distribution', $intents, true) || in_array('auto', $intents, true);
        $wantShare = in_array('share', $intents, true) || in_array('auto', $intents, true);

        $disable = $requestPlan['disable_blocks'] ?? [];
        if (!is_array($disable)) {
            $disable = [];
        }
        $disableFinance = !empty($disable['finance']);
        $disableTimeSeries = !empty($disable['time_series']);
        $disableForecast = !empty($disable['forecast']);
        $disableGantt = !empty($disable['gantt']);

        $plan = $requestPlan['analytic_plan'] ?? [];
        if (!is_array($plan)) {
            $plan = [];
        }
        $metricOp = (string)($plan['metric_op'] ?? ($requestPlan['agg'] ?? 'sum'));
        if (!in_array($metricOp, ['sum', 'avg', 'count'], true)) {
            $metricOp = 'sum';
        }
        $metricOpLabel = $metricOp === 'count' ? 'contagem' : ($metricOp === 'avg' ? 'média' : 'soma');

        if (!empty($requestPlan['force_conservative'])) {
            // Em modo conservador, evitamos séries temporais/forecast/gantt e distribuições que poluem.
            $wantTime = false;
            $wantDistribution = false;
        }

        // Finance overview (ganhos x perdas / saldo) - aparece cedo para deixar o dashboard claro
        if (!$disableFinance && !empty($analytics['finance']['cashflow'])) {
            $cf = $analytics['finance']['cashflow'];
            $pos = (float)($cf['sum_positive'] ?? 0);
            $neg = (float)($cf['sum_negative'] ?? 0);
            $net = (float)($cf['net'] ?? 0);

            // Pizza / participação (ganhos x perdas)
            $push([
                'chart_type' => 'pie',
                'title' => 'Ganhos x Perdas (participação)',
                'description' => 'Participação percentual de valores positivos (ganhos) vs negativos (perdas).',
                'labels' => ['Ganhos', 'Perdas'],
                'values' => [abs($pos), abs($neg)],
            ]);

            // Barra / saldo líquido
            $push([
                'chart_type' => 'bar',
                'title' => 'Resumo financeiro (total)',
                'description' => 'Totais agregados: ganhos, perdas e saldo líquido.',
                'labels' => ['Ganhos', 'Perdas', 'Saldo'],
                'values' => [$pos, $neg, $net],
            ]);
        }

        // Custo x Benefício (quando existem colunas próprias)
        $costCol = $columnMap['cost']['column'] ?? null;
        $benefCol = $columnMap['benefit']['column'] ?? null;
        if (is_string($costCol) && $costCol !== '' && is_string($benefCol) && $benefCol !== '' && $costCol !== $benefCol) {
            $cIdx = array_search($costCol, $headers, true);
            $bIdx = array_search($benefCol, $headers, true);
            if ($cIdx !== false && $bIdx !== false) {
                $sumC = 0.0;
                $sumB = 0.0;
                $n = 0;
                foreach ($rows as $row) {
                    $cv = isset($row[$cIdx]) ? self::parseNumber((string)$row[$cIdx]) : null;
                    $bv = isset($row[$bIdx]) ? self::parseNumber((string)$row[$bIdx]) : null;
                    if ($cv === null || $bv === null) {
                        continue;
                    }
                    $sumC += (float)$cv;
                    $sumB += (float)$bv;
                    $n++;
                }
                if ($n > 0) {
                    $roi = $sumC != 0.0 ? ($sumB / $sumC) : null;
                    $push([
                        'chart_type' => 'bar',
                        'title' => 'Custo x Benefício (total)',
                        'description' => 'Comparação agregada entre custo e benefício. ROI=' . ($roi === null ? 'n/a' : self::fmt((float)$roi)),
                        'labels' => ['Custo', 'Benefício'],
                        'values' => [$sumC, $sumB],
                    ]);
                }
            }
        }

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

            $push([
                'chart_type' => 'bar',
                'title' => 'Top ' . ($cmp['entity'] ?? 'entidades') . ' por ' . $metricOpLabel . ' de ' . ($cmp['metric'] ?? 'métrica'),
                'description' => 'Ranking por ' . $metricOpLabel . ' da métrica (top ' . (int)$limit . ').',
                'labels' => array_keys($topAgg),
                'values' => array_values($topAgg),
            ]);

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
                        $push([
                            'chart_type' => 'bar',
                            'title' => 'Detalhamento: ' . $cat . ' (' . $secondary . ')',
                            'description' => 'Top itens/subcategorias dentro da categoria selecionada, por soma da métrica.',
                            'labels' => array_keys($subTop),
                            'values' => array_values($subTop),
                        ]);
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
                    $push([
                        'chart_type' => 'pie',
                        'title' => 'Participação (soma): ' . ($cmp['entity'] ?? 'entidade'),
                        'description' => 'Participação percentual por soma da métrica (top).',
                        'labels' => array_keys($shares),
                        'values' => $pct,
                    ]);
                }
            }
        }

        if ($wantTime && !$disableTimeSeries && !empty($analytics['temporal']['series'])) {
            $labels = array_keys($analytics['temporal']['series']);
            $values = array_values($analytics['temporal']['series']);
            $push([
                'chart_type' => 'line',
                'title' => 'Evolução temporal (' . $metricOpLabel . '): ' . ($context['main_metric'] ?? 'métrica'),
                'description' => 'Série temporal agregada por dia (' . $metricOpLabel . ').',
                'labels' => $labels,
                'values' => $values,
            ]);

            if (!empty($analytics['temporal']['series_monthly'])) {
                $mLabels = array_keys($analytics['temporal']['series_monthly']);
                $mValues = array_values($analytics['temporal']['series_monthly']);
                $push([
                    'chart_type' => 'line',
                    'title' => 'Evolução mensal (' . $metricOpLabel . '): ' . ($context['main_metric'] ?? 'métrica'),
                    'description' => 'Série temporal agregada por mês (' . $metricOpLabel . ').',
                    'labels' => $mLabels,
                    'values' => $mValues,
                ]);
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
                $push([
                    'chart_type' => 'bar',
                    'title' => 'Variação percentual: ' . ($context['main_metric'] ?? 'métrica'),
                    'description' => 'Variação percentual entre períodos consecutivos (em %).',
                    'labels' => $pctLabels,
                    'values' => $pctValues,
                ]);
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
                $push([
                    'chart_type' => 'bar',
                    'title' => 'Variação (delta): ' . ($context['main_metric'] ?? 'métrica'),
                    'description' => 'Diferença absoluta entre períodos consecutivos.',
                    'labels' => $deltaLabels,
                    'values' => $deltas,
                ]);
            }

            // forecast simples se disponível
            if (!$disableForecast && !empty($analytics['temporal']['forecast'])) {
                $f = $analytics['temporal']['forecast'];
                if (!empty($f['labels']) && !empty($f['values']) && count($f['labels']) === count($f['values'])) {
                    $push([
                        'chart_type' => 'line',
                        'title' => 'Projeção simples: ' . ($context['main_metric'] ?? 'métrica'),
                        'description' => 'Extrapolação linear simples (baseada na série observada).',
                        'labels' => $f['labels'],
                        'values' => $f['values'],
                    ]);
                }
            }
        }

        // Gantt best-effort (governado por disable_blocks)
        if (!$disableGantt) {
            $startIdx = null;
            $endIdx = null;
            foreach ($headers as $i => $h) {
                $l = mb_strtolower((string)$h);
                if ($startIdx === null && (strpos($l, 'inicio') !== false || strpos($l, 'início') !== false || strpos($l, 'start') !== false)) {
                    $startIdx = $i;
                }
                if ($endIdx === null && (strpos($l, 'fim') !== false || strpos($l, 'término') !== false || strpos($l, 'termino') !== false || strpos($l, 'end') !== false)) {
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
                    $push([
                        'chart_type' => 'gantt',
                        'title' => 'Gantt (duração em dias)',
                        'description' => 'Gráfico tipo Gantt (best-effort) representado como duração total (dias) por item.',
                        'labels' => array_keys($dur),
                        'values' => array_values($dur),
                    ]);
                }
            }
        }

        // Limite e ordem: evita excesso e mantém legibilidade
        $maxCharts = 12;
        if (count($charts) > $maxCharts) {
            $charts = array_slice($charts, 0, $maxCharts);
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

        $analyticPlan = $dashboardPlan['analytic_plan'] ?? null;
        $gov = $dashboardPlan['governance'] ?? [];
        if (!is_array($gov)) {
            $gov = [];
        }

        $planHtml = '';
        if (is_array($analyticPlan)) {
            $validation = $analyticPlan['validation'] ?? [];
            if (!is_array($validation)) {
                $validation = [];
            }
            $issues = $validation['issues'] ?? [];
            if (!is_array($issues)) {
                $issues = [];
            }

            $planRows = '';
            $planRows .= '<div><strong>Group by:</strong> ' . htmlspecialchars((string)($analyticPlan['group_by'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</div>';
            $planRows .= '<div><strong>Métrica:</strong> ' . htmlspecialchars((string)($analyticPlan['metric_op'] ?? '-'), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars((string)($analyticPlan['metric_column'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
            $planRows .= '<div><strong>Tempo:</strong> ' . htmlspecialchars((string)($analyticPlan['time_axis'] ?? '-'), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars((string)($analyticPlan['time_bucket'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
            $planRows .= '<div><strong>Ordem:</strong> ' . htmlspecialchars((string)($analyticPlan['order'] ?? '-'), ENT_QUOTES, 'UTF-8') . ' | <strong>Limite:</strong> ' . htmlspecialchars((string)($analyticPlan['limit'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</div>';

            $issuesHtml = '';
            if (!empty($issues)) {
                foreach ($issues as $it) {
                    $issuesHtml .= '<li>' . htmlspecialchars((string)($it['type'] ?? ''), ENT_QUOTES, 'UTF-8') . '</li>';
                }
                $issuesHtml = '<div style="margin-top:8px;"><strong>Validação:</strong> ' . (!empty($validation['ok']) ? 'ok' : 'falhou') . '<ol style="margin:6px 0 0 18px;">' . $issuesHtml . '</ol></div>';
            } else {
                $issuesHtml = '<div style="margin-top:8px;"><strong>Validação:</strong> ' . (!empty($validation['ok']) ? 'ok' : 'falhou') . '</div>';
            }

            if (!empty($validation['fallback_applied'])) {
                $issuesHtml .= '<div style="margin-top:6px;color:#6b7280;font-size:12px;"><strong>Fallback:</strong> ' . htmlspecialchars((string)$validation['fallback_applied'], ENT_QUOTES, 'UTF-8') . '</div>';
            }

            $planHtml = '<div style="margin-top:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:14px;background:#ffffff;">
                <div style="font-weight:700;color:#0f172a;margin-bottom:6px;">Plano analítico (executável)</div>
                <div style="font-size:13px;color:#374151;line-height:1.6;">' . $planRows . $issuesHtml . '</div>
            </div>';
        }

        $limitations = '';
        $disable = $gov['disable_blocks'] ?? [];
        if (!is_array($disable)) {
            $disable = [];
        }
        $disabledList = [];
        foreach (['time_series' => 'Série temporal', 'forecast' => 'Forecast', 'finance' => 'Financeiro (ganhos/perdas)', 'gantt' => 'Gantt'] as $k => $label) {
            if (!empty($disable[$k])) {
                $disabledList[] = $label;
            }
        }
        if (!empty($gov['force_conservative']) || !empty($disabledList)) {
            $limitations .= '<div style="margin-top:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:14px;background:#fff7ed;">
                <div style="font-weight:700;color:#9a3412;margin-bottom:6px;">Limitações aplicadas (governança)</div>
                <div style="font-size:13px;color:#7c2d12;line-height:1.6;">';
            if (!empty($gov['force_conservative'])) {
                $limitations .= '<div><strong>Modo conservador:</strong> ativado</div>';
            }
            if (!empty($disabledList)) {
                $limitations .= '<div><strong>Blocos omitidos:</strong> ' . htmlspecialchars(implode(', ', $disabledList), ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $limitations .= '</div></div>';
        }

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

            ' . $planHtml . '
            ' . $limitations . '

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

        $lines[] = '1.1 Plano analítico e governança';
        $metric = $context['main_metric'] ?? null;
        $entity = $context['main_entity'] ?? null;
        $timeAxis = $context['time_axis'] ?? null;
        $lines[] = 'Métrica: ' . ($metric ?: '-');
        $lines[] = 'Dimensão: ' . ($entity ?: '-');
        $lines[] = 'Eixo temporal: ' . ($timeAxis ?: '-');
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
