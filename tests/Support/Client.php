<?php
namespace Planquadro\Tests\Support;

/**
 * Minimaler HTTP-Client mit eigener Cookie-Session (ein Objekt = ein Browser).
 * Setzt das CSRF-Token automatisch als Header X-CSRF-Token, sobald es aus
 * einer Seite (meta csrf-token / verstecktes Feld _token) gelesen wurde.
 */
final class Client
{
    private string $jar;
    public ?string $csrf = null;
    public bool $sendCsrf = true;
    /** Basis-URL (Standard: Haupt-Testserver) und optionaler Host-Header */
    public ?string $base = null;
    public ?string $host = null;

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'pqc');
    }

    public function __destruct() { @unlink($this->jar); }

    public static function loggedIn(string $user, string $password = TestEnv::PASSWORD): self
    {
        $c = new self();
        $r = $c->login($user, $password);
        if (strpos($r->body, 'name="username"') !== false && strpos($r->body, 'Willkommen') !== false && $r->status === 200) {
            throw new \RuntimeException("Login für $user fehlgeschlagen");
        }
        $c->get('/dashboard'); // CSRF-Token aus der Seite übernehmen
        return $c;
    }

    public function login(string $user, string $password): Response
    {
        $this->get('/');
        $fields = ['username' => $user, 'password' => $password];
        if ($this->csrf) $fields['_token'] = $this->csrf;
        return $this->request('POST', '/', http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
    }

    public function get(string $path, array $headers = []): Response
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** JSON-POST an eine API (wie fetch() in app.js) */
    public function postJson(string $path, array $data, array $headers = []): Response
    {
        return $this->request('POST', $path, json_encode($data), array_merge(['Content-Type: application/json'], $headers));
    }

    /** Formular-POST an einen Seiten-AJAX-Handler (wie ajaxPost() in app.js) */
    public function postAjax(string $path, array $fields, array $headers = []): Response
    {
        return $this->request('POST', $path, http_build_query($fields), array_merge([
            'Content-Type: application/x-www-form-urlencoded', 'X-Requested-With: XMLHttpRequest'], $headers));
    }

    public function request(string $method, string $path, ?string $body, array $headers = []): Response
    {
        if ($this->sendCsrf && $this->csrf && $method !== 'GET') $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        if ($this->host) $headers[] = 'Host: ' . $this->host;
        $ch = curl_init(($this->base ?? TestEnv::baseUrl()) . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_NOPROXY => '*',
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) throw new \RuntimeException('HTTP-Fehler: ' . curl_error($ch));
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $resp = new Response($status, substr($raw, 0, $hsize), substr($raw, $hsize));
        if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $resp->body, $m)
            || preg_match('/name="_token" value="([a-f0-9]+)"/', $resp->body, $m)) {
            $this->csrf = $m[1];
        }
        return $resp;
    }
}
