<?php
declare(strict_types=1);

/**
 * Central fleet bridge, phase 1: read-only inventory and diagnostics.
 * No secrets or credentials are stored here. This module must be called only
 * after the existing MCP OAuth authorization succeeds.
 *
 * Integrate with the existing paired-site registry and authenticated bridge
 * transport via the two injected callbacks. Do NOT expose as a public route.
 */
final class OdladeniFleetReadOnly
{
    private $listPairedSites;
    private $authenticatedSiteRequest;

    public function __construct(callable $listPairedSites, callable $authenticatedSiteRequest)
    {
        $this->listPairedSites = $listPairedSites;
        $this->authenticatedSiteRequest = $authenticatedSiteRequest;
    }

    /** Canonical registry is the ONLY source of selectable targets. */
    private function registry(): array
    {
        $sites = ($this->listPairedSites)();
        if (!is_array($sites)) {
            throw new RuntimeException('Paired-site registry unavailable');
        }
        $result = [];
        foreach ($sites as $site) {
            if (!is_array($site)) continue;
            $url = (string)($site['url'] ?? '');
            $parts = parse_url($url);
            if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['fragment']) || isset($parts['query'])) continue;
            $canonical = 'https://' . strtolower((string)$parts['host']);
            if (isset($parts['port'])) $canonical .= ':' . (int)$parts['port'];
            $path = rtrim((string)($parts['path'] ?? ''), '/');
            $canonical .= $path;
            $result[$canonical] = [
                'url' => $canonical,
                'name' => (string)($site['name'] ?? $canonical),
                'paired' => true
            ];
        }
        return $result;
    }

    public function sites(): array
    {
        return ['count' => count($this->registry()), 'sites' => array_values($this->registry())];
    }

    /** Read-only remote call; the transport must sign and authenticate requests. */
    public function diagnostics(string $siteUrl): array
    {
        $registry = $this->registry();
        if (!isset($registry[$siteUrl])) {
            throw new InvalidArgumentException('Target is not in the paired-site registry');
        }
        // Transport must use stored per-site credentials and pin the paired origin.
        // Never follow redirects or accept an arbitrary user-supplied URL.
        $data = ($this->authenticatedSiteRequest)($siteUrl, 'GET', '/wp-json/odladeni-bridge/v1/diagnostics');
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            throw new RuntimeException('Authenticated remote diagnostics failed');
        }
        return [
            'site' => $siteUrl,
            'wordpress_version' => $data['wordpress_version'] ?? null,
            'php_version' => $data['php_version'] ?? null,
            'plugins' => $data['plugins'] ?? [],
            'capabilities' => $data['capabilities'] ?? [],
            'checked_at' => gmdate('c'),
        ];
    }

    /** Independent failures do not abort the remaining sites. */
    public function fleetDiagnostics(): array
    {
        $results = [];
        foreach (array_keys($this->registry()) as $url) {
            try {
                $results[] = ['site' => $url, 'ok' => true, 'diagnostics' => $this->diagnostics($url)];
            } catch (Throwable $e) {
                $results[] = ['site' => $url, 'ok' => false, 'error' => 'Remote diagnostics unavailable'];
            }
        }
        return ['count' => count($results), 'results' => $results];
    }
}
