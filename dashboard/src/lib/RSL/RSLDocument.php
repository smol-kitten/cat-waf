<?php
/**
 * RSL (Really Simple Licensing) PHP Library
 * Implements the RSL 1.0 Specification
 * https://rslstandard.org/spec/1.0/
 */

namespace CatWAF\RSL;

class RSLDocument {
    private const RSL_NAMESPACE = 'https://rslstandard.org/rsl';
    private const RSL_VERSION = '1.0';
    
    private ?string $contentUrl = null;
    private ?string $licenseServer = null;
    private bool $encrypted = false;
    private ?string $lastmod = null;
    
    // Multi-path content support
    private array $contents = [];
    
    private array $permits = [];
    private array $prohibits = [];
    
    private ?string $paymentType = null;
    private ?string $paymentStandard = null;
    private ?string $paymentCustom = null;
    private ?float $paymentAmount = null;
    private ?string $paymentCurrency = null;
    private array $paymentAccepts = [];
    
    private array $legal = [];
    
    private ?string $copyrightHolder = null;
    private ?string $copyrightYear = null;
    private ?string $copyrightLicense = null;
    private ?string $terms = null;
    
    private ?string $schemaUrl = null;
    private ?string $alternateFormat = null;
    
    /**
     * Create a new RSL document
     */
    public function __construct(?string $contentUrl = null) {
        $this->contentUrl = $contentUrl;
    }
    
    /**
     * Add a content path with its own permissions
     * @param string $url URL pattern (e.g., /*, /blog/*)
     * @param array $permits Array with 'usage' and 'user' keys
     * @param array $prohibits Array with 'usage' and 'user' keys
     */
    public function addContent(string $url, array $permits = [], array $prohibits = []): self {
        $this->contents[] = [
            'url' => $url,
            'permits' => $permits,
            'prohibits' => $prohibits
        ];
        return $this;
    }
    
    /**
     * Check if document has multiple content paths
     */
    public function hasMultipleContents(): bool {
        return count($this->contents) > 0;
    }
    
    /**
     * Get all content paths
     */
    public function getContents(): array {
        return $this->contents;
    }
    
    /**
     * Set content URL
     */
    public function setContentUrl(string $url): self {
        $this->contentUrl = $url;
        return $this;
    }
    
    /**
     * Set license server URL
     */
    public function setLicenseServer(string $server): self {
        $this->licenseServer = $server;
        return $this;
    }
    
    /**
     * Set content as encrypted
     */
    public function setEncrypted(bool $encrypted = true): self {
        $this->encrypted = $encrypted;
        return $this;
    }
    
    /**
     * Set last modified timestamp
     */
    public function setLastmod(string $lastmod): self {
        $this->lastmod = $lastmod;
        return $this;
    }
    
    /**
     * Add permitted usage
     * @param string $type usage|user|geo
     * @param array $values Array of permitted values
     */
    public function addPermit(string $type, array $values): self {
        $this->permits[] = ['type' => $type, 'values' => $values];
        return $this;
    }
    
    /**
     * Add permitted usage type (convenience method)
     */
    public function permitUsage(array $usages): self {
        return $this->addPermit('usage', $usages);
    }
    
    /**
     * Add permitted user types
     */
    public function permitUser(array $users): self {
        return $this->addPermit('user', $users);
    }
    
    /**
     * Add permitted geographic regions
     */
    public function permitGeo(array $regions): self {
        return $this->addPermit('geo', $regions);
    }
    
    /**
     * Add prohibited usage
     * @param string $type usage|user|geo
     * @param array $values Array of prohibited values
     */
    public function addProhibit(string $type, array $values): self {
        $this->prohibits[] = ['type' => $type, 'values' => $values];
        return $this;
    }
    
    /**
     * Add prohibited usage type
     */
    public function prohibitUsage(array $usages): self {
        return $this->addProhibit('usage', $usages);
    }
    
    /**
     * Add prohibited user types
     */
    public function prohibitUser(array $users): self {
        return $this->addProhibit('user', $users);
    }
    
    /**
     * Add prohibited geographic regions
     */
    public function prohibitGeo(array $regions): self {
        return $this->addProhibit('geo', $regions);
    }
    
    /**
     * Set payment configuration
     * @param string $type free|purchase|subscription|training|crawl|use|contribution|attribution
     */
    public function setPayment(string $type, ?float $amount = null, ?string $currency = 'USD'): self {
        $this->paymentType = $type;
        $this->paymentAmount = $amount;
        $this->paymentCurrency = $currency;
        return $this;
    }
    
    /**
     * Set standard payment URL (existing license marketplace)
     */
    public function setPaymentStandard(string $url): self {
        $this->paymentStandard = $url;
        return $this;
    }
    
    /**
     * Set custom payment URL
     */
    public function setPaymentCustom(string $url): self {
        $this->paymentCustom = $url;
        return $this;
    }
    
    /**
     * Set accepted payment methods
     */
    public function setPaymentAccepts(array $methods): self {
        $this->paymentAccepts = $methods;
        return $this;
    }
    
    /**
     * Add legal reference
     * @param string $type warranty|disclaimer|attestation|contact|proof
     */
    public function addLegal(string $type, string $url): self {
        $this->legal[$type] = $url;
        return $this;
    }
    
    /**
     * Set copyright information
     */
    public function setCopyright(?string $holder = null, ?string $year = null, ?string $license = null): self {
        $this->copyrightHolder = $holder;
        $this->copyrightYear = $year;
        $this->copyrightLicense = $license;
        return $this;
    }
    
    /**
     * Set terms URL
     */
    public function setTerms(string $url): self {
        $this->terms = $url;
        return $this;
    }
    
    /**
     * Set custom schema URL
     */
    public function setSchema(string $url): self {
        $this->schemaUrl = $url;
        return $this;
    }
    
    /**
     * Set alternate format URL
     */
    public function setAlternate(string $url): self {
        $this->alternateFormat = $url;
        return $this;
    }
    
    /**
     * Generate XML document
     */
    public function toXML(): string {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        
        // Root element
        $rsl = $dom->createElementNS(self::RSL_NAMESPACE, 'rsl');
        $dom->appendChild($rsl);
        
        // Multi-path content support
        if (count($this->contents) > 0) {
            foreach ($this->contents as $contentDef) {
                $content = $dom->createElement('content');
                $content->setAttribute('url', $contentDef['url']);
                
                if ($this->licenseServer) {
                    $content->setAttribute('server', $this->licenseServer);
                }
                if ($this->encrypted) {
                    $content->setAttribute('encrypted', 'true');
                }
                
                // Per-content permits
                if (!empty($contentDef['permits'])) {
                    foreach ($contentDef['permits'] as $type => $values) {
                        if (!empty($values)) {
                            $permitEl = $dom->createElement('permits');
                            $permitEl->setAttribute('type', $type);
                            $permitEl->textContent = implode(' ', (array)$values);
                            $content->appendChild($permitEl);
                        }
                    }
                }
                
                // Per-content prohibits
                if (!empty($contentDef['prohibits'])) {
                    foreach ($contentDef['prohibits'] as $type => $values) {
                        if (!empty($values)) {
                            $prohibitEl = $dom->createElement('prohibits');
                            $prohibitEl->setAttribute('type', $type);
                            $prohibitEl->textContent = implode(' ', (array)$values);
                            $content->appendChild($prohibitEl);
                        }
                    }
                }
                
                $rsl->appendChild($content);
            }
        } else {
            // Single content element (legacy support)
            $content = $dom->createElement('content');
            if ($this->contentUrl) {
                $content->setAttribute('url', $this->contentUrl);
            }
            if ($this->licenseServer) {
                $content->setAttribute('server', $this->licenseServer);
            }
            if ($this->encrypted) {
                $content->setAttribute('encrypted', 'true');
            }
            if ($this->lastmod) {
                $content->setAttribute('lastmod', $this->lastmod);
            }
            $rsl->appendChild($content);
        }
        
        // License element (for global permissions when not using multi-path)
        if (count($this->contents) === 0) {
            $license = $dom->createElement('license');
            
            // Permits
            foreach ($this->permits as $permit) {
                $permitEl = $dom->createElement('permits');
                $permitEl->setAttribute('type', $permit['type']);
                $permitEl->textContent = implode(' ', $permit['values']);
                $license->appendChild($permitEl);
            }
            
            // Prohibits
            foreach ($this->prohibits as $prohibit) {
                $prohibitEl = $dom->createElement('prohibits');
                $prohibitEl->setAttribute('type', $prohibit['type']);
                $prohibitEl->textContent = implode(' ', $prohibit['values']);
                $license->appendChild($prohibitEl);
            }
            
            // Payment
            if ($this->paymentType) {
                $payment = $dom->createElement('payment');
                $payment->setAttribute('type', $this->paymentType);
                
                if ($this->paymentStandard) {
                    $standard = $dom->createElement('standard', $this->paymentStandard);
                    $payment->appendChild($standard);
                }
                
                if ($this->paymentCustom) {
                    $custom = $dom->createElement('custom', $this->paymentCustom);
                    $payment->appendChild($custom);
                }
                
                if ($this->paymentAmount !== null) {
                    $amount = $dom->createElement('amount', number_format($this->paymentAmount, 2, '.', ''));
                    if ($this->paymentCurrency) {
                        $amount->setAttribute('currency', $this->paymentCurrency);
                    }
                    $payment->appendChild($amount);
                }
                
                foreach ($this->paymentAccepts as $method) {
                    $accepts = $dom->createElement('accepts');
                    $accepts->setAttribute('type', $method);
                    $payment->appendChild($accepts);
                }
                
                $license->appendChild($payment);
            }
            
            // Legal
            foreach ($this->legal as $type => $url) {
                $legalEl = $dom->createElement('legal', $url);
                $legalEl->setAttribute('type', $type);
                $license->appendChild($legalEl);
            }
            
            $rsl->appendChild($license);
        }
        
        // Global payment element (for multi-path)
        if (count($this->contents) > 0 && $this->paymentType) {
            $payment = $dom->createElement('payment');
            $payment->setAttribute('type', $this->paymentType);
            
            if ($this->paymentStandard) {
                $standard = $dom->createElement('standard', $this->paymentStandard);
                $payment->appendChild($standard);
            }
            
            if ($this->paymentCustom) {
                $custom = $dom->createElement('custom', $this->paymentCustom);
                $payment->appendChild($custom);
            }
            
            if ($this->paymentAmount !== null) {
                $amount = $dom->createElement('amount', number_format($this->paymentAmount, 2, '.', ''));
                if ($this->paymentCurrency) {
                    $amount->setAttribute('currency', $this->paymentCurrency);
                }
                $payment->appendChild($amount);
            }
            
            $rsl->appendChild($payment);
        }
        
        // Global legal (for multi-path)
        if (count($this->contents) > 0 && count($this->legal) > 0) {
            foreach ($this->legal as $type => $url) {
                $legalEl = $dom->createElement('legal', $url);
                $legalEl->setAttribute('type', $type);
                $rsl->appendChild($legalEl);
            }
        }
        
        // Schema
        if ($this->schemaUrl) {
            $schema = $dom->createElement('schema', $this->schemaUrl);
            $rsl->appendChild($schema);
        }
        
        // Alternate
        if ($this->alternateFormat) {
            $alternate = $dom->createElement('alternate', $this->alternateFormat);
            $rsl->appendChild($alternate);
        }
        
        // Copyright
        if ($this->copyrightHolder || $this->copyrightYear || $this->copyrightLicense) {
            $copyright = $dom->createElement('copyright');
            if ($this->copyrightHolder) {
                $copyright->setAttribute('holder', $this->copyrightHolder);
            }
            if ($this->copyrightYear) {
                $copyright->setAttribute('year', $this->copyrightYear);
            }
            if ($this->copyrightLicense) {
                $copyright->setAttribute('license', $this->copyrightLicense);
            }
            $rsl->appendChild($copyright);
        }
        
        // Terms
        if ($this->terms) {
            $termsEl = $dom->createElement('terms', $this->terms);
            $rsl->appendChild($termsEl);
        }
        
        return $dom->saveXML();
    }
    
    /**
     * Parse RSL document from XML string
     */
    public static function fromXML(string $xml): self {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        
        $rsl = new self();
        
        // Parse content element
        $contentNodes = $dom->getElementsByTagName('content');
        if ($contentNodes->length > 0) {
            $content = $contentNodes->item(0);
            if ($content->hasAttribute('url')) {
                $rsl->contentUrl = $content->getAttribute('url');
            }
            if ($content->hasAttribute('server')) {
                $rsl->licenseServer = $content->getAttribute('server');
            }
            if ($content->hasAttribute('encrypted')) {
                $rsl->encrypted = $content->getAttribute('encrypted') === 'true';
            }
            if ($content->hasAttribute('lastmod')) {
                $rsl->lastmod = $content->getAttribute('lastmod');
            }
        }
        
        // Parse license element
        $licenseNodes = $dom->getElementsByTagName('license');
        if ($licenseNodes->length > 0) {
            $license = $licenseNodes->item(0);
            
            // Parse permits
            foreach ($license->getElementsByTagName('permits') as $permit) {
                $type = $permit->getAttribute('type') ?: 'usage';
                $values = array_filter(explode(' ', trim($permit->textContent)));
                if (!empty($values)) {
                    $rsl->permits[] = ['type' => $type, 'values' => $values];
                }
            }
            
            // Parse prohibits
            foreach ($license->getElementsByTagName('prohibits') as $prohibit) {
                $type = $prohibit->getAttribute('type') ?: 'usage';
                $values = array_filter(explode(' ', trim($prohibit->textContent)));
                if (!empty($values)) {
                    $rsl->prohibits[] = ['type' => $type, 'values' => $values];
                }
            }
            
            // Parse payment
            $paymentNodes = $license->getElementsByTagName('payment');
            if ($paymentNodes->length > 0) {
                $payment = $paymentNodes->item(0);
                $rsl->paymentType = $payment->getAttribute('type') ?: 'free';
                
                foreach ($payment->childNodes as $child) {
                    if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                    
                    switch ($child->nodeName) {
                        case 'standard':
                            $rsl->paymentStandard = $child->textContent;
                            break;
                        case 'custom':
                            $rsl->paymentCustom = $child->textContent;
                            break;
                        case 'amount':
                            $rsl->paymentAmount = (float)$child->textContent;
                            $rsl->paymentCurrency = $child->getAttribute('currency') ?: 'USD';
                            break;
                        case 'accepts':
                            $rsl->paymentAccepts[] = $child->getAttribute('type');
                            break;
                    }
                }
            }
            
            // Parse legal
            foreach ($license->getElementsByTagName('legal') as $legal) {
                $type = $legal->getAttribute('type');
                if ($type) {
                    $rsl->legal[$type] = $legal->textContent;
                }
            }
        }
        
        // Parse copyright
        $copyrightNodes = $dom->getElementsByTagName('copyright');
        if ($copyrightNodes->length > 0) {
            $copyright = $copyrightNodes->item(0);
            $rsl->copyrightHolder = $copyright->getAttribute('holder') ?: null;
            $rsl->copyrightYear = $copyright->getAttribute('year') ?: null;
            $rsl->copyrightLicense = $copyright->getAttribute('license') ?: null;
        }
        
        // Parse terms
        $termsNodes = $dom->getElementsByTagName('terms');
        if ($termsNodes->length > 0) {
            $rsl->terms = $termsNodes->item(0)->textContent;
        }
        
        // Parse schema
        $schemaNodes = $dom->getElementsByTagName('schema');
        if ($schemaNodes->length > 0) {
            $rsl->schemaUrl = $schemaNodes->item(0)->textContent;
        }
        
        // Parse alternate
        $alternateNodes = $dom->getElementsByTagName('alternate');
        if ($alternateNodes->length > 0) {
            $rsl->alternateFormat = $alternateNodes->item(0)->textContent;
        }
        
        return $rsl;
    }
    
    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array {
        return [
            'content' => [
                'url' => $this->contentUrl,
                'server' => $this->licenseServer,
                'encrypted' => $this->encrypted,
                'lastmod' => $this->lastmod,
            ],
            'license' => [
                'permits' => $this->permits,
                'prohibits' => $this->prohibits,
                'payment' => $this->paymentType ? [
                    'type' => $this->paymentType,
                    'standard' => $this->paymentStandard,
                    'custom' => $this->paymentCustom,
                    'amount' => $this->paymentAmount,
                    'currency' => $this->paymentCurrency,
                    'accepts' => $this->paymentAccepts,
                ] : null,
                'legal' => $this->legal,
            ],
            'copyright' => [
                'holder' => $this->copyrightHolder,
                'year' => $this->copyrightYear,
                'license' => $this->copyrightLicense,
            ],
            'terms' => $this->terms,
            'schema' => $this->schemaUrl,
            'alternate' => $this->alternateFormat,
        ];
    }
    
    /**
     * Create from array (reverse of toArray)
     */
    public static function fromArray(array $data): self {
        $rsl = new self();
        
        if (isset($data['content'])) {
            $rsl->contentUrl = $data['content']['url'] ?? null;
            $rsl->licenseServer = $data['content']['server'] ?? null;
            $rsl->encrypted = $data['content']['encrypted'] ?? false;
            $rsl->lastmod = $data['content']['lastmod'] ?? null;
        }
        
        if (isset($data['license'])) {
            $rsl->permits = $data['license']['permits'] ?? [];
            $rsl->prohibits = $data['license']['prohibits'] ?? [];
            
            if (isset($data['license']['payment'])) {
                $payment = $data['license']['payment'];
                $rsl->paymentType = $payment['type'] ?? null;
                $rsl->paymentStandard = $payment['standard'] ?? null;
                $rsl->paymentCustom = $payment['custom'] ?? null;
                $rsl->paymentAmount = $payment['amount'] ?? null;
                $rsl->paymentCurrency = $payment['currency'] ?? 'USD';
                $rsl->paymentAccepts = $payment['accepts'] ?? [];
            }
            
            $rsl->legal = $data['license']['legal'] ?? [];
        }
        
        if (isset($data['copyright'])) {
            $rsl->copyrightHolder = $data['copyright']['holder'] ?? null;
            $rsl->copyrightYear = $data['copyright']['year'] ?? null;
            $rsl->copyrightLicense = $data['copyright']['license'] ?? null;
        }
        
        $rsl->terms = $data['terms'] ?? null;
        $rsl->schemaUrl = $data['schema'] ?? null;
        $rsl->alternateFormat = $data['alternate'] ?? null;
        
        return $rsl;
    }
    
    /**
     * Get media type for RSL documents
     */
    public static function getMediaType(): string {
        return 'application/rsl+xml';
    }
    
    /**
     * Generate robots.txt License directive
     */
    public function toRobotsTxt(string $rslUrl): string {
        return "License: $rslUrl";
    }
    
    /**
     * Generate HTTP Link header value
     */
    public function toHttpLinkHeader(string $rslUrl): string {
        return "<$rslUrl>; rel=\"license\"; type=\"application/rsl+xml\"";
    }
    
    /**
     * Generate HTML <link> element
     */
    public function toHtmlLink(string $rslUrl): string {
        return '<link rel="license" type="application/rsl+xml" href="' . htmlspecialchars($rslUrl) . '">';
    }
    
    // Getters
    public function getContentUrl(): ?string { return $this->contentUrl; }
    public function getLicenseServer(): ?string { return $this->licenseServer; }
    public function isEncrypted(): bool { return $this->encrypted; }
    public function getPermits(): array { return $this->permits; }
    public function getProhibits(): array { return $this->prohibits; }
    public function getPaymentType(): ?string { return $this->paymentType; }
    public function getPaymentAmount(): ?float { return $this->paymentAmount; }
    public function getPaymentCurrency(): ?string { return $this->paymentCurrency; }
    public function getLegal(): array { return $this->legal; }
    public function getCopyrightHolder(): ?string { return $this->copyrightHolder; }
    public function getTerms(): ?string { return $this->terms; }
}
