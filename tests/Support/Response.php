<?php
namespace Vireo\Tests\Support;

final class Response
{
    public function __construct(public int $status, public string $headers, public string $body) {}

    public function json(): array
    {
        $d = json_decode($this->body, true);
        if (!is_array($d)) throw new \RuntimeException("Keine JSON-Antwort (HTTP {$this->status}): " . mb_substr($this->body, 0, 500));
        return $d;
    }

    public function header(string $name): ?string
    {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/mi', $this->headers, $m) ? trim($m[1]) : null;
    }
}
