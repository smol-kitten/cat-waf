<?php
/**
 * Cat-WAF Public Tools API
 * Provides public utilities including RSL builder, validators, etc.
 * These endpoints don't require authentication
 */

require_once __DIR__ . '/../lib/RSL/RSLDocument.php';

use CatWAF\RSL\RSLDocument;

function handleCatWafTools($method, $params, $db) {
    $tool = $params[0] ?? 'index';
    
    switch ($tool) {
        case 'index':
        case '':
            handleToolsIndex($method);
            break;
            
        case 'rsl-builder':
            handleRSLBuilder($method, array_slice($params, 1), $db);
            break;
            
        case 'rsl-validate':
            handleRSLValidate($method, $db);
            break;
            
        case 'rsl-presets':
            handleRSLPresets($method);
            break;
            
        case 'rsl-fetch':
            handleRSLFetch($method);
            break;
            
        case 'robots-txt-generator':
            handleRobotsTxtGenerator($method);
            break;
            
        default:
            sendResponse(['error' => 'Unknown tool: ' . $tool], 404);
    }
}

/**
 * List available public tools
 */
function handleToolsIndex($method) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    sendResponse([
        'name' => 'Cat-WAF Public Tools',
        'version' => '1.5.0',
        'description' => 'Free utilities for web security and content licensing',
        'tools' => [
            [
                'id' => 'rsl-builder',
                'name' => 'RSL Builder',
                'description' => 'Create RSL (Really Simple Licensing) documents for your content',
                'endpoint' => '/cat-waf/rsl-builder',
                'methods' => ['GET', 'POST'],
                'documentation' => 'https://rslstandard.org/spec/1.0/'
            ],
            [
                'id' => 'rsl-validate',
                'name' => 'RSL Validator',
                'description' => 'Validate RSL XML documents against the specification',
                'endpoint' => '/cat-waf/rsl-validate',
                'methods' => ['POST']
            ],
            [
                'id' => 'rsl-presets',
                'name' => 'RSL Presets',
                'description' => 'Common RSL license configurations',
                'endpoint' => '/cat-waf/rsl-presets',
                'methods' => ['GET']
            ],
            [
                'id' => 'robots-txt-generator',
                'name' => 'Robots.txt Generator',
                'description' => 'Generate robots.txt with RSL directives',
                'endpoint' => '/cat-waf/robots-txt-generator',
                'methods' => ['POST']
            ]
        ],
        'rsl_specification' => [
            'version' => '1.0',
            'namespace' => 'https://rslstandard.org/rsl',
            'media_type' => 'application/rsl+xml',
            'documentation' => 'https://rslstandard.org/spec/1.0/'
        ]
    ]);
}

/**
 * RSL Builder - Generate RSL documents
 */
function handleRSLBuilder($method, $params, $db) {
    $action = $params[0] ?? '';
    
    switch ($method) {
        case 'GET':
            if ($action === 'schema') {
                // Return the RSL schema for form building
                sendResponse(getRSLSchema());
            } else {
                // Return builder information
                sendResponse([
                    'name' => 'RSL Builder',
                    'version' => '1.0.0',
                    'description' => 'Create RSL (Really Simple Licensing) documents',
                    'specification' => 'https://rslstandard.org/spec/1.0/',
                    'schema_endpoint' => '/cat-waf/rsl-builder/schema',
                    'presets_endpoint' => '/cat-waf/rsl-presets',
                    'usage' => [
                        'method' => 'POST',
                        'content_type' => 'application/json',
                        'body' => [
                            'content_url' => 'URL of the content being licensed (optional, use * for all)',
                            'license_server' => 'URL of your license server for OLP (optional)',
                            'permits' => [
                                'usage' => ['Array of permitted uses: all, ai-all, ai-train, ai-input, ai-index, search'],
                                'user' => ['Array of permitted users: commercial, non-commercial, education, government, personal'],
                                'geo' => ['Array of ISO 3166-1 alpha-2 country codes']
                            ],
                            'prohibits' => 'Same structure as permits',
                            'payment' => [
                                'type' => 'free|purchase|subscription|training|crawl|use|contribution|attribution',
                                'amount' => 'Numeric amount (optional)',
                                'currency' => 'ISO 4217 currency code (default: USD)',
                                'standard' => 'URL to standard license marketplace',
                                'custom' => 'URL to custom payment endpoint',
                                'accepts' => ['Payment methods: credit-card, crypto, invoice, etc.']
                            ],
                            'legal' => [
                                'terms' => 'URL to terms of service',
                                'warranty' => 'URL to warranty information',
                                'disclaimer' => 'URL to disclaimer',
                                'contact' => 'URL to legal contact',
                                'proof' => 'URL to ownership proof',
                                'attestation' => 'URL to attestation'
                            ],
                            'copyright' => [
                                'holder' => 'Copyright holder name',
                                'year' => 'Copyright year(s)',
                                'license' => 'License name (e.g., CC BY-NC 4.0)'
                            ]
                        ],
                        'response' => 'RSL XML document (application/rsl+xml)'
                    ]
                ]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                sendResponse(['error' => 'Invalid JSON body'], 400);
            }
            
            try {
                $rsl = buildRSLDocument($input);
                
                // Check if JSON output is requested
                $format = $_GET['format'] ?? $input['_format'] ?? 'xml';
                
                if ($format === 'json') {
                    sendResponse([
                        'xml' => $rsl->toXML(),
                        'parsed' => $rsl->toArray(),
                        'discovery' => [
                            'robots_txt' => $rsl->toRobotsTxt($_GET['rsl_url'] ?? '/rsl.xml'),
                            'http_header' => $rsl->toHttpLinkHeader($_GET['rsl_url'] ?? '/rsl.xml'),
                            'html_link' => $rsl->toHtmlLink($_GET['rsl_url'] ?? '/rsl.xml')
                        ]
                    ]);
                } else {
                    header('Content-Type: application/rsl+xml; charset=utf-8');
                    header('Content-Disposition: inline; filename="rsl.xml"');
                    echo $rsl->toXML();
                    exit;
                }
            } catch (\Exception $e) {
                sendResponse(['error' => 'Failed to generate RSL: ' . $e->getMessage()], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * RSL Validate - Validate RSL XML documents
 */
function handleRSLValidate($method, $db) {
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed. Use POST with XML body or JSON {"xml": "..."}'], 405);
    }
    
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/xml') !== false || 
        strpos($contentType, 'application/rsl+xml') !== false ||
        strpos($contentType, 'text/xml') !== false) {
        $xml = file_get_contents('php://input');
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $xml = $input['xml'] ?? null;
    }
    
    if (!$xml) {
        sendResponse(['error' => 'No XML content provided. Send XML directly or as {"xml": "..."}'], 400);
    }
    
    $errors = [];
    $warnings = [];
    
    // Basic XML validation
    libxml_use_internal_errors(true);
    $dom = new \DOMDocument();
    
    if (!$dom->loadXML($xml)) {
        foreach (libxml_get_errors() as $error) {
            $errors[] = [
                'line' => $error->line,
                'column' => $error->column,
                'message' => trim($error->message),
                'level' => $error->level === LIBXML_ERR_ERROR ? 'error' : 'warning'
            ];
        }
        libxml_clear_errors();
        
        sendResponse([
            'valid' => false,
            'errors' => $errors,
            'message' => 'XML parsing failed'
        ], 400);
    }
    
    libxml_clear_errors();
    
    // Check namespace
    $root = $dom->documentElement;
    if ($root->nodeName !== 'rsl') {
        $errors[] = [
            'message' => 'Root element must be <rsl>',
            'found' => $root->nodeName
        ];
    }
    
    $namespace = $root->namespaceURI ?? $root->getAttribute('xmlns');
    if ($namespace !== 'https://rslstandard.org/rsl') {
        $warnings[] = [
            'message' => 'Namespace should be https://rslstandard.org/rsl',
            'found' => $namespace ?: 'none'
        ];
    }
    
    // Check required elements
    if ($dom->getElementsByTagName('content')->length === 0) {
        $warnings[] = ['message' => 'Missing <content> element'];
    }
    
    if ($dom->getElementsByTagName('license')->length === 0) {
        $warnings[] = ['message' => 'Missing <license> element'];
    }
    
    // Validate permits/prohibits types
    $validUsageTypes = ['all', 'ai-all', 'ai-train', 'ai-input', 'ai-index', 'search'];
    $validUserTypes = ['commercial', 'non-commercial', 'education', 'government', 'personal'];
    
    foreach (['permits', 'prohibits'] as $element) {
        foreach ($dom->getElementsByTagName($element) as $node) {
            $type = $node->getAttribute('type') ?: 'usage';
            $values = array_filter(explode(' ', trim($node->textContent)));
            
            if ($type === 'usage') {
                foreach ($values as $value) {
                    if (!in_array($value, $validUsageTypes)) {
                        $warnings[] = [
                            'message' => "Unknown usage type in <$element>: $value",
                            'valid_values' => $validUsageTypes
                        ];
                    }
                }
            } elseif ($type === 'user') {
                foreach ($values as $value) {
                    if (!in_array($value, $validUserTypes)) {
                        $warnings[] = [
                            'message' => "Unknown user type in <$element>: $value",
                            'valid_values' => $validUserTypes
                        ];
                    }
                }
            }
        }
    }
    
    // Try to parse the document
    try {
        $rsl = RSLDocument::fromXML($xml);
        $parsed = $rsl->toArray();
    } catch (\Exception $e) {
        $errors[] = ['message' => 'Failed to parse RSL structure: ' . $e->getMessage()];
        $parsed = null;
    }
    
    $isValid = empty($errors);
    
    sendResponse([
        'valid' => $isValid,
        'errors' => $errors,
        'warnings' => $warnings,
        'parsed' => $parsed,
        'message' => $isValid ? 'RSL document is valid' : 'RSL document has errors'
    ], $isValid ? 200 : 400);
}

/**
 * RSL Presets - Common license configurations
 */
function handleRSLPresets($method) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    sendResponse([
        'presets' => [
            [
                'id' => 'prohibit-ai-training',
                'name' => 'Prohibit AI Training',
                'description' => 'Allow search indexing but prohibit AI training',
                'config' => [
                    'permits' => ['usage' => ['search', 'ai-index']],
                    'prohibits' => ['usage' => ['ai-train']],
                    'payment' => ['type' => 'free']
                ]
            ],
            [
                'id' => 'require-license',
                'name' => 'Require License',
                'description' => 'Require a license for any AI use',
                'config' => [
                    'permits' => ['usage' => ['search']],
                    'prohibits' => ['usage' => ['ai-all']],
                    'payment' => ['type' => 'purchase']
                ]
            ],
            [
                'id' => 'pay-per-crawl',
                'name' => 'Pay Per Crawl',
                'description' => 'Charge for crawling/scraping content',
                'config' => [
                    'permits' => ['usage' => ['search']],
                    'payment' => [
                        'type' => 'crawl',
                        'amount' => 0.001,
                        'currency' => 'USD'
                    ]
                ]
            ],
            [
                'id' => 'attribution-only',
                'name' => 'Attribution Only',
                'description' => 'Allow all uses with attribution requirement',
                'config' => [
                    'permits' => ['usage' => ['all']],
                    'payment' => ['type' => 'attribution']
                ]
            ],
            [
                'id' => 'non-commercial',
                'name' => 'Non-Commercial Only',
                'description' => 'Allow non-commercial use only',
                'config' => [
                    'permits' => [
                        'usage' => ['all'],
                        'user' => ['non-commercial', 'education', 'personal']
                    ],
                    'prohibits' => ['user' => ['commercial']],
                    'payment' => ['type' => 'free']
                ]
            ],
            [
                'id' => 'geo-restricted',
                'name' => 'Geographic Restriction (EU)',
                'description' => 'Restrict to European Union countries',
                'config' => [
                    'permits' => [
                        'usage' => ['all'],
                        'geo' => ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE']
                    ],
                    'payment' => ['type' => 'free']
                ]
            ],
            [
                'id' => 'subscription',
                'name' => 'Subscription Model',
                'description' => 'Require subscription for access',
                'config' => [
                    'permits' => ['usage' => ['ai-all', 'search']],
                    'payment' => [
                        'type' => 'subscription',
                        'amount' => 99.00,
                        'currency' => 'USD'
                    ]
                ]
            ],
            [
                'id' => 'all-rights-reserved',
                'name' => 'All Rights Reserved',
                'description' => 'Prohibit all automated access',
                'config' => [
                    'prohibits' => ['usage' => ['all']],
                    'payment' => ['type' => 'purchase']
                ]
            ]
        ]
    ]);
}

/**
 * Robots.txt Generator with RSL
 */
function handleRobotsTxtGenerator($method) {
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendResponse(['error' => 'Invalid JSON body'], 400);
    }
    
    $lines = [];
    
    // Add RSL License directive
    $rslUrl = $input['rsl_url'] ?? '/rsl.xml';
    $lines[] = "# RSL License - Really Simple Licensing";
    $lines[] = "License: $rslUrl";
    $lines[] = "";
    
    // Default user agent rules
    $rules = $input['rules'] ?? [];
    
    if (empty($rules)) {
        // Add sensible defaults based on RSL config
        $lines[] = "# Default rules";
        $lines[] = "User-agent: *";
        $lines[] = "Allow: /";
        
        // Add AI bot restrictions if prohibiting AI
        $prohibitAI = false;
        if (!empty($input['rsl_config']['prohibits']['usage'])) {
            $prohibits = $input['rsl_config']['prohibits']['usage'];
            if (in_array('ai-train', $prohibits) || in_array('ai-all', $prohibits)) {
                $prohibitAI = true;
            }
        }
        
        if ($prohibitAI) {
            $lines[] = "";
            $lines[] = "# AI Training Bots (Prohibited by RSL)";
            $aiBots = [
                'GPTBot', 'ChatGPT-User', 'Google-Extended', 'CCBot', 
                'anthropic-ai', 'Claude-Web', 'cohere-ai', 'PerplexityBot',
                'Bytespider', 'PetalBot', 'Amazonbot', 'FacebookBot'
            ];
            foreach ($aiBots as $bot) {
                $lines[] = "User-agent: $bot";
                $lines[] = "Disallow: /";
                $lines[] = "";
            }
        }
    } else {
        // Use provided rules
        foreach ($rules as $rule) {
            if (isset($rule['user_agent'])) {
                $lines[] = "User-agent: " . $rule['user_agent'];
            }
            if (isset($rule['allow'])) {
                foreach ((array)$rule['allow'] as $path) {
                    $lines[] = "Allow: $path";
                }
            }
            if (isset($rule['disallow'])) {
                foreach ((array)$rule['disallow'] as $path) {
                    $lines[] = "Disallow: $path";
                }
            }
            $lines[] = "";
        }
    }
    
    // Add sitemap if provided
    if (!empty($input['sitemap'])) {
        $lines[] = "Sitemap: " . $input['sitemap'];
    }
    
    $robotsTxt = implode("\n", $lines);
    
    if (($_GET['format'] ?? '') === 'text') {
        header('Content-Type: text/plain');
        echo $robotsTxt;
        exit;
    }
    
    sendResponse([
        'robots_txt' => $robotsTxt,
        'rsl_directive' => "License: $rslUrl"
    ]);
}

/**
 * Fetch RSL from a URL and parse it for pre-population
 */
function handleRSLFetch($method) {
    if ($method !== 'GET') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $url = $_GET['url'] ?? null;
    if (!$url) {
        sendResponse(['error' => 'URL parameter required'], 400);
    }
    
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        sendResponse(['error' => 'Invalid URL'], 400);
    }
    
    // Fetch with timeout
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Cat-WAF RSL Builder/1.0',
            'follow_location' => true,
            'max_redirects' => 3
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $xml = @file_get_contents($url, false, $ctx);
    
    if ($xml === false) {
        sendResponse(['success' => false, 'error' => 'Failed to fetch URL'], 404);
    }
    
    // Try to parse as RSL
    try {
        $config = parseRSLToConfig($xml);
        sendResponse(['success' => true, 'config' => $config, 'source_url' => $url]);
    } catch (\Exception $e) {
        sendResponse(['success' => false, 'error' => 'Failed to parse RSL: ' . $e->getMessage()], 400);
    }
}

/**
 * Parse RSL XML into config format for the builder
 */
function parseRSLToConfig(string $xml): array {
    $dom = new \DOMDocument();
    if (!@$dom->loadXML($xml)) {
        throw new \Exception('Invalid XML');
    }
    
    $config = [
        'contents' => [],
        'payment' => ['type' => 'free'],
        'legal' => [],
        'copyright' => []
    ];
    
    // Parse content elements
    $contents = $dom->getElementsByTagName('content');
    foreach ($contents as $content) {
        $contentConfig = [
            'url' => $content->getAttribute('url') ?: '/*',
            'permits' => ['usage' => [], 'user' => []],
            'prohibits' => ['usage' => [], 'user' => []]
        ];
        
        // Check for license server
        if ($content->getAttribute('server')) {
            $config['license_server'] = $content->getAttribute('server');
        }
        if ($content->getAttribute('encrypted') === 'true') {
            $config['encrypted'] = true;
        }
        
        // Parse permits within content
        foreach ($content->getElementsByTagName('permits') as $permit) {
            $type = $permit->getAttribute('type') ?: 'usage';
            $values = array_filter(explode(' ', trim($permit->textContent)));
            if ($type === 'usage' || $type === 'user') {
                $contentConfig['permits'][$type] = array_merge($contentConfig['permits'][$type], $values);
            }
        }
        
        // Parse prohibits within content
        foreach ($content->getElementsByTagName('prohibits') as $prohibit) {
            $type = $prohibit->getAttribute('type') ?: 'usage';
            $values = array_filter(explode(' ', trim($prohibit->textContent)));
            if ($type === 'usage' || $type === 'user') {
                $contentConfig['prohibits'][$type] = array_merge($contentConfig['prohibits'][$type], $values);
            }
        }
        
        $config['contents'][] = $contentConfig;
    }
    
    // If no content elements, check for global license
    if (empty($config['contents'])) {
        $license = $dom->getElementsByTagName('license')->item(0);
        if ($license) {
            $globalConfig = [
                'url' => '/*',
                'permits' => ['usage' => [], 'user' => []],
                'prohibits' => ['usage' => [], 'user' => []]
            ];
            
            foreach ($license->getElementsByTagName('permits') as $permit) {
                $type = $permit->getAttribute('type') ?: 'usage';
                $values = array_filter(explode(' ', trim($permit->textContent)));
                if ($type === 'usage' || $type === 'user') {
                    $globalConfig['permits'][$type] = array_merge($globalConfig['permits'][$type], $values);
                }
            }
            
            foreach ($license->getElementsByTagName('prohibits') as $prohibit) {
                $type = $prohibit->getAttribute('type') ?: 'usage';
                $values = array_filter(explode(' ', trim($prohibit->textContent)));
                if ($type === 'usage' || $type === 'user') {
                    $globalConfig['prohibits'][$type] = array_merge($globalConfig['prohibits'][$type], $values);
                }
            }
            
            $config['contents'][] = $globalConfig;
        }
    }
    
    // Parse payment
    $payment = $dom->getElementsByTagName('payment')->item(0);
    if ($payment) {
        $config['payment']['type'] = $payment->getAttribute('type') ?: 'free';
        
        $amount = $payment->getElementsByTagName('amount')->item(0);
        if ($amount) {
            $config['payment']['amount'] = (float)$amount->textContent;
            $config['payment']['currency'] = $amount->getAttribute('currency') ?: 'USD';
        }
        
        $standard = $payment->getElementsByTagName('standard')->item(0);
        if ($standard) {
            $config['payment']['standard'] = $standard->textContent;
        }
        
        $custom = $payment->getElementsByTagName('custom')->item(0);
        if ($custom) {
            $config['payment']['custom'] = $custom->textContent;
        }
    }
    
    // Parse legal
    foreach ($dom->getElementsByTagName('legal') as $legal) {
        $type = $legal->getAttribute('type');
        if ($type) {
            $config['legal'][$type] = $legal->textContent;
        }
    }
    
    // Parse copyright
    $copyright = $dom->getElementsByTagName('copyright')->item(0);
    if ($copyright) {
        if ($copyright->getAttribute('holder')) {
            $config['copyright']['holder'] = $copyright->getAttribute('holder');
        }
        if ($copyright->getAttribute('year')) {
            $config['copyright']['year'] = $copyright->getAttribute('year');
        }
        if ($copyright->getAttribute('license')) {
            $config['copyright']['license'] = $copyright->getAttribute('license');
        }
    }
    
    return $config;
}

/**
 * Build RSL document from input
 */
function buildRSLDocument(array $input): RSLDocument {
    $rsl = new RSLDocument($input['content_url'] ?? null);
    
    // Multi-path content support
    if (!empty($input['contents']) && is_array($input['contents'])) {
        foreach ($input['contents'] as $content) {
            $url = $content['url'] ?? '/*';
            $permits = [];
            $prohibits = [];
            
            // Build permits array
            if (!empty($content['permits'])) {
                if (!empty($content['permits']['usage'])) {
                    $permits['usage'] = (array)$content['permits']['usage'];
                }
                if (!empty($content['permits']['user'])) {
                    $permits['user'] = (array)$content['permits']['user'];
                }
            }
            
            // Build prohibits array
            if (!empty($content['prohibits'])) {
                if (!empty($content['prohibits']['usage'])) {
                    $prohibits['usage'] = (array)$content['prohibits']['usage'];
                }
                if (!empty($content['prohibits']['user'])) {
                    $prohibits['user'] = (array)$content['prohibits']['user'];
                }
            }
            
            $rsl->addContent($url, $permits, $prohibits);
        }
    }
    
    if (!empty($input['license_server'])) {
        $rsl->setLicenseServer($input['license_server']);
    }
    
    if (!empty($input['encrypted'])) {
        $rsl->setEncrypted(true);
    }
    
    // Add permits (for single-path legacy support)
    if (!empty($input['permits']) && empty($input['contents'])) {
        if (isset($input['permits']['usage'])) {
            $rsl->permitUsage((array)$input['permits']['usage']);
        }
        if (isset($input['permits']['user'])) {
            $rsl->permitUser((array)$input['permits']['user']);
        }
        if (isset($input['permits']['geo'])) {
            $rsl->permitGeo((array)$input['permits']['geo']);
        }
    }
    
    // Add prohibits (for single-path legacy support)
    if (!empty($input['prohibits']) && empty($input['contents'])) {
        if (isset($input['prohibits']['usage'])) {
            $rsl->prohibitUsage((array)$input['prohibits']['usage']);
        }
        if (isset($input['prohibits']['user'])) {
            $rsl->prohibitUser((array)$input['prohibits']['user']);
        }
        if (isset($input['prohibits']['geo'])) {
            $rsl->prohibitGeo((array)$input['prohibits']['geo']);
        }
    }
    
    // Set payment (global for all paths)
    if (!empty($input['payment'])) {
        $payment = $input['payment'];
        $rsl->setPayment(
            $payment['type'] ?? 'free',
            $payment['amount'] ?? null,
            $payment['currency'] ?? 'USD'
        );
        
        if (!empty($payment['standard'])) {
            $rsl->setPaymentStandard($payment['standard']);
        }
        if (!empty($payment['custom'])) {
            $rsl->setPaymentCustom($payment['custom']);
        }
        if (!empty($payment['accepts'])) {
            $rsl->setPaymentAccepts((array)$payment['accepts']);
        }
    }
    
    // Set legal references
    if (!empty($input['legal'])) {
        foreach ($input['legal'] as $type => $url) {
            if (!empty($url)) {
                $rsl->addLegal($type, $url);
            }
        }
    }
    
    // Set copyright
    if (!empty($input['copyright'])) {
        $rsl->setCopyright(
            $input['copyright']['holder'] ?? null,
            $input['copyright']['year'] ?? null,
            $input['copyright']['license'] ?? null
        );
    }
    
    // Set terms
    if (!empty($input['terms'])) {
        $rsl->setTerms($input['terms']);
    }
    
    return $rsl;
}

/**
 * Get RSL schema for form building
 */
function getRSLSchema(): array {
    return [
        'usage_types' => [
            ['value' => 'all', 'label' => 'All Uses', 'description' => 'Permits/prohibits all use types'],
            ['value' => 'ai-all', 'label' => 'All AI Uses', 'description' => 'All AI-related uses'],
            ['value' => 'ai-train', 'label' => 'AI Training', 'description' => 'Training AI/ML models'],
            ['value' => 'ai-input', 'label' => 'AI Input', 'description' => 'Using content as AI input'],
            ['value' => 'ai-index', 'label' => 'AI Indexing', 'description' => 'Indexing for AI retrieval'],
            ['value' => 'search', 'label' => 'Search Indexing', 'description' => 'Traditional search engine indexing']
        ],
        'user_types' => [
            ['value' => 'commercial', 'label' => 'Commercial', 'description' => 'For-profit commercial use'],
            ['value' => 'non-commercial', 'label' => 'Non-Commercial', 'description' => 'Non-profit use'],
            ['value' => 'education', 'label' => 'Educational', 'description' => 'Educational institutions'],
            ['value' => 'government', 'label' => 'Government', 'description' => 'Government entities'],
            ['value' => 'personal', 'label' => 'Personal', 'description' => 'Individual personal use']
        ],
        'payment_types' => [
            ['value' => 'free', 'label' => 'Free', 'description' => 'No payment required'],
            ['value' => 'purchase', 'label' => 'One-time Purchase', 'description' => 'Single payment for access'],
            ['value' => 'subscription', 'label' => 'Subscription', 'description' => 'Recurring payment'],
            ['value' => 'training', 'label' => 'Training Fee', 'description' => 'Fee for AI training use'],
            ['value' => 'crawl', 'label' => 'Per-Crawl', 'description' => 'Pay per crawl/request'],
            ['value' => 'use', 'label' => 'Per-Use', 'description' => 'Pay per content use'],
            ['value' => 'contribution', 'label' => 'Contribution', 'description' => 'Voluntary contribution'],
            ['value' => 'attribution', 'label' => 'Attribution', 'description' => 'Attribution required']
        ],
        'legal_types' => [
            ['value' => 'terms', 'label' => 'Terms of Service', 'description' => 'URL to terms'],
            ['value' => 'warranty', 'label' => 'Warranty', 'description' => 'Warranty information'],
            ['value' => 'disclaimer', 'label' => 'Disclaimer', 'description' => 'Legal disclaimer'],
            ['value' => 'contact', 'label' => 'Legal Contact', 'description' => 'Contact for legal inquiries'],
            ['value' => 'proof', 'label' => 'Ownership Proof', 'description' => 'Proof of content ownership'],
            ['value' => 'attestation', 'label' => 'Attestation', 'description' => 'Publisher attestation']
        ],
        'currencies' => ['USD', 'EUR', 'GBP', 'JPY', 'CNY', 'BTC', 'ETH'],
        'payment_methods' => ['credit-card', 'crypto', 'invoice', 'paypal', 'bank-transfer']
    ];
}
