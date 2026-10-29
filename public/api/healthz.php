<?php
/**
 * Liveness: Der PHP-Prozess antwortet. Keine Datenbank, keine Session, keine
 * Details – geeignet für Docker-/Kubernetes-Healthchecks und Load Balancer.
 * GET /api/healthz → 200 {"status":"ok"}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo '{"status":"ok"}';
