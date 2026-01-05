<?php declare(strict_types=1);

namespace Base3IliasTools\AiProxy;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;

final class AiVectorDbProxy implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IConfiguration $configuration
	) {}

	public static function getName(): string {
		return 'aivectordbproxy';
	}

	public function getHelp() {
		return "REST proxy for Vector DB (Qdrant gateway).\n"
			. "Call via: base3.php?name=aivectordbproxy&path=/collections/...\n"
			. "Auth header: X-Proxy-Token\n";
	}

	public function getOutput($out = 'html') {
		$vectorCfg   = (array)($this->configuration->get('vectordb') ?? []);
		$proxyCfg    = (array)($this->configuration->get('aiproxy') ?? []);

		$upstreamBase = rtrim((string)($vectorCfg['endpoint'] ?? ''), '/'); // e.g. https://ht152.qualitus.net
		$upstreamToken = (string)($vectorCfg['apikey'] ?? '');
		$proxyToken = (string)($proxyCfg['apikey'] ?? '');

		if ($upstreamBase === '' || $upstreamToken === '' || $proxyToken === '') {
			http_response_code(500);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode(['error' => 'Proxy misconfigured.'], JSON_UNESCAPED_UNICODE);
		}

		// Client -> Proxy auth
		$clientToken = (string)$this->request->server('HTTP_X_PROXY_TOKEN', '');
		if ($clientToken !== $proxyToken) {
			http_response_code(401);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode(['error' => 'Unauthorized. Invalid proxy token.'], JSON_UNESCAPED_UNICODE);
		}

		$method = strtoupper((string)$this->request->server('REQUEST_METHOD', 'GET'));
		$allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
		if (!in_array($method, $allowedMethods, true)) {
			http_response_code(405);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode(['error' => 'Method Not Allowed.'], JSON_UNESCAPED_UNICODE);
		}

		// IMPORTANT: in this routing mode, we forward via ?path=
		$forwardPath = (string)$this->request->get('path', '/');
		if ($forwardPath === '') {
			$forwardPath = '/';
		}
		if ($forwardPath[0] !== '/') {
			$forwardPath = '/' . $forwardPath;
		}

		// Safety
		if (str_contains($forwardPath, '..')) {
			http_response_code(400);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode(['error' => 'Invalid path.'], JSON_UNESCAPED_UNICODE);
		}

		// Optional restriction: only allow VectorDB/Qdrant endpoints
		$allowedPrefixes = ['/collections', '/health'];
		$okPrefix = false;
		foreach ($allowedPrefixes as $p) {
			if (str_starts_with($forwardPath, $p)) {
				$okPrefix = true;
				break;
			}
		}
		if (!$okPrefix) {
			http_response_code(403);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode(['error' => 'Forbidden path.', 'path' => $forwardPath], JSON_UNESCAPED_UNICODE);
		}

		$upstreamUrl = $upstreamBase . $forwardPath;

		$body = $this->getRawBody();
		$contentType = (string)$this->request->server('CONTENT_TYPE', '');
		$accept = (string)$this->request->server('HTTP_ACCEPT', '');

		$headers = [
			'Authorization: Bearer ' . $upstreamToken,
			'Accept: ' . ($accept !== '' ? $accept : 'application/json'),
		];

		if ($contentType !== '') {
			$headers[] = 'Content-Type: ' . $contentType;
		} else {
			$headers[] = 'Content-Type: application/json';
		}

		$responseHeaders = [];
		$ch = curl_init($upstreamUrl);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_POSTFIELDS     => ($method === 'GET' ? null : $body),
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders) {
				$len = strlen($line);
				$line = trim($line);
				if ($line === '' || !str_contains($line, ':')) {
					return $len;
				}
				[$k, $v] = explode(':', $line, 2);
				$responseHeaders[strtolower(trim($k))] = trim($v);
				return $len;
			},
		]);

		$responseBody = curl_exec($ch);
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($responseBody === false) {
			$err = curl_error($ch);
			curl_close($ch);
			http_response_code(502);
			header('Content-Type: application/json; charset=utf-8');
			return json_encode(['error' => 'Upstream request failed', 'details' => $err], JSON_UNESCAPED_UNICODE);
		}

		curl_close($ch);

		http_response_code($httpCode);

		$respCt = $responseHeaders['content-type'] ?? 'application/json; charset=utf-8';
		header('Content-Type: ' . $respCt);

		return (string)$responseBody;
	}

	private function getRawBody(): string {
		$raw = file_get_contents('php://input');
		return ($raw === false) ? '' : $raw;
	}
}
